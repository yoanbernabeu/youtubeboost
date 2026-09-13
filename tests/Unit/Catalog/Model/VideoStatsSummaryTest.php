<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Model;

use App\Catalog\Entity\ThumbnailSet;
use App\Catalog\Entity\Video;
use App\Catalog\Entity\Visibility;
use App\Catalog\Model\DailyPoint;
use App\Catalog\Model\DailyStatSeries;
use App\Catalog\Model\VideoStatsSummarizer;
use App\Catalog\Model\VideoStatsSummary;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VideoStatsSummary::class)]
#[CoversClass(VideoStatsSummarizer::class)]
final class VideoStatsSummaryTest extends TestCase
{
    public function testItAggregatesTheWindowAndTheLifetime(): void
    {
        $summary = new VideoStatsSummarizer()->summarize(
            self::video(),
            self::series(),
            new \DateTimeImmutable('2026-06-30'),
            28,
            30,
        );

        self::assertSame(28, $summary->windowDays);
        self::assertSame(120000, $summary->lifetimeViews);
        self::assertSame(50.0, $summary->viewsPerDay);
        self::assertSame(200.0, $summary->bestViewsPerDay);
        self::assertSame(28 * 800, $summary->impressions);
        self::assertSame(3.5, $summary->clickThroughRate);
        self::assertSame(42.0, $summary->averageViewPercentage);
        self::assertSame('2026-06-30', $summary->lastDataDay?->format('Y-m-d'));
    }

    public function testTheRetainedShareComparesTheWindowToThePeak(): void
    {
        $summary = new VideoStatsSummarizer()->summarize(
            self::video(),
            self::series(),
            new \DateTimeImmutable('2026-06-30'),
            28,
            30,
        );

        self::assertSame(25.0, $summary->retainedShare());
    }

    public function testTheRetainedShareIsUnknownWithoutAPeak(): void
    {
        $summary = new VideoStatsSummary(28, 10, 5.0, null, null, null, null, null, null);

        self::assertNull($summary->retainedShare());
    }

    public function testThePromptSummaryListsEveryAvailableFigure(): void
    {
        $summary = new VideoStatsSummary(28, 120000, 50.0, 200.0, 22400, 3.5, 42.0, 180.0, new \DateTimeImmutable('2026-06-30'));

        $text = $summary->toPromptSummary();

        self::assertStringContainsString('120,000 lifetime views', $text);
        self::assertStringContainsString('50.0 views/day over the last 28 days', $text);
        self::assertStringContainsString('best period at 200.0 views/day', $text);
        self::assertStringContainsString('22,400 thumbnail impressions', $text);
        self::assertStringContainsString('3.50% click-through rate', $text);
        self::assertStringContainsString('42.0% of the video watched', $text);
    }

    public function testThePromptSummaryOmitsWhatIsMissing(): void
    {
        $summary = new VideoStatsSummary(28, 500, null, null, null, null, null, null, null);

        self::assertSame('500 lifetime views.', $summary->toPromptSummary());
    }

    /**
     * The figures handed to the model stay English whatever the interface says,
     * because the instructions around them are English.
     */
    public function testThePromptSummaryIgnoresTheInterfaceLanguage(): void
    {
        $previous = \Locale::getDefault();
        \Locale::setDefault('fr_FR');

        try {
            $summary = new VideoStatsSummary(28, 120000, 50.0, null, null, null, null, null, null);

            self::assertStringContainsString('120,000 lifetime views', $summary->toPromptSummary());
        } finally {
            \Locale::setDefault($previous);
        }
    }

    private static function video(): Video
    {
        $video = new Video(
            'dQw4w9WgXcQ',
            'Titre',
            'Description',
            new \DateTimeImmutable('2026-01-01'),
            933,
            Visibility::Public,
            ThumbnailSet::fromApiPayload([]),
        );
        $video->updateStatistics(120000, 0, 0);

        return $video;
    }

    private static function series(): DailyStatSeries
    {
        $points = [];
        $day = new \DateTimeImmutable('2026-01-01');
        // 200 views/day for five months, then 50 views/day over the recent window.
        for ($i = 0; $i < 181; ++$i) {
            $isRecent = $day >= new \DateTimeImmutable('2026-06-03');
            $points[] = new DailyPoint($day, $isRecent ? 50 : 200, 800, 3.5, 42.0, 180, 0);
            $day = $day->modify('+1 day');
        }

        return DailyStatSeries::fromPoints($points);
    }
}
