<?php

declare(strict_types=1);

namespace App\Analysis\Transcript;

/**
 * Turns an SRT or VTT caption track into the plain text a language model can read.
 *
 * Automatic captions are the tricky part: YouTube emits them as a rolling window
 * where each cue repeats the tail of the previous one, so naive concatenation
 * doubles the whole transcript.
 */
final class SubtitleParser
{
    /** Marks the gap left in a transcript too long for its budget. */
    public const string ELISION = ' [...transcript shortened here...] ';

    /** Two thirds of the budget go to the opening, one third to the conclusion. */
    private const int HEAD_SHARE = 2;
    private const int SHARES = 3;

    /** Below this, no tail would be long enough to be worth keeping. */
    private const int MIN_LENGTH_FOR_TAIL = 1000;

    private const string TIMESTAMP_LINE = '/^(?:\d{1,2}:)?\d{1,2}:\d{2}[.,]\d{1,3}\s*-->/';
    private const string CUE_NUMBER_LINE = '/^\d+$/';

    /**
     * @param int|null $maxLength keep at most this many characters, cutting on word
     *                            boundaries and dropping the middle rather than the end
     */
    public function toPlainText(string $subtitles, ?int $maxLength = null): string
    {
        $rawLines = preg_split('/\r\n|\n|\r/', $subtitles);
        $lines = array_map('trim', false === $rawLines ? [] : $rawLines);

        /** @var list<string> $words */
        $words = [];
        $inNote = false;

        foreach ($lines as $index => $line) {
            if ('' === $line) {
                $inNote = false;
                continue;
            }

            if ($inNote) {
                continue;
            }

            if (str_starts_with($line, 'NOTE')) {
                $inNote = true;
                continue;
            }

            if (self::isMetadata($line) || self::isCueIdentifier($lines, $index)) {
                continue;
            }

            $text = self::cleanCueText($line);
            if ('' === $text) {
                continue;
            }

            $incoming = preg_split('/\s+/', $text);
            $words = self::appendWithoutOverlap($words, false === $incoming ? [] : $incoming);
        }

        $plain = implode(' ', $words);

        return null === $maxLength ? $plain : self::truncate($plain, $maxLength);
    }

    /**
     * A cue identifier is any line sitting right before a timestamp line.
     *
     * @param list<string> $lines
     */
    private static function isCueIdentifier(array $lines, int $index): bool
    {
        $next = $lines[$index + 1] ?? null;

        return null !== $next && 1 === preg_match(self::TIMESTAMP_LINE, $next);
    }

    private static function isMetadata(string $line): bool
    {
        return 'WEBVTT' === $line
            || str_starts_with($line, 'WEBVTT ')
            || 1 === preg_match(self::TIMESTAMP_LINE, $line)
            || 1 === preg_match(self::CUE_NUMBER_LINE, $line)
            // VTT header and cue identifiers: "Kind: captions", "align:start".
            || 1 === preg_match('/^(Kind|Language|Region|Style|X-TIMESTAMP-MAP)\b/i', $line);
    }

    private static function cleanCueText(string $line): string
    {
        // Karaoke timestamps and any inline tag: <00:00:01.240>, <c.colorE5E5E5>, <i>.
        $text = preg_replace('/<[^>]*>/', '', $line) ?? '';
        $text = html_entity_decode($text, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        return trim($text);
    }

    /**
     * Appends the new words, skipping the longest prefix that repeats the tail of
     * what we already have. This is what undoes the rolling window.
     *
     * @param list<string> $words
     * @param list<string> $incoming
     *
     * @return list<string>
     */
    private static function appendWithoutOverlap(array $words, array $incoming): array
    {
        $incoming = array_values(array_filter($incoming, static fn (string $word): bool => '' !== $word));
        if ([] === $incoming) {
            return $words;
        }

        $maxOverlap = min(\count($words), \count($incoming));
        for ($overlap = $maxOverlap; $overlap > 0; --$overlap) {
            if (\array_slice($words, -$overlap) === \array_slice($incoming, 0, $overlap)) {
                return [...$words, ...\array_slice($incoming, $overlap)];
            }
        }

        return [...$words, ...$incoming];
    }

    /**
     * Keeps the opening and the conclusion, and elides what sits between them.
     *
     * Cutting the tail off would throw away the call to action and the closing
     * pitch, which is exactly what an analysis needs to advise on a relaunch.
     */
    private static function truncate(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        if ($maxLength < self::MIN_LENGTH_FOR_TAIL) {
            return self::head($text, $maxLength) . '…';
        }

        $budget = $maxLength - mb_strlen(self::ELISION);
        $headLength = intdiv($budget * self::HEAD_SHARE, self::SHARES);

        return self::head($text, $headLength) . self::ELISION . self::tail($text, $budget - $headLength);
    }

    /**
     * The first $length characters, backed off to the last whole word.
     */
    private static function head(string $text, int $length): string
    {
        $cut = mb_substr($text, 0, $length);
        $lastSpace = mb_strrpos($cut, ' ');

        return rtrim(false === $lastSpace ? $cut : mb_substr($cut, 0, $lastSpace));
    }

    /**
     * The last $length characters, advanced to the next whole word.
     */
    private static function tail(string $text, int $length): string
    {
        $cut = mb_substr($text, -$length);
        $firstSpace = mb_strpos($cut, ' ');

        return ltrim(false === $firstSpace ? $cut : mb_substr($cut, $firstSpace + 1));
    }
}
