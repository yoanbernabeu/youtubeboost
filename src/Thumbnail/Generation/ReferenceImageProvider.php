<?php

declare(strict_types=1);

namespace App\Thumbnail\Generation;

use App\Settings\Entity\ReferenceAngle;
use App\Settings\Entity\ReferencePhoto;
use App\Settings\Repository\ReferencePhotoRepository;
use App\Settings\Storage\ReferencePhotoStore;
use App\Shared\Storage\StorageFailedException;
use Psr\Log\LoggerInterface;

/**
 * Loads the reference photos to send with a generation.
 *
 * All of them are sent — the model uses every angle to build one coherent face —
 * but the requested angle comes first, because order is what the prompt refers to.
 */
final class ReferenceImageProvider
{
    public function __construct(
        private readonly ReferencePhotoRepository $photos,
        private readonly ReferencePhotoStore $store,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<ReferenceImage>
     */
    public function forAngle(ReferenceAngle $preferred): array
    {
        $photos = $this->photos->findAllOrdered();
        usort($photos, static fn (ReferencePhoto $a, ReferencePhoto $b): int => self::rank($b, $preferred) <=> self::rank($a, $preferred));

        $images = [];
        foreach ($photos as $photo) {
            try {
                $images[] = new ReferenceImage(
                    $this->store->read($photo->getPath()),
                    $photo->getMimeType(),
                    $photo->getAngle(),
                    $photo->getLabel(),
                );
            } catch (StorageFailedException $exception) {
                $this->logger->warning('A reference photo is missing from the storage.', [
                    'path' => $photo->getPath(),
                    'exception' => $exception,
                ]);
            }
        }

        return $images;
    }

    public function hasAny(): bool
    {
        return [] !== $this->photos->findAllOrdered();
    }

    /**
     * @return list<ReferenceAngle>
     */
    public function availableAngles(): array
    {
        return $this->photos->coveredAngles();
    }

    private static function rank(ReferencePhoto $photo, ReferenceAngle $preferred): int
    {
        return $photo->getAngle() === $preferred ? 1 : 0;
    }
}
