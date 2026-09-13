<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scoring\Calculator;

use App\Catalog\Entity\ThumbnailSet;
use App\Catalog\Entity\Video;
use App\Catalog\Entity\Visibility;
use App\Catalog\Model\DailyPoint;
use App\Catalog\Model\DailyStatSeries;
use App\Scoring\Calculator\DeclineSignalCalculator;
use App\Scoring\Calculator\ImpressionsSignalCalculator;
use App\Scoring\Calculator\LowCtrSignalCalculator;
use App\Scoring\Calculator\PotentialSignalCalculator;
use App\Scoring\Calculator\SignalCalculatorInterface;
use App\Scoring\Model\ChannelBaseline;
use App\Scoring\Model\ScoringContext;
use App\Scoring\Model\ScoringParameters;
use App\Scoring\Model\Signal;
use App\Scoring\Model\VideoMetrics;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeclineSignalCalculator::class)]
#[CoversClass(LowCtrSignalCalculator::class)]
#[CoversClass(ImpressionsSignalCalculator::class)]
#[CoversClass(PotentialSignalCalculator::class)]
#[CoversClass(ScoringContext::class)]
#[CoversClass(ScoringParameters::class)]
#[CoversClass(ChannelBaseline::class)]
#[CoversClass(VideoMetrics::class)]
final class SignalCalculatorTest extends TestCase
{
    private const string REFERENCE_DATE = '2026-06-30';

    public function testEveryCalculatorAnswersForExactlyOneSignal(): void
    {
        $signals = array_map(
            static fn (SignalCalculatorInterface $c): Signal => $c->signal(),
            [new DeclineSignalCalculator(), new LowCtrSignalCalculator(), new ImpressionsSignalCalculator(), new PotentialSignalCalculator()],
        );

        self::assertSame([Signal::Decline, Signal::LowCtr, Signal::Impressions, Signal::Potential], $signals);
    }

    public function testDeclineIsZeroWhenTheVideoIsAtItsPeak(): void
    {
        // A flat 100 views/day: the current window equals the best window.
        $metrics = self::metrics(self::flatSeries('2026-01-01', 181, 100));

        self::assertSame(0.0, new DeclineSignalCalculator()->calculate($metrics, self::context()));
    }

    public function testDeclineIsNearlyOneWhenTheVideoIsAllButDead(): void
    {
        $points = self::flatPoints('2026-01-01', 120, 100);
        $points = [...$points, ...self::flatPoints('2026-05-01', 61, 1)];

        $signal = new DeclineSignalCalculator()->calculate(self::metrics(DailyStatSeries::fromPoints($points)), self::context());

        self::assertNotNull($signal);
        self::assertGreaterThan(0.95, $signal);
    }

    public function testDeclineIsHalfWhenTheVideoLostHalfItsAudience(): void
    {
        $points = [...self::flatPoints('2026-01-01', 120, 100), ...self::flatPoints('2026-05-01', 61, 50)];

        $signal = new DeclineSignalCalculator()->calculate(self::metrics(DailyStatSeries::fromPoints($points)), self::context());

        self::assertSame(0.5, $signal);
    }

    public function testDeclineIgnoresTheWarmupPeriodWhenLookingForThePeak(): void
    {
        // A launch spike of 1000 views/day for the first 30 days must not become the yardstick.
        $points = [...self::flatPoints('2026-01-01', 30, 1000), ...self::flatPoints('2026-01-31', 151, 100)];

        self::assertSame(0.0, new DeclineSignalCalculator()->calculate(self::metrics(DailyStatSeries::fromPoints($points)), self::context()));
    }

    public function testDeclineIsUnknownWithoutEnoughHistory(): void
    {
        $metrics = self::metrics(self::flatSeries('2026-06-01', 30, 100), publishedAt: '2026-06-01');

        self::assertNull(new DeclineSignalCalculator()->calculate($metrics, self::context()));
    }

