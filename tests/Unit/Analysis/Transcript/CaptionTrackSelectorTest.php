<?php

declare(strict_types=1);

namespace App\Tests\Unit\Analysis\Transcript;

use App\Analysis\Transcript\CaptionTrackSelector;
use App\YouTube\Api\Dto\CaptionTrack;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CaptionTrackSelector::class)]
final class CaptionTrackSelectorTest extends TestCase
{
    public function testAManualTrackInTheChannelLanguageComesFirst(): void
    {
        $ranked = new CaptionTrackSelector()->rank([
            self::track('asr-fr', 'fr', CaptionTrack::KIND_ASR),
            self::track('manual-en', 'en', CaptionTrack::KIND_STANDARD),
            self::track('manual-fr', 'fr-FR', CaptionTrack::KIND_STANDARD),
            self::track('asr-en', 'en', CaptionTrack::KIND_ASR),
        ], 'fr');

        self::assertSame(['manual-fr', 'manual-en', 'asr-fr', 'asr-en'], array_map(static fn (CaptionTrack $t): string => $t->id, $ranked));
    }

    public function testDraftTracksAreExcluded(): void
    {
        $ranked = new CaptionTrackSelector()->rank([
            self::track('draft', 'fr', CaptionTrack::KIND_STANDARD, isDraft: true),
            self::track('ready', 'fr', CaptionTrack::KIND_STANDARD),
        ], 'fr');

        self::assertSame(['ready'], array_map(static fn (CaptionTrack $t): string => $t->id, $ranked));
    }

    public function testNoTrackAtAllGivesNoCandidate(): void
    {
        self::assertSame([], new CaptionTrackSelector()->rank([], 'fr'));
    }

    public function testRegionalVariantsCountAsTheSameLanguage(): void
    {
        $ranked = new CaptionTrackSelector()->rank([
            self::track('manual-en', 'en', CaptionTrack::KIND_STANDARD),
            self::track('manual-fr-ca', 'fr-CA', CaptionTrack::KIND_STANDARD),
        ], 'fr-FR');

        self::assertSame('manual-fr-ca', $ranked[0]->id);
    }

    private static function track(string $id, string $language, string $kind, bool $isDraft = false): CaptionTrack
    {
        return new CaptionTrack($id, $language, $kind, '', $isDraft, null);
    }
}
