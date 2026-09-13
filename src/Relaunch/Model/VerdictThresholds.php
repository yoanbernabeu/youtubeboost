<?php

declare(strict_types=1);

namespace App\Relaunch\Model;

use App\Settings\SettingKey;
use App\Settings\Settings;

/**
 * Where the line is drawn between a win, a shrug and a mistake.
 */
final readonly class VerdictThresholds
{
    public function __construct(
        /** Daily views must reach this multiple of the "before" figure to be a win. */
        public float $successViewsRatio = 1.5,
        /** Below this multiple, the relaunch is a loss. */
        public float $neutralFloorRatio = 0.8,
        /** A click-through rate below this multiple is a loss on its own. */
        public float $negativeCtrRatio = 0.8,
    ) {
        if ($this->neutralFloorRatio <= 0.0 || $this->successViewsRatio <= $this->neutralFloorRatio || $this->negativeCtrRatio <= 0.0) {
            throw new \InvalidArgumentException('Verdict thresholds must be positive and ordered.');
        }
    }

    public static function defaults(): self
    {
        return new self();
    }

    public static function fromSettings(Settings $settings): self
    {
        try {
            return new self(
                $settings->getFloat(SettingKey::VerdictSuccessViewsRatio),
                $settings->getFloat(SettingKey::VerdictNeutralFloorRatio),
                $settings->getFloat(SettingKey::VerdictNegativeCtrRatio),
            );
        } catch (\InvalidArgumentException) {
            return self::defaults();
        }
    }
}
