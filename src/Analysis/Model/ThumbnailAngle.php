<?php

declare(strict_types=1);

namespace App\Analysis\Model;

use App\Settings\Entity\ReferenceAngle;

/**
 * One of the five thumbnail ideas the language model proposes.
 */
final readonly class ThumbnailAngle
{
    public function __construct(
        /** Zero-based position in the proposal grid. */
        public int $index,
        /** Two to four French words to burn into the image. */
        public string $overlayText,
        public string $sceneDescription,
        public string $faceExpression,
        public ReferenceAngle $referenceAngle,
        /** Self-contained prompt handed to the image model. */
        public string $imagePrompt,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(int $index, array $data): self
    {
        $string = static fn (string $key): string => \is_scalar($data[$key] ?? null) ? trim((string) $data[$key]) : '';

        return new self(
            $index,
            $string('overlayText'),
            $string('sceneDescription'),
            $string('faceExpression'),
            ReferenceAngle::fromLabel($string('referenceAngle')),
            $string('imagePrompt'),
        );
    }

    /**
     * @return array{overlayText: string, sceneDescription: string, faceExpression: string, referenceAngle: string, imagePrompt: string}
     */
    public function toArray(): array
    {
        return [
            'overlayText' => $this->overlayText,
            'sceneDescription' => $this->sceneDescription,
            'faceExpression' => $this->faceExpression,
            'referenceAngle' => $this->referenceAngle->value,
            'imagePrompt' => $this->imagePrompt,
        ];
    }

    public function withPrompt(string $imagePrompt): self
    {
        return new self($this->index, $this->overlayText, $this->sceneDescription, $this->faceExpression, $this->referenceAngle, $imagePrompt);
    }
}
