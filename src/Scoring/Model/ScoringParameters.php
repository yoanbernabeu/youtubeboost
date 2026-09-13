<?php

declare(strict_types=1);

namespace App\Scoring\Model;

/**
 * Tunable thresholds of the relaunch score, editable from the settings screen.
 */
final readonly class ScoringParameters
{
    public const int DEFAULT_MIN_AGE_DAYS = 60;
    public const int DEFAULT_WINDOW_DAYS = 28;
    public const int DEFAULT_WARMUP_DAYS = 30;
    public const float DEFAULT_CTR_FLOOR_RATIO = 0.5;
    public const float DEFAULT_RETENTION_TARGET_RATIO = 2.0;
    public const int DEFAULT_RELAUNCH_COOLDOWN_DAYS = 28;

    public function __construct(
        /** A video must be at least this old before it is worth relaunching. */
        public int $minAgeDays = self::DEFAULT_MIN_AGE_DAYS,
        /** Length of the "recent" window every signal looks at. */
        public int $windowDays = self::DEFAULT_WINDOW_DAYS,
        /** Days after publication that are excluded when looking for the peak. */
        public int $warmupDays = self::DEFAULT_WARMUP_DAYS,
        /** Share of the channel median CTR below which the signal saturates at 1. */
        public float $ctrFloorRatio = self::DEFAULT_CTR_FLOOR_RATIO,
        /** Retention ratio to the channel median that scores a full point. */
        public float $retentionTargetRatio = self::DEFAULT_RETENTION_TARGET_RATIO,
        /** A video under tracking is left alone for this long. */
        public int $relaunchCooldownDays = self::DEFAULT_RELAUNCH_COOLDOWN_DAYS,
    ) {
        if ($this->windowDays < 1 || $this->warmupDays < 0 || $this->minAgeDays < 0 || $this->relaunchCooldownDays < 0) {
            throw new \InvalidArgumentException('Scoring day counts must be positive.');
        }

        if ($this->ctrFloorRatio < 0.0 || $this->ctrFloorRatio >= 1.0) {
            throw new \InvalidArgumentException('The CTR floor ratio must sit between 0 and 1, excluded.');
        }

        if ($this->retentionTargetRatio <= 0.0) {
            throw new \InvalidArgumentException('The retention target ratio must be greater than zero.');
        }
    }

    public static function defaults(): self
    {
        return new self();
    }

    /**
     * First day of the recent window ending on $referenceDate.
     */
    public function windowStart(\DateTimeImmutable $referenceDate): \DateTimeImmutable
    {
        return $referenceDate->setTime(0, 0)->modify(\sprintf('-%d days', $this->windowDays - 1));
    }
}
