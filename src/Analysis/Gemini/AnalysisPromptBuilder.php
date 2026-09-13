<?php

declare(strict_types=1);

namespace App\Analysis\Gemini;

use App\Analysis\Model\VideoBrief;
use App\Settings\Entity\ReferenceAngle;
use App\Settings\Locale\ContentLanguage;
use App\Shared\Time\IsoDuration;

/**
 * Builds the two messages sent to the language model.
 *
 * The instructions are in English because that is what the models follow most
 * reliably, while every piece of text meant for the audience — the overlay words —
 * is explicitly required in the language of the interface, which is the language
 * the creator publishes in.
 */
final class AnalysisPromptBuilder
{
    public const int ANGLE_COUNT = 5;

    public function __construct(
        private readonly ContentLanguage $language,
    ) {
    }

    public function systemPrompt(): string
    {
        $language = $this->language->englishName();
        $emphasised = mb_strtoupper($language);

        return <<<PROMPT
            You are a YouTube packaging strategist. You work for a single {$language}-speaking
            creator and your only job is to design thumbnails that win the click on videos
            that already exist.

            Hard rules:
            - Produce exactly {$this->angleCount()} angles, each genuinely different from the others:
              different framing, different emotion, different promise. Never five variations
              of one idea.
            - Every `overlayText` is in {$emphasised}, 2 to 4 words, no final punctuation, no
              hashtag, no emoji. It must be readable at the size of a phone thumbnail.
            - `overlayText` never repeats the video title word for word. It says the part
              that makes someone curious.
            - `referenceAngle` is one of: front, right, left. Pick the one that serves the
              composition, and only pick an angle the creator actually has a photo for.
            - `imagePrompt` is in English, self-contained, and describes a 16:9 YouTube
              thumbnail: subject placement, the creator's face and expression, background,
              lighting, colour palette, and where the overlay text sits. It must ask for the
              overlay text to be rendered in the image, spelled exactly as given, in a heavy
              sans-serif with a strong outline or shadow so it survives a small screen.
            - Never promise anything the video does not deliver. Never mention brands or
              people who are not in the video.
            - Leave generous empty space away from the bottom-right corner, where YouTube
              draws the duration badge.

            Answer only with the requested structure, in no other format.
            PROMPT;
    }

    public function userPrompt(VideoBrief $brief): string
    {
        $sections = [
            '# Channel',
            $brief->channelTitle,
            '',
            '# Video',
            \sprintf('Title: %s', $brief->title),
            \sprintf('Published: %s', $brief->publishedAt->format('Y-m-d')),
            \sprintf('Duration: %s', IsoDuration::format($brief->durationSeconds)),
            '',
            '## Description',
            '' === trim($brief->description) ? '(empty)' : $brief->description,
            '',
            '## Performance',
            '' === trim($brief->statsSummary) ? '(no data)' : $brief->statsSummary,
        ];

        $sections[] = '';
        $sections[] = '## Available reference photos of the creator';
        $sections[] = $this->describeAngles($brief->availableAngles);

        if ('' !== trim($brief->styleGuidelines)) {
            $sections[] = '';
            $sections[] = '## Channel style guidelines, to follow closely';
            $sections[] = trim($brief->styleGuidelines);
        }

        $sections[] = '';
        if ($brief->hasTranscript()) {
            $sections[] = '## Transcript';
            $sections[] = (string) $brief->transcript;
        } else {
            $sections[] = '## Transcript';
            $sections[] = 'Not available. Work from the title, the description and the '
                . 'performance data alone, and stay cautious about what the video contains.';
        }

        return implode("\n", $sections);
    }

    /**
     * The answer schema, in the same language as the instructions above.
     *
     * It travels with the prompt because the schema descriptions name the language
     * the creator-facing fields must be written in, and only this class knows it.
     *
     * @return array<string, mixed>
     */
    public function responseSchema(): array
    {
        return ThumbnailPlanSchema::definition(self::ANGLE_COUNT, $this->language->englishName());
    }

    /**
     * @param list<ReferenceAngle> $angles
     */
    private function describeAngles(array $angles): string
    {
        $usable = array_values(array_filter($angles, static fn (ReferenceAngle $angle): bool => ReferenceAngle::Other !== $angle));
        if ([] === $usable) {
            return 'None. Describe the creator generically and keep the face small in the frame.';
        }

        return implode("\n", array_map(
            static fn (ReferenceAngle $angle): string => \sprintf('- %s (%s)', $angle->value, $angle->promptHint()),
            $usable,
        ));
    }

    private function angleCount(): int
    {
        return self::ANGLE_COUNT;
    }
}
