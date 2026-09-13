<?php

declare(strict_types=1);

namespace App\Tests\Unit\Analysis\Transcript;

use App\Analysis\Transcript\SubtitleParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SubtitleParser::class)]
final class SubtitleParserTest extends TestCase
{
    private SubtitleParser $parser;

    protected function setUp(): void
    {
        $this->parser = new SubtitleParser();
    }

    public function testItTurnsAnSrtFileIntoPlainText(): void
    {
        $srt = <<<'SRT'
            1
            00:00:01,000 --> 00:00:03,500
            Bonjour à tous et bienvenue

            2
            00:00:03,500 --> 00:00:06,000
            dans ce nouveau tutoriel.
            SRT;

        self::assertSame('Bonjour à tous et bienvenue dans ce nouveau tutoriel.', $this->parser->toPlainText($srt));
    }

    public function testItTurnsAVttFileIntoPlainText(): void
    {
        $vtt = <<<'VTT'
            WEBVTT
            Kind: captions
            Language: fr

            00:00:01.000 --> 00:00:03.500
            Bonjour à tous

            00:00:03.500 --> 00:00:06.000
            et bienvenue.
            VTT;

        self::assertSame('Bonjour à tous et bienvenue.', $this->parser->toPlainText($vtt));
    }

    public function testItStripsCueSettingsAndIdentifiers(): void
    {
        $vtt = <<<'VTT'
            WEBVTT

            intro
            00:00:01.000 --> 00:00:03.500 align:start position:0%
            Première phrase.
            VTT;

        self::assertSame('Première phrase.', $this->parser->toPlainText($vtt));
    }

    public function testItStripsInlineMarkupAndKaraokeTimestamps(): void
    {
        $vtt = <<<'VTT'
            WEBVTT

            00:00:01.000 --> 00:00:03.500
            <c.colorE5E5E5><00:00:01.240>Bonjour</c> <i>à tous</i>
            VTT;

        self::assertSame('Bonjour à tous', $this->parser->toPlainText($vtt));
    }

    public function testItDropsNoteBlocks(): void
    {
        $vtt = <<<'VTT'
            WEBVTT

            NOTE
            Ce bloc est un commentaire
            sur plusieurs lignes.

            00:00:01.000 --> 00:00:03.500
            Le vrai texte.
            VTT;

        self::assertSame('Le vrai texte.', $this->parser->toPlainText($vtt));
    }

    public function testItRemovesTheRollingRepetitionOfAutomaticCaptions(): void
    {
        $vtt = <<<'VTT'
            WEBVTT

            00:00:01.000 --> 00:00:03.000
            alors aujourd'hui on va parler

            00:00:03.000 --> 00:00:05.000
            alors aujourd'hui on va parler
            de miniatures YouTube

            00:00:05.000 --> 00:00:07.000
            de miniatures YouTube
            et de leur impact
            VTT;

        self::assertSame(
            "alors aujourd'hui on va parler de miniatures YouTube et de leur impact",
            $this->parser->toPlainText($vtt),
        );
    }

    public function testItDecodesHtmlEntities(): void
    {
        $srt = "1\n00:00:01,000 --> 00:00:02,000\nJ&#39;aime les &amp; esperluettes\n";

        self::assertSame("J'aime les & esperluettes", $this->parser->toPlainText($srt));
    }

    public function testItCollapsesWhitespace(): void
    {
        $srt = "1\n00:00:01,000 --> 00:00:02,000\n   Trop     d'espaces   \n";

        self::assertSame("Trop d'espaces", $this->parser->toPlainText($srt));
    }

    public function testAnEmptyOrTimestampOnlyFileGivesAnEmptyString(): void
    {
        self::assertSame('', $this->parser->toPlainText(''));
        self::assertSame('', $this->parser->toPlainText("WEBVTT\n\n00:00:01.000 --> 00:00:02.000\n\n"));
    }

    public function testItHandlesWindowsLineEndings(): void
    {
        $srt = "1\r\n00:00:01,000 --> 00:00:02,000\r\nUne ligne\r\n\r\n2\r\n00:00:02,000 --> 00:00:03,000\r\nUne autre\r\n";

        self::assertSame('Une ligne Une autre', $this->parser->toPlainText($srt));
    }

    public function testItSupportsShortTimestamps(): void
    {
        $vtt = "WEBVTT\n\n00:01.000 --> 00:03.000\nTexte court.\n";

        self::assertSame('Texte court.', $this->parser->toPlainText($vtt));
    }

    public function testItTruncatesOnAWordBoundary(): void
    {
        $srt = "1\n00:00:01,000 --> 00:00:02,000\nBonjour à tous les créateurs de contenu\n";

        self::assertSame('Bonjour à tous…', $this->parser->toPlainText($srt, 16));
    }

    /**
     * The conclusion is where the call to action lives, so a transcript that
     * overruns its budget loses its middle rather than its ending.
     */
    public function testAnOverlongTranscriptKeepsItsEnding(): void
    {
        $text = 'Ouverture de la vidéo. ' . str_repeat('remplissage ', 2000) . 'Et voilà la conclusion.';
        $srt = "1\n00:00:01,000 --> 00:00:02,000\n" . $text . "\n";

        $plain = $this->parser->toPlainText($srt, 4000);

        self::assertStringStartsWith('Ouverture de la vidéo.', $plain);
        self::assertStringEndsWith('Et voilà la conclusion.', $plain);
        self::assertStringContainsString(SubtitleParser::ELISION, $plain);
        self::assertLessThanOrEqual(4000, mb_strlen($plain));
    }

    public function testATinyBudgetKeepsOnlyTheBeginning(): void
    {
        $srt = "1\n00:00:01,000 --> 00:00:02,000\n" . str_repeat('mot ', 200) . "fin\n";

        $plain = $this->parser->toPlainText($srt, 40);

        self::assertStringStartsWith('mot mot', $plain);
        self::assertStringEndsWith('…', $plain);
        self::assertStringNotContainsString(SubtitleParser::ELISION, $plain);
    }

    public function testItDoesNotTruncateShortTranscripts(): void
    {
        $srt = "1\n00:00:01,000 --> 00:00:02,000\nCourt\n";

        self::assertSame('Court', $this->parser->toPlainText($srt, 1000));
    }
}
