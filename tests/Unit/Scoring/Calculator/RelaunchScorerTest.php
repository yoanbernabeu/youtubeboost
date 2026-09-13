<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scoring\Calculator;

use App\Catalog\Entity\ThumbnailSet;
use App\Catalog\Entity\Video;
use App\Catalog\Entity\VideoType;
use App\Catalog\Entity\Visibility;
use App\Catalog\Model\DailyPoint;
use App\Catalog\Model\DailyStatSeries;
use App\Scoring\Calculator\ChannelBaselineCalculator;
use App\Scoring\Calculator\EligibilityChecker;
use App\Scoring\Calculator\RelaunchScorer;
use App\Scoring\Calculator\ScoreCalculator;
use App\Scoring\Calculator\SignalCalculatorInterface;
use App\Scoring\Calculator\SignalSetCalculator;
use App\Scoring\Model\ChannelBaseline;
use App\Scoring\Model\IneligibilityReason;
use App\Scoring\Model\ScoringContext;
use App\Scoring\Model\ScoringParameters;
use App\Scoring\Model\Signal;
use App\Scoring\Model\VideoMetrics;
use App\Scoring\Model\Weights;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SignalSetCalculator::class)]
#[CoversClass(EligibilityChecker::class)]
#[CoversClass(ChannelBaselineCalculator::class)]
#[CoversClass(RelaunchScorer::class)]
final class RelaunchScorerTest extends TestCase
{
    private const string REFERENCE_DATE = '2026-06-30';

    public function testTheSignalSetGathersEveryCalculatorAnswer(): void
    {
        $calculator = new SignalSetCalculator([
            self::fixedCalculator(Signal::Decline, 0.9),
            self::fixedCalculator(Signal::LowCtr, null),
        ]);

        $set = $calculator->calculate(self::metrics(), self::context());

        self::assertSame(0.9, $set->get(Signal::Decline));
        self::assertNull($set->get(Signal::LowCtr));
        self::assertNull($set->get(Signal::Potential));
    }

    public function testAVideoOldEnoughAndNeverRelaunchedIsEligible(): void
    {
        $eligibility = new EligibilityChecker()->check(self::video(), self::context(), null);

        self::assertTrue($eligibility->isEligible);
        self::assertNull($eligibility->reason);
    }

    public function testATooRecentVideoIsNotEligible(): void
    {
        $video = self::video(publishedAt: '2026-06-01');

        $eligibility = new EligibilityChecker()->check($video, self::context(), null);

        self::assertFalse($eligibility->isEligible);
        self::assertSame(IneligibilityReason::TooRecent, $eligibility->reason);
    }

    public function testAShortIsNotEligible(): void
    {
        $video = self::video();
        $video->forceType(VideoType::Short);

        self::assertSame(
            IneligibilityReason::NotAStandardVideo,
            new EligibilityChecker()->check($video, self::context(), null)->reason,
        );
    }

    public function testAPrivateVideoIsNotEligible(): void
    {
        $video = self::video();
        $video->changeVisibility(Visibility::Private);

        self::assertSame(
            IneligibilityReason::NotVisible,
            new EligibilityChecker()->check($video, self::context(), null)->reason,
        );
    }

    public function testAVideoUnderTrackingIsNotEligible(): void
    {
        $eligibility = new EligibilityChecker()->check(
            self::video(),
            self::context(),
            new \DateTimeImmutable('2026-06-20'),
        );

        self::assertSame(IneligibilityReason::RelaunchInProgress, $eligibility->reason);
    }

    public function testAVideoBecomesEligibleAgainOnceTheCooldownIsOver(): void
    {
        $eligibility = new EligibilityChecker()->check(
            self::video(),
            self::context(),
            new \DateTimeImmutable('2026-05-01'),
        );

        self::assertTrue($eligibility->isEligible);
    }

    public function testTheBaselineTakesTheMedianOfTheChannel(): void
    {
        $metrics = [
            self::metricsWith(ctr: 2.0, impressions: 100, retention: 20.0, viewCount: 1000),
            self::metricsWith(ctr: 4.0, impressions: 200, retention: 40.0, viewCount: 10000),
            self::metricsWith(ctr: 9.0, impressions: 900, retention: 90.0, viewCount: 100000),
        ];

        $baseline = new ChannelBaselineCalculator()->calculate($metrics, ScoringParameters::defaults(), new \DateTimeImmutable(self::REFERENCE_DATE));

        self::assertSame(4.0, $baseline->medianClickThroughRate);
        self::assertSame(200.0 * 28, $baseline->medianImpressions);
        self::assertSame(40.0, $baseline->medianAverageViewPercentage);
        self::assertNotNull($baseline->referenceViewCount);
        self::assertGreaterThan(10000.0, $baseline->referenceViewCount);
    }

