<?php

declare(strict_types=1);

namespace App\Settings\Input;

use App\Scoring\Model\Signal;
use App\Settings\SettingKey;

/**
 * The scoring section of the settings form, clamped to values that cannot break
 * a synchronisation.
 *
 * Clamping rather than rejecting is deliberate: a creator sliding a weight too far
 * should see the tool settle on the nearest sensible value, not a validation wall.
 */
final readonly class ScoringSettingsInput
{
    public const int MAX_WEIGHT = 100;
    public const int MIN_AGE_MAX = 3650;
    public const int WINDOW_MIN = 7;
    public const int WINDOW_MAX = 120;
    public const int WARMUP_MAX = 365;
    public const float CTR_FLOOR_MIN = 0.05;
    public const float CTR_FLOOR_MAX = 0.95;
    public const float RETENTION_TARGET_MIN = 1.1;
    public const float RETENTION_TARGET_MAX = 5.0;
    public const int COOLDOWN_MAX = 365;

    /**
     * @param array<non-empty-string, int> $weights
     */
    private function __construct(
        public array $weights,
        public int $minAgeDays,
        public int $windowDays,
        public int $warmupDays,
        public float $ctrFloorRatio,
        public float $retentionTargetRatio,
        public int $relaunchCooldownDays,
    ) {
    }

    /**
     * @param array<array-key, mixed> $values raw request payload
     */
    public static function fromArray(array $values): self
    {
        $submitted = $values['weights'] ?? null;
        $submitted = \is_array($submitted) ? $submitted : [];

        $weights = [];
        $total = 0;
        foreach (Signal::cases() as $signal) {
            $weight = self::clampInt($submitted[$signal->value] ?? null, 0, self::MAX_WEIGHT, (int) $signal->defaultWeight());
            $weights[$signal->value] = $weight;
            $total += $weight;
        }

        // All weights at zero would make every score zero; fall back on the defaults.
        if (0 === $total) {
            foreach (Signal::cases() as $signal) {
                $weights[$signal->value] = (int) $signal->defaultWeight();
            }
        }

        return new self(
            $weights,
            self::clampInt($values['minAgeDays'] ?? null, 0, self::MIN_AGE_MAX, 60),
            self::clampInt($values['windowDays'] ?? null, self::WINDOW_MIN, self::WINDOW_MAX, 28),
            self::clampInt($values['warmupDays'] ?? null, 0, self::WARMUP_MAX, 30),
            self::clampFloat($values['ctrFloorRatio'] ?? null, self::CTR_FLOOR_MIN, self::CTR_FLOOR_MAX, 0.5),
            self::clampFloat($values['retentionTargetRatio'] ?? null, self::RETENTION_TARGET_MIN, self::RETENTION_TARGET_MAX, 2.0),
            self::clampInt($values['relaunchCooldownDays'] ?? null, 0, self::COOLDOWN_MAX, 28),
        );
    }

    /**
     * @return array<string, mixed> indexed by {@see SettingKey} value
     */
    public function toSettings(): array
    {
        return [
            SettingKey::ScoringWeights->value => $this->weights,
            SettingKey::ScoringMinAgeDays->value => $this->minAgeDays,
            SettingKey::ScoringWindowDays->value => $this->windowDays,
            SettingKey::ScoringWarmupDays->value => $this->warmupDays,
            SettingKey::ScoringCtrFloorRatio->value => $this->ctrFloorRatio,
            SettingKey::ScoringRetentionTargetRatio->value => $this->retentionTargetRatio,
            SettingKey::ScoringRelaunchCooldownDays->value => $this->relaunchCooldownDays,
        ];
    }

    private static function clampInt(mixed $value, int $min, int $max, int $fallback): int
    {
        if (!is_numeric($value)) {
            return $fallback;
        }

        return max($min, min($max, (int) $value));
    }

    private static function clampFloat(mixed $value, float $min, float $max, float $fallback): float
    {
        if (!is_numeric($value)) {
            return $fallback;
        }

        return max($min, min($max, round((float) $value, 3)));
    }
}