    public function testDeclineIsUnknownWhenTheSeriesIsEmpty(): void
    {
        self::assertNull(new DeclineSignalCalculator()->calculate(self::metrics(DailyStatSeries::fromPoints([])), self::context()));
    }

    public function testLowCtrIsZeroAboveTheChannelMedian(): void
    {
        $metrics = self::metrics(self::ctrSeries(6.0));

        self::assertSame(0.0, new LowCtrSignalCalculator()->calculate($metrics, self::context(medianCtr: 4.0)));
    }

    public function testLowCtrIsOneAtOrBelowHalfTheChannelMedian(): void
    {
        $calculator = new LowCtrSignalCalculator();

        self::assertSame(1.0, $calculator->calculate(self::metrics(self::ctrSeries(2.0)), self::context(medianCtr: 4.0)));
        self::assertSame(1.0, $calculator->calculate(self::metrics(self::ctrSeries(0.5)), self::context(medianCtr: 4.0)));
    }

    public function testLowCtrInterpolatesBetweenTheFloorAndTheMedian(): void
    {
        // 3 % against a 4 % median is a ratio of 0.75, halfway between the 0.5 floor and 1.
        $signal = new LowCtrSignalCalculator()->calculate(self::metrics(self::ctrSeries(3.0)), self::context(medianCtr: 4.0));

        self::assertSame(0.5, $signal);
    }

    public function testLowCtrIsUnknownWithoutChannelOrVideoData(): void
    {
        $calculator = new LowCtrSignalCalculator();

        self::assertNull($calculator->calculate(self::metrics(self::ctrSeries(3.0)), self::context(medianCtr: null)));
        self::assertNull($calculator->calculate(self::metrics(self::flatSeries('2026-01-01', 181, 100)), self::context(medianCtr: 4.0)));
    }

    public function testImpressionsIsCappedAtOne(): void
    {
        $metrics = self::metrics(self::impressionSeries(1000));

        // 28 days x 1000 impressions against a 10 000 median.
        self::assertSame(1.0, new ImpressionsSignalCalculator()->calculate($metrics, self::context(medianImpressions: 10000.0)));
    }

    public function testImpressionsIsTheRatioToTheChannelMedian(): void
    {
        $metrics = self::metrics(self::impressionSeries(100)); // 2 800 over the window

        self::assertSame(0.28, new ImpressionsSignalCalculator()->calculate($metrics, self::context(medianImpressions: 10000.0)));
    }

    public function testImpressionsIsUnknownWithoutData(): void
    {
        $calculator = new ImpressionsSignalCalculator();

        self::assertNull($calculator->calculate(self::metrics(self::impressionSeries(100)), self::context(medianImpressions: null)));
        self::assertNull($calculator->calculate(self::metrics(self::flatSeries('2026-01-01', 181, 100)), self::context(medianImpressions: 1000.0)));
    }

    public function testPotentialCombinesAudienceSizeAndRetention(): void
    {
        $metrics = self::metrics(self::retentionSeries(40.0), viewCount: 100000);
        $context = self::context(medianRetention: 40.0, referenceViews: 100000.0);

        // Views at the channel reference gives 1, retention at the median gives 0.5.
        self::assertSame(0.75, new PotentialSignalCalculator()->calculate($metrics, $context));
    }

    public function testPotentialRewardsRetentionAboveTheChannelMedian(): void
    {
        $metrics = self::metrics(self::retentionSeries(80.0), viewCount: 100000);
        $context = self::context(medianRetention: 40.0, referenceViews: 100000.0);

        self::assertSame(1.0, new PotentialSignalCalculator()->calculate($metrics, $context));
    }

    public function testPotentialUsesWhicheverComponentIsAvailable(): void
    {
        $calculator = new PotentialSignalCalculator();

        $retentionOnly = $calculator->calculate(
            self::metrics(self::retentionSeries(40.0), viewCount: 0),
            self::context(medianRetention: 40.0, referenceViews: null),
        );
        self::assertSame(0.5, $retentionOnly);

        $viewsOnly = $calculator->calculate(
            self::metrics(self::flatSeries('2026-01-01', 181, 100), viewCount: 100000),
            self::context(medianRetention: null, referenceViews: 100000.0),
        );
        self::assertSame(1.0, $viewsOnly);
    }

