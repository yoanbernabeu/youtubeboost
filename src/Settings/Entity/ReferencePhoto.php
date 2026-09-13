<?php

declare(strict_types=1);

namespace App\Settings\Entity;

use App\Settings\Repository\ReferencePhotoRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A photo of the creator, used so the generated face stays recognisable.
 */
#[ORM\Entity(repositoryClass: ReferencePhotoRepository::class)]
#[ORM\Table(name: 'reference_photo')]
class ReferencePhoto
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Path inside the reference photo storage. */
    #[ORM\Column(length: 255, unique: true)]
    private string $path;

    #[ORM\Column(length: 16, enumType: ReferenceAngle::class)]
    private ReferenceAngle $angle;

    #[ORM\Column(length: 128)]
    private string $label;

    #[ORM\Column(length: 64)]
    private string $mimeType;

    #[ORM\Column]
    private int $byteSize;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $uploadedAt;

    public function __construct(
        string $path,
        ReferenceAngle $angle,
        string $label,
        string $mimeType,
        int $byteSize,
        \DateTimeImmutable $uploadedAt,
    ) {
        $this->path = $path;
        $this->angle = $angle;
        $this->label = $label;
        $this->mimeType = $mimeType;
        $this->byteSize = $byteSize;
        $this->uploadedAt = $uploadedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getAngle(): ReferenceAngle
    {
        return $this->angle;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getByteSize(): int
    {
        return $this->byteSize;
    }

    public function getUploadedAt(): \DateTimeImmutable
    {
        return $this->uploadedAt;
    }

    public function reclassify(ReferenceAngle $angle, string $label): void
    {
        $this->angle = $angle;
        $this->label = $label;
    }
}
