<?php

declare(strict_types=1);

namespace App\Settings\Entity;

/**
 * The angle a reference photo of the creator was shot from.
 *
 * The image model is told which one to favour for a given thumbnail angle.
 */
enum ReferenceAngle: string
{
    case Front = 'front';
    case Right = 'right';
    case Left = 'left';
    case Other = 'other';

    public static function fromLabel(string $label): self
    {
        return match (strtolower(trim($label))) {
            'front', 'face' => self::Front,
            'right', 'droite', 'right_profile' => self::Right,
            'left', 'gauche', 'left_profile' => self::Left,
            default => self::Other,
        };
    }

    /**
     * Wording injected into the image prompt.
     */
    public function promptHint(): string
    {
        return match ($this) {
            self::Front => 'face-on, looking straight at the camera',
            self::Right => 'three-quarter view turned to their right',
            self::Left => 'three-quarter view turned to their left',
            self::Other => 'any flattering angle',
        };
    }
}
