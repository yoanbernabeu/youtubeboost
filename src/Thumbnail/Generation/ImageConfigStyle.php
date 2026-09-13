<?php

declare(strict_types=1);

namespace App\Thumbnail\Generation;

/**
 * How the aspect ratio is declared to the Gemini API.
 *
 * Two spellings coexist on `v1beta`, and they do not accept the same values:
 * `generationConfig.imageConfig` takes the plain `16:9`, while
 * `generationConfig.responseFormat.image` types the setting as a protobuf enum.
 * `imageConfig` is the one the API answers to today, so it goes first; the other
 * is kept for the day Google makes it the only shape. The generator remembers
 * whichever one the creator's account accepted.
 */
enum ImageConfigStyle: string
{
    case ImageConfig = 'image_config';
    case ResponseFormat = 'response_format';

    /** Last resort: let the model pick the ratio from the prompt alone. */
    case None = 'none';

    /**
     * @return array<string, mixed>
     */
    public function toOptions(ImageShape $shape): array
    {
        return match ($this) {
            self::ImageConfig => ['imageConfig' => [
                'aspectRatio' => $shape->aspectRatio,
                'imageSize' => $shape->imageSize,
            ]],
            self::ResponseFormat => ['responseFormat' => ['image' => [
                'aspectRatio' => $shape->aspectRatioEnum,
                'imageSize' => $shape->imageSizeEnum,
            ]]],
            self::None => [],
        };
    }
}
