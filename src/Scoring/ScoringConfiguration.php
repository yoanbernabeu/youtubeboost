<?php

declare(strict_types=1);

namespace App\Scoring;

use App\Scoring\Model\ScoringParameters;
use App\Scoring\Model\Weights;
use App\Settings\SettingKey;
use App\Settings\Settings;

/**
 * Turns the persisted settings into the value objects the scoring module needs.
 *
 * Invalid stored values never break a synchronisation: they fall back on the
 * defaults, which the settings form validates against anyway.
 */
final class ScoringConfiguration
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function weights(): Weights
    {
        try {
            return Weights::fromArray($this->settings->getArray(SettingKey::ScoringWeights));
        } catch (\InvalidArgumentException) {
            return Weights::defaults();
        }
    }

    public function parameters(): ScoringParameters
    {
        try {
            return new ScoringParameters(
                $this->settings->getInt(SettingKey::ScoringMinAgeDays),
                $this->settings->getInt(SettingKey::ScoringWindowDays),
                $this->settings->getInt(SettingKey::ScoringWarmupDays),
                $this->settings->getFloat(SettingKey::ScoringCtrFloorRatio),
                $this->settings->getFloat(SettingKey::ScoringRetentionTargetRatio),
                $this->settings->getInt(SettingKey::ScoringRelaunchCooldownDays),
            );
        } catch (\InvalidArgumentException) {
            return ScoringParameters::defaults();
        }
    }
}
