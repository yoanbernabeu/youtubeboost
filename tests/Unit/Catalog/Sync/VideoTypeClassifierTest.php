<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Sync;

use App\Catalog\Entity\ThumbnailSet;
use App\Catalog\Entity\TypeSource;
use App\Catalog\Entity\VideoType;
use App\Catalog\Entity\Visibility;
use App\Catalog\Sync\TypeGuess;
use App\Catalog\Sync\VideoTypeClassifier;
use App\YouTube\Api\Dto\ContentTypeBreakdown;
use App\YouTube\Api\Dto\VideoSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(VideoTypeClassifier::class)]
#[CoversClass(TypeGuess::class)]
final class VideoTypeClassifierTest extends TestCase
{
    public function testALiveBroadcastIsRecognisedStraightAway(): void
    {
        $guess = new VideoTypeClassifier()->fromSnapshot(self::snapshot(durationSeconds: 7200, isLive: true));

        self::assertSame(VideoType::Live, $guess->type);
        self::assertSame(TypeSource::Heuristic, $guess->source);
    }

    #[DataProvider('shortCandidates')]
    public function testAShortVideoIsGuessedAsAShort(int $durationSeconds, string $title, string $description): void
    {
        $guess = new VideoTypeClassifier()->fromSnapshot(self::snapshot(
            durationSeconds: $durationSeconds,
            title: $title,
            description: $description,
        ));

        self::assertSame(VideoType::Short, $guess->type);
    }

    /**
     * @return iterable<string, array{int, string, string}>
     */
    public static function shortCandidates(): iterable
    {
        yield 'under three minutes' => [120, 'Une astuce rapide', ''];
        yield 'exactly three minutes' => [180, 'Une astuce rapide', ''];
        yield 'very short' => [18, 'Une astuce', ''];
    }

    #[DataProvider('standardCandidates')]
    public function testALongerVideoIsGuessedAsStandard(int $durationSeconds, string $title): void
    {
        $guess = new VideoTypeClassifier()->fromSnapshot(self::snapshot(durationSeconds: $durationSeconds, title: $title));

        self::assertSame(VideoType::Standard, $guess->type);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function standardCandidates(): iterable
    {
        yield 'just over three minutes' => [181, 'Un tutoriel'];
        yield 'a long video' => [1800, 'Un tutoriel'];
        // The #shorts tag is not a signal: creators leave it in long-form copy.
        yield 'tagged but long' => [900, 'Un tutoriel #shorts'];
        yield 'unknown duration' => [0, 'Un tutoriel'];
    }

    public function testTheAnalyticsBreakdownIsAuthoritative(): void
    {
        $classifier = new VideoTypeClassifier();

        $short = $classifier->fromBreakdown(new ContentTypeBreakdown([ContentTypeBreakdown::SHORTS => 42000]));
        self::assertNotNull($short);
        self::assertSame(VideoType::Short, $short->type);
        self::assertSame(TypeSource::Analytics, $short->source);

        $live = $classifier->fromBreakdown(new ContentTypeBreakdown([ContentTypeBreakdown::LIVE_STREAM => 10]));
        self::assertSame(VideoType::Live, $live?->type);

        $standard = $classifier->fromBreakdown(new ContentTypeBreakdown([ContentTypeBreakdown::VIDEO_ON_DEMAND => 10]));
        self::assertSame(VideoType::Standard, $standard?->type);
    }

    public function testTheBreakdownFollowsTheDominantContentType(): void
    {
        $guess = new VideoTypeClassifier()->fromBreakdown(new ContentTypeBreakdown([
            ContentTypeBreakdown::SHORTS => 5,
            ContentTypeBreakdown::VIDEO_ON_DEMAND => 5000,
        ]));

        self::assertSame(VideoType::Standard, $guess?->type);
    }

    public function testAnUnwatchedVideoGetsNoAnalyticsAnswer(): void
    {
        self::assertNull(new VideoTypeClassifier()->fromBreakdown(ContentTypeBreakdown::empty()));
    }

    public function testAnUnknownContentTypeGetsNoAnswer(): void
    {
        self::assertNull(new VideoTypeClassifier()->fromBreakdown(new ContentTypeBreakdown(['STORY' => 10])));
    }

    private static function snapshot(
        int $durationSeconds,
        string $title = 'Titre',
        string $description = '',
        bool $isLive = false,
    ): VideoSnapshot {
        return new VideoSnapshot(
            'dQw4w9WgXcQ',
            $title,
            $description,
            new \DateTimeImmutable('2026-01-01'),
            $durationSeconds,
            Visibility::Public,
            ThumbnailSet::fromApiPayload([]),
            1000,
            10,
            1,
            $isLive,
            false,
            null,
        );
    }
}
