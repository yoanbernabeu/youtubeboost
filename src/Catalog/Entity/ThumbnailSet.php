<?php

declare(strict_types=1);

namespace App\Catalog\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The thumbnail images YouTube exposes for a video, by size name.
 *
 * YouTube keeps adding sizes (`fhd`, `qhd`, `uhd`...) and omits the large ones on
 * older uploads, so sizes are never indexed by name: the set simply keeps the
 * widest image it was given.
 */
#[ORM\Embeddable]
final class ThumbnailSet
{
    /** Sizes wide enough to be shown in a comparison view. */
    private const int PREVIEW_MIN_WIDTH = 320;

    /**
     * @param array<string, array{url: string, width: int, height: int}> $images
     */
    private function __construct(
        #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
        private array $images = [],
    ) {
    }

    /**
     * @param array<array-key, mixed> $payload the `snippet.thumbnails` map of the Data API
     */
    public static function fromApiPayload(array $payload): self
    {
        $images = [];
        foreach ($payload as $name => $image) {
            if (!\is_string($name) || !\is_array($image)) {
                continue;
            }

            $url = $image['url'] ?? null;
            $width = $image['width'] ?? null;
            $height = $image['height'] ?? null;
            if (!\is_string($url) || '' === $url || !is_numeric($width) || !is_numeric($height)) {
                continue;
            }

            $images[$name] = ['url' => $url, 'width' => (int) $width, 'height' => (int) $height];
        }

        return new self($images);
    }

    /**
     * URL of the widest available image, used when archiving the current thumbnail.
     */
    public function best(): ?string
    {
        return $this->widest($this->images);
    }

    /**
     * URL of a reasonably sized image for on-screen comparison.
     */
    public function preview(): ?string
    {
        $candidates = array_filter(
            $this->images,
            static fn (array $image): bool => $image['width'] <= 640 && $image['width'] >= self::PREVIEW_MIN_WIDTH,
        );

        return $this->widest($candidates) ?? $this->best();
    }

    public function isEmpty(): bool
    {
        return [] === $this->images;
    }

    /**
     * @return array<string, array{url: string, width: int, height: int}>
     */
    public function toArray(): array
    {
        return $this->images;
    }

    /**
     * @param array<string, array{url: string, width: int, height: int}> $images
     */
    private function widest(array $images): ?string
    {
        $best = null;
        $bestWidth = -1;
        foreach ($images as $image) {
            if ($image['width'] > $bestWidth) {
                $bestWidth = $image['width'];
                $best = $image['url'];
            }
        }

        return $best;
    }
}
