<?php

declare(strict_types=1);

namespace App\Settings;

/**
 * Every persisted setting, with its default value.
 *
 * Keeping the list in one enum means the settings form, the defaults and the
 * readers can never drift apart.
 */
enum SettingKey: string
{
    case ScoringWeights = 'scoring.weights';
    case ScoringMinAgeDays = 'scoring.min_age_days';
    case ScoringWindowDays = 'scoring.window_days';
    case ScoringWarmupDays = 'scoring.warmup_days';
    case ScoringCtrFloorRatio = 'scoring.ctr_floor_ratio';
    case ScoringRetentionTargetRatio = 'scoring.retention_target_ratio';
    case ScoringRelaunchCooldownDays = 'scoring.relaunch_cooldown_days';
    case StyleGuidelines = 'style.guidelines';
    case GeminiTextModel = 'gemini.text_model';
    case GeminiImageModel = 'gemini.image_model';
    case GeminiImageConfigStyle = 'gemini.image_config_style';
    case AnalyticsRequestDelayMs = 'analytics.request_delay_ms';
    case VerdictSuccessViewsRatio = 'verdict.success_views_ratio';
    case VerdictNeutralFloorRatio = 'verdict.neutral_floor_ratio';
    case VerdictNegativeCtrRatio = 'verdict.negative_ctr_ratio';
    case OnboardingCompletedAt = 'onboarding.completed_at';
    case Locale = 'app.locale';

    /**
     * Value used as long as the creator did not change anything.
     *
     * Keys whose default comes from an environment variable answer null: their
     * reader injects the environment value instead.
     */
    public function defaultValue(): mixed
    {
        return match ($this) {
            self::ScoringWeights => null,
            self::ScoringMinAgeDays => 60,
            self::ScoringWindowDays => 28,
            self::ScoringWarmupDays => 30,
            self::ScoringCtrFloorRatio => 0.5,
            self::ScoringRetentionTargetRatio => 2.0,
            self::ScoringRelaunchCooldownDays => 28,
            self::StyleGuidelines => '',
            self::GeminiTextModel, self::GeminiImageModel, self::GeminiImageConfigStyle, self::OnboardingCompletedAt, self::Locale => null,
            self::AnalyticsRequestDelayMs => 250,
            self::VerdictSuccessViewsRatio => 1.5,
            self::VerdictNeutralFloorRatio => 0.8,
            self::VerdictNegativeCtrRatio => 0.8,
        };
    }
}
