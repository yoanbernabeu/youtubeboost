<?php

declare(strict_types=1);

namespace App\Thumbnail\Generation;

use App\Analysis\Model\ThumbnailAngle;
use App\Settings\Locale\ContentLanguage;

/**
 * Assembles the prompt actually sent to the image model.
 *
 * The angle prompt written by the language model is the creative part; everything
 * added here is the part that never changes: the format, the overlay text and the
 * language it is written in, the channel style and the mistakes to avoid.
 */
final class ThumbnailPromptBuilder
{
    public function __construct(
        private readonly ContentLanguage $language,
    ) {
    }

    /**
     * Naming the person makes Gemini drop the whole image — `finishReason:
     * IMAGE_OTHER`, not a single part returned — even when the person is the
     * creator generating their own face from their own photos. A proper noun reads
     * as a request to depict a real identity from memory, which the model declines.
     * The reference images carry the identity, and that is the sanctioned way to
     * ask for it, so the channel name has no business in an image prompt.
     */
    public function forAngle(ThumbnailAngle $angle, string $styleGuidelines): string
    {
        $sections = [
            $angle->imagePrompt,
            '',
            'Hard requirements:',
            '- 16:9 YouTube thumbnail, one single image, no border, no frame, no watermark.',
            \sprintf('- The person is the creator shown in the reference photos, seen %s.', $angle->referenceAngle->promptHint()),
            '- Reproduce the face identity from the reference photos exactly: same bone structure, same hair, same skin tone, same age.',
            \sprintf('- Facial expression: %s.', '' !== $angle->faceExpression ? $angle->faceExpression : 'engaging'),
            \sprintf('- Render this exact text in the image, spelled character for character: "%s".', $angle->overlayText),
            \sprintf('- The text is in %s. Use a heavy condensed sans-serif in upper case, with a thick outline or a hard drop shadow, so it stays readable at 320 by 180 pixels.', $this->language->englishName()),
            '- The text must not cover the face, and must stay clear of the bottom-right corner where YouTube draws the duration badge.',
            '- No other text anywhere in the image: no subtitle, no logo, no URL, no signature.',
            '- High contrast, saturated colours, a clearly separated subject and background.',
            '- Photographic look, sharp focus on the face, no illustration style unless the scene asks for it.',
        ];

        if ('' !== trim($styleGuidelines)) {
            $sections[] = '';
            $sections[] = 'Channel style to respect:';
            $sections[] = trim($styleGuidelines);
        }

        return implode("\n", $sections);
    }

    public function forIteration(string $instruction, string $overlayText, string $styleGuidelines): string
    {
        $sections = [
            'Edit the attached thumbnail according to this instruction, and change nothing else:',
            trim($instruction),
            '',
            'Keep unchanged:',
            '- The identity of the face: same person, same features.',
            '- The 16:9 format and the overall composition, unless the instruction asks otherwise.',
        ];

        if ('' !== trim($overlayText)) {
            $sections[] = \sprintf('- The text "%s", spelled character for character, unless the instruction asks otherwise.', trim($overlayText));
        }

        $sections[] = '- No text other than the overlay text, no watermark, no frame.';

        if ('' !== trim($styleGuidelines)) {
            $sections[] = '';
            $sections[] = 'Channel style to respect:';
            $sections[] = trim($styleGuidelines);
        }

        return implode("\n", $sections);
    }
}
