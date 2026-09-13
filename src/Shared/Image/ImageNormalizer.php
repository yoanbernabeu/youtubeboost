<?php

declare(strict_types=1);

namespace App\Shared\Image;

/**
 * Brings any generated image to the exact shape YouTube expects.
 *
 * Gemini returns 16:9 images at 1344x768, which is not quite 1280x720: scaling
 * alone would distort by about 1.6 %. The image is therefore scaled to cover the
 * target and the centre is cropped.
 *
 * GD is used on purpose. ImageMagick's OpenMP threads are documented as unstable
 * inside FrankenPHP's worker threads, and php-vips goes through FFI, which is
 * worse. For a plain downscale to 1280x720 the three are visually equivalent.
 */
final class ImageNormalizer
{
    public const int WIDTH = 1280;
    public const int HEIGHT = 720;

    /** Hard limit of the `thumbnails.set` endpoint. */
    public const int MAX_UPLOAD_BYTES = 2 * 1024 * 1024;

    /** Quality steps tried when a PNG is too heavy for YouTube. */
    private const array JPEG_QUALITY_STEPS = [92, 86, 80, 72, 60];

    /**
     * @throws InvalidImageException
     */
    public function toThumbnailPng(string $binary): string
    {
        $source = $this->read($binary);

        try {
            $target = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
            if (false === $target) {
                throw InvalidImageException::unreadable();
            }

            $sourceWidth = imagesx($source);
            $sourceHeight = imagesy($source);

            // Scale to cover, then take the centre.
            $scale = max(self::WIDTH / $sourceWidth, self::HEIGHT / $sourceHeight);
            $cropWidth = (int) round(self::WIDTH / $scale);
            $cropHeight = (int) round(self::HEIGHT / $scale);
            $cropX = (int) round(($sourceWidth - $cropWidth) / 2);
            $cropY = (int) round(($sourceHeight - $cropHeight) / 2);

            imagecopyresampled(
                $target,
                $source,
                0,
                0,
                $cropX,
                $cropY,
                self::WIDTH,
                self::HEIGHT,
                min($cropWidth, $sourceWidth),
                min($cropHeight, $sourceHeight),
            );

            return $this->encodePng($target);
        } finally {
            imagedestroy($source);
            if (isset($target) && false !== $target) {
                imagedestroy($target);
            }
        }
    }

    /**
     * Returns the payload to upload, downgrading to JPEG when it is too heavy.
     *
     * A photographic 1280x720 PNG regularly lands above two megabytes, and a
     * rejected upload still costs fifty quota units.
     */
    public function forUpload(string $binary, string $mimeType = 'image/png'): UploadImage
    {
        if (\strlen($binary) <= self::MAX_UPLOAD_BYTES) {
            return new UploadImage($binary, $mimeType);
        }

        $image = $this->read($binary);

        try {
            foreach (self::JPEG_QUALITY_STEPS as $quality) {
                $jpeg = $this->encodeJpeg($image, $quality);
                if (\strlen($jpeg) <= self::MAX_UPLOAD_BYTES) {
                    return new UploadImage($jpeg, 'image/jpeg');
                }
            }

            return new UploadImage($this->encodeJpeg($image, 40), 'image/jpeg');
        } finally {
            imagedestroy($image);
        }
    }

    /**
     * Re-encodes an uploaded photo as a JPEG whose longest side fits the bound.
     *
     * Reference photos are sent on every generation, so a 24 megapixel phone
     * picture would make each request needlessly heavy for no extra likeness.
     *
     * @throws InvalidImageException
     */
    public function toBoundedJpeg(string $binary, int $maxSide = 1600, int $quality = 90): string
    {
        if ($maxSide < 1) {
            throw new \InvalidArgumentException('The bound must be at least one pixel.');
        }

        $source = $this->read($binary);

        try {
            $width = imagesx($source);
            $height = imagesy($source);
            $longest = max($width, $height);

            if ($longest <= $maxSide) {
                return $this->encodeJpeg($source, $quality);
            }

            $scale = $maxSide / $longest;
            $targetWidth = max(1, (int) round($width * $scale));
            $targetHeight = max(1, (int) round($height * $scale));

            $target = imagecreatetruecolor($targetWidth, $targetHeight);
            if (false === $target) {
                throw InvalidImageException::unreadable();
            }

            try {
                imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

                return $this->encodeJpeg($target, $quality);
            } finally {
                imagedestroy($target);
            }
        } finally {
            imagedestroy($source);
        }
    }

    /**
     * @return array{0: int, 1: int}
     *
     * @throws InvalidImageException
     */
    public function dimensions(string $binary): array
    {
        $size = @getimagesizefromstring($binary);
        if (false === $size) {
            throw InvalidImageException::unreadable();
        }

        return [$size[0], $size[1]];
    }

    private function read(string $binary): \GdImage
    {
        if ('' === $binary) {
            throw InvalidImageException::unreadable();
        }

        $image = @imagecreatefromstring($binary);
        if (false === $image) {
            throw InvalidImageException::unreadable();
        }

        return $image;
    }

    private function encodePng(\GdImage $image): string
    {
        ob_start();
        $written = imagepng($image, null, 9);
        $binary = (string) ob_get_clean();

        if (!$written || '' === $binary) {
            throw InvalidImageException::unreadable();
        }

        return $binary;
    }

    private function encodeJpeg(\GdImage $image, int $quality): string
    {
        ob_start();
        $written = imagejpeg($image, null, $quality);
        $binary = (string) ob_get_clean();

        if (!$written || '' === $binary) {
            throw InvalidImageException::unreadable();
        }

        return $binary;
    }
}
