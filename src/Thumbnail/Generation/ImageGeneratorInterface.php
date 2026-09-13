<?php

declare(strict_types=1);

namespace App\Thumbnail\Generation;

use App\Thumbnail\Exception\ImageGenerationFailedException;

/**
 * Generates a thumbnail image from a prompt and reference photos.
 *
 * Behind an interface so a different provider can be dropped in without touching
 * the rest of the application.
 */
interface ImageGeneratorInterface
{
    /**
     * @throws ImageGenerationFailedException
     */
    public function generate(ImageRequest $request): GeneratedImage;
}
