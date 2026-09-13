<?php

declare(strict_types=1);

namespace App\Settings;

use App\Settings\Entity\ReferenceAngle;
use App\Settings\Entity\ReferencePhoto;
use App\Settings\Repository\ReferencePhotoRepository;
use App\Settings\Storage\ReferencePhotoStore;
use App\Shared\Image\ImageNormalizer;
use App\Shared\Image\InvalidImageException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Adds, relabels and removes the reference photos of the creator.
 */
final class ReferencePhotoManager
{
    /** Beyond this the photo adds weight to every prompt, not likeness. */
    private const int MAX_SIDE = 1600;

    private const int MAX_UPLOAD_BYTES = 12 * 1024 * 1024;

    /** @var list<string> */
    private const array ACCEPTED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private readonly ReferencePhotoRepository $photos,
        private readonly ReferencePhotoStore $store,
        private readonly ImageNormalizer $normalizer,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @throws InvalidReferencePhotoException
     */
    public function add(UploadedFile $file, ReferenceAngle $angle, string $label = ''): ReferencePhoto
    {
        if (!$file->isValid()) {
            throw InvalidReferencePhotoException::uploadFailed($file->getClientOriginalName());
        }

        if ($file->getSize() > self::MAX_UPLOAD_BYTES) {
            throw InvalidReferencePhotoException::tooHeavy($file->getClientOriginalName(), self::MAX_UPLOAD_BYTES);
        }

        if (!\in_array((string) $file->getMimeType(), self::ACCEPTED_MIME_TYPES, true)) {
            throw InvalidReferencePhotoException::unsupportedType($file->getClientOriginalName());
        }

        $binary = (string) file_get_contents($file->getPathname());

        try {
            $jpeg = $this->normalizer->toBoundedJpeg($binary, self::MAX_SIDE);
        } catch (InvalidImageException $exception) {
            throw InvalidReferencePhotoException::unreadable($file->getClientOriginalName(), $exception);
        }

        $path = $this->store->write($jpeg, $angle->value, pathinfo($file->getClientOriginalName(), \PATHINFO_FILENAME), 'jpg');

        $photo = new ReferencePhoto(
            $path,
            $angle,
            '' !== trim($label) ? trim($label) : $this->defaultLabel($angle),
            'image/jpeg',
            \strlen($jpeg),
            \DateTimeImmutable::createFromInterface($this->clock->now()),
        );

        $this->entityManager->persist($photo);
        $this->entityManager->flush();

        return $photo;
    }

    public function remove(ReferencePhoto $photo): void
    {
        $this->store->delete($photo->getPath());
        $this->entityManager->remove($photo);
        $this->entityManager->flush();
    }

    public function relabel(ReferencePhoto $photo, ReferenceAngle $angle, string $label): void
    {
        $photo->reclassify($angle, '' !== trim($label) ? trim($label) : $this->defaultLabel($angle));
        $this->entityManager->flush();
    }

    /**
     * @return list<ReferenceAngle> the angles the product specification asks for
     */
    public function missingRequiredAngles(): array
    {
        $covered = $this->photos->coveredAngles();

        return array_values(array_filter(
            [ReferenceAngle::Front, ReferenceAngle::Right, ReferenceAngle::Left],
            static fn (ReferenceAngle $angle): bool => !\in_array($angle, $covered, true),
        ));
    }

    /**
     * Stored as is on the photo, where the creator can rename it: this is data,
     * not interface wording, so it is not translated.
     */
    private function defaultLabel(ReferenceAngle $angle): string
    {
        return match ($angle) {
            ReferenceAngle::Front => 'Front',
            ReferenceAngle::Right => 'Right profile',
            ReferenceAngle::Left => 'Left profile',
            ReferenceAngle::Other => 'Other angle',
        };
    }
}