    public function testTheBaselineIgnoresShortsAndPrivateVideos(): void
    {
        $short = self::metricsWith(ctr: 50.0, impressions: 1, retention: 99.0, viewCount: 1);
        $short->video->forceType(VideoType::Short);

        $metrics = [$short, self::metricsWith(ctr: 4.0, impressions: 200, retention: 40.0, viewCount: 10000)];

        $baseline = new ChannelBaselineCalculator()->calculate($metrics, ScoringParameters::defaults(), new \DateTimeImmutable(self::REFERENCE_DATE));

        self::assertSame(4.0, $baseline->medianClickThroughRate);
    }

    public function testAnEmptyChannelHasAnUnknownBaseline(): void
    {
        $baseline = new ChannelBaselineCalculator()->calculate([], ScoringParameters::defaults(), new \DateTimeImmutable(self::REFERENCE_DATE));

        self::assertNull($baseline->medianClickThroughRate);
        self::assertNull($baseline->medianImpressions);
        self::assertNull($baseline->medianAverageViewPercentage);
        self::assertNull($baseline->referenceViewCount);
    }

    public function testScoringAnIneligibleVideoYieldsZeroAndTheReason(): void
    {
        $scorer = self::scorer([self::fixedCalculator(Signal::Decline, 1.0)]);
        $video = self::video();
        $video->forceType(VideoType::Short);

        $result = $scorer->score(new VideoMetrics($video, self::series()), self::context(), null, Weights::defaults());

        self::assertSame(0, $result->score->value);
        self::assertFalse($result->eligibility->isEligible);
        self::assertSame(IneligibilityReason::NotAStandardVideo, $result->eligibility->reason);
    }

    public function testScoringAnEligibleVideoRunsTheCalculators(): void
    {
        $scorer = self::scorer([
            self::fixedCalculator(Signal::Decline, 1.0),
            self::fixedCalculator(Signal::LowCtr, 1.0),
        ]);

        $result = $scorer->score(self::metrics(), self::context(), null, Weights::defaults());

        self::assertSame(100, $result->score->value);
        self::assertTrue($result->eligibility->isEligible);
        self::assertTrue($result->score->isReliable());
    }

    /**
     * @param list<SignalCalculatorInterface> $calculators
     */
    private static function scorer(array $calculators): RelaunchScorer
    {
        return new RelaunchScorer(new SignalSetCalculator($calculators), new ScoreCalculator(), new EligibilityChecker());
    }

    private static function fixedCalculator(Signal $signal, ?float $value): SignalCalculatorInterface
    {
        return new class($signal, $value) implements SignalCalculatorInterface {
            public function __construct(private readonly Signal $signal, private readonly ?float $value)
            {
            }

            public function signal(): Signal
            {
                return $this->signal;
            }

            public function calculate(VideoMetrics $metrics, ScoringContext $context): ?float
            {
                return $this->value;
            }
        };
    }

    private static function context(): ScoringContext
    {
        return new ScoringContext(
            ChannelBaseline::unknown(),
            ScoringParameters::defaults(),
            new \DateTimeImmutable(self::REFERENCE_DATE),
        );
    }

    private static function video(string $publishedAt = '2026-01-01'): Video
    {
        return new Video(
            'dQw4w9WgXcQ',
            'Titre',
            'Description',
            new \DateTimeImmutable($publishedAt),
            600,
            Visibility::Public,
            ThumbnailSet::fromApiPayload([]),
        );
    }

    private static function metrics(): VideoMetrics
    {
        return new VideoMetrics(self::video(), self::series());
    }

    private static function metricsWith(float $ctr, int $impressions, float $retention, int $viewCount): VideoMetrics
    {
        $video = self::video();
        $video->updateStatistics($viewCount, 0, 0);

        $points = [];
        $day = new \DateTimeImmutable('2026-06-03');
        for ($i = 0; $i < 28; ++$i) {
            $points[] = new DailyPoint($day, 100, $impressions, $ctr, $retention, null, 0);
            $day = $day->modify('+1 day');
        }

        return new VideoMetrics($video, DailyStatSeries::fromPoints($points));
    }

    private static function series(): DailyStatSeries
    {
        return DailyStatSeries::fromPoints([]);
    }
}
