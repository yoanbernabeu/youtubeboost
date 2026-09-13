<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Entity;

use App\Catalog\Entity\ThumbnailSet;
use App\Catalog\Entity\TypeSource;
use App\Catalog\Entity\Video;
use App\Catalog\Entity\VideoType;
use App\Catalog\Entity\Visibility;
use App\Scoring\Model\Score;
use App\Scoring\Model\Signal;
use App\Scoring\Model\SignalSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Video::class)]
#[CoversClass(ThumbnailSet::class)]
final class VideoTest extends TestCase
{
    public function testANewVideoStartsUnscoredAndUnanalysed(): void
    {
        $video = self::video();

        self::assertSame(0, $video->getScore());
        self::assertFalse($video->isScoreReliable());
        self::assertSame(SignalSet::empty()->toArray(), $video->getSignals()->toArray());
        self::assertNull($video->getLastAnalyzedAt());
        self::assertNull($video->getStatsSyncedUntil());
    }

    public function testAgeInDaysIsCountedFromThePublicationDate(): void
    {
        $video = self::video(publishedAt: '2026-01-01');

        self::assertSame(60, $video->ageInDays(new \DateTimeImmutable('2026-03-02')));
    }

    public function testAgeInDaysIsNeverNegative(): void
    {
        $video = self::video(publishedAt: '2026-03-01');

        self::assertSame(0, $video->ageInDays(new \DateTimeImmutable('2026-01-01')));
    }

    public function testApplyingAScoreStoresItsValueAndItsSignals(): void
    {
        $video = self::video();
        $signals = SignalSet::fromArray(['decline' => 0.8, 'low_ctr' => 0.6]);

        $video->applyScore(new Score(70, $signals), new \DateTimeImmutable('2026-03-01 10:00'));

        self::assertSame(70, $video->getScore());
        self::assertSame(0.8, $video->getSignals()->get(Signal::Decline));
        self::assertTrue($video->isScoreReliable());
        self::assertSame('2026-03-01 10:00', $video->getScoredAt()?->format('Y-m-d H:i'));
    }

    public function testAScoreWithoutItsEssentialSignalsIsMarkedUnreliable(): void
    {
        $video = self::video();

        $video->applyScore(new Score(20, SignalSet::fromArray(['potential' => 0.8])), new \DateTimeImmutable());

        self::assertFalse($video->isScoreReliable());
    }

    public function testManualTypeOverrideWinsOverDetection(): void
    {
        $video = self::video();

        $video->forceType(VideoType::Short);
        $video->detectType(VideoType::Standard, TypeSource::Analytics);

        self::assertSame(VideoType::Short, $video->getType());
        self::assertSame(TypeSource::Manual, $video->getTypeSource());
    }

    public function testAnalyticsDetectionWinsOverHeuristicDetection(): void
    {
        $video = self::video();

        $video->detectType(VideoType::Short, TypeSource::Heuristic);
        self::assertSame(VideoType::Short, $video->getType());

        $video->detectType(VideoType::Standard, TypeSource::Analytics);
        self::assertSame(VideoType::Standard, $video->getType());

        // A later heuristic pass must not undo the authoritative answer.
        $video->detectType(VideoType::Short, TypeSource::Heuristic);
        self::assertSame(VideoType::Standard, $video->getType());
    }

    public function testOnlyStandardPublicOrUnlistedVideosAreRelaunchable(): void
    {
        $video = self::video();
        self::assertTrue($video->isRelaunchable());

        $video->forceType(VideoType::Short);
        self::assertFalse($video->isRelaunchable());

        $short = self::video();
        $short->changeVisibility(Visibility::Private);
        self::assertFalse($short->isRelaunchable());

        $unlisted = self::video();
        $unlisted->changeVisibility(Visibility::Unlisted);
        self::assertTrue($unlisted->isRelaunchable());
    }

    public function testThumbnailSetExposesTheLargestAvailableImage(): void
    {
        $set = ThumbnailSet::fromApiPayload([
            'default' => ['url' => 'https://i.ytimg.com/vi/x/default.jpg', 'width' => 120, 'height' => 90],
            'maxres' => ['url' => 'https://i.ytimg.com/vi/x/maxresdefault.jpg', 'width' => 1280, 'height' => 720],
            'high' => ['url' => 'https://i.ytimg.com/vi/x/hqdefault.jpg', 'width' => 480, 'height' => 360],
        ]);

        self::assertSame('https://i.ytimg.com/vi/x/maxresdefault.jpg', $set->best());
        self::assertSame('https://i.ytimg.com/vi/x/hqdefault.jpg', $set->preview());
    }

    public function testThumbnailSetFallsBackWhenTheBiggestSizesAreMissing(): void
    {
        $set = ThumbnailSet::fromApiPayload([
            'default' => ['url' => 'https://i.ytimg.com/vi/x/default.jpg', 'width' => 120, 'height' => 90],
        ]);

        self::assertSame('https://i.ytimg.com/vi/x/default.jpg', $set->best());
        self::assertSame('https://i.ytimg.com/vi/x/default.jpg', $set->preview());
    }

    public function testAnEmptyThumbnailSetHasNoImage(): void
    {
        $set = ThumbnailSet::fromApiPayload([]);

        self::assertNull($set->best());
        self::assertNull($set->preview());
    }

    public function testThumbnailSetIgnoresMalformedEntries(): void
    {
        $set = ThumbnailSet::fromApiPayload([
            'default' => ['width' => 120],
            'high' => ['url' => 'https://i.ytimg.com/vi/x/hqdefault.jpg', 'width' => 480, 'height' => 360],
        ]);

        self::assertSame('https://i.ytimg.com/vi/x/hqdefault.jpg', $set->best());
    }

    public function testThumbnailSetRoundTripsThroughItsStoredForm(): void
    {
        $payload = [
            'high' => ['url' => 'https://i.ytimg.com/vi/x/hqdefault.jpg', 'width' => 480, 'height' => 360],
        ];

        $set = ThumbnailSet::fromApiPayload($payload);

        self::assertSame($payload, $set->toArray());
        self::assertSame($payload, ThumbnailSet::fromApiPayload($set->toArray())->toArray());
    }

    private static function video(string $publishedAt = '2026-01-01'): Video
    {
        return new Video(
            'dQw4w9WgXcQ',
            'Titre de la vidéo',
            'Description',
            new \DateTimeImmutable($publishedAt),
            933,
            Visibility::Public,
            ThumbnailSet::fromApiPayload([]),
        );
    }
}