    public function testPotentialIsUnknownWithoutAnyComponent(): void
    {
        $signal = new PotentialSignalCalculator()->calculate(
            self::metrics(self::flatSeries('2026-01-01', 181, 100), viewCount: 1000),
            self::context(medianRetention: null, referenceViews: null),
        );

        self::assertNull($signal);
    }

    public function testPotentialGrowsWithTheLogarithmOfTheViewCount(): void
    {
        $calculator = new PotentialSignalCalculator();
        $context = self::context(medianRetention: null, referenceViews: 1000000.0);

        $small = $calculator->calculate(self::metrics(self::flatSeries('2026-01-01', 181, 1), viewCount: 1000), $context);
        $large = $calculator->calculate(self::metrics(self::flatSeries('2026-01-01', 181, 1), viewCount: 100000), $context);

        self::assertNotNull($small);
        self::assertNotNull($large);
        self::assertGreaterThan($small, $large);
        self::assertLessThan(1.0, $large);
    }

    private static function context(
        ?float $medianCtr = 4.0,
        ?float $medianImpressions = 10000.0,
        ?float $medianRetention = 40.0,
        ?float $referenceViews = 100000.0,
    ): ScoringContext {
        return new ScoringContext(
            new ChannelBaseline($medianCtr, $medianImpressions, $medianRetention, $referenceViews),
            ScoringParameters::defaults(),
            new \DateTimeImmutable(self::REFERENCE_DATE),
        );
    }

    private static function metrics(DailyStatSeries $series, string $publishedAt = '2026-01-01', int $viewCount = 10000): VideoMetrics
    {
        $video = new Video(
            'dQw4w9WgXcQ',
            'Titre',
            'Description',
            new \DateTimeImmutable($publishedAt),
            600,
            Visibility::Public,
            ThumbnailSet::fromApiPayload([]),
        );
        $video->updateStatistics($viewCount, 0, 0);

        return new VideoMetrics($video, $series);
    }

    /**
     * @return list<DailyPoint>
     */
    private static function flatPoints(string $from, int $days, int $views): array
    {
        $points = [];
        $day = new \DateTimeImmutable($from);
        for ($i = 0; $i < $days; ++$i) {
            $points[] = new DailyPoint($day, $views, null, null, null, null, 0);
            $day = $day->modify('+1 day');
        }

        return $points;
    }

    private static function flatSeries(string $from, int $days, int $views): DailyStatSeries
    {
        return DailyStatSeries::fromPoints(self::flatPoints($from, $days, $views));
    }

    private static function ctrSeries(float $ctr): DailyStatSeries
    {
        $points = [];
        $day = new \DateTimeImmutable('2026-06-03');
        for ($i = 0; $i < 28; ++$i) {
            $points[] = new DailyPoint($day, 100, 1000, $ctr, null, null, 0);
            $day = $day->modify('+1 day');
        }

        return DailyStatSeries::fromPoints($points);
    }

    private static function impressionSeries(int $impressionsPerDay): DailyStatSeries
    {
        $points = [];
        $day = new \DateTimeImmutable('2026-06-03');
        for ($i = 0; $i < 28; ++$i) {
            $points[] = new DailyPoint($day, 100, $impressionsPerDay, 4.0, null, null, 0);
            $day = $day->modify('+1 day');
        }

        return DailyStatSeries::fromPoints($points);
    }

    private static function retentionSeries(float $averageViewPercentage): DailyStatSeries
    {
        $points = [];
        $day = new \DateTimeImmutable('2026-01-01');
        for ($i = 0; $i < 181; ++$i) {
            $points[] = new DailyPoint($day, 100, null, null, $averageViewPercentage, null, 0);
            $day = $day->modify('+1 day');
        }

        return DailyStatSeries::fromPoints($points);
    }
}
