<?php

declare(strict_types=1);

namespace App\Settings\Input;

use App\Settings\SettingKey;

/**
 * The models, the request pacing and the verdict thresholds.
 */
final readonly class GenerationSettingsInput
{
    public const int DELAY_MIN = 0;
    public const int DELAY_MAX = 5000;

    private function __construct(
        public string $textModel,
        public string $imageModel,
        public int $analyticsRequestDelayMs,
        public float $successViewsRatio,
        public float $neutralFloorRatio,
        public float $negativeCtrRatio,
    ) {
    }

    /**
     * @param array<array-key, mixed> $values
     */
    public static function fromArray(array $values): self
    {
        $floor = self::clampFloat($values['neutralFloorRatio'] ?? null, 0.1, 0.99, 0.8);
        // The success threshold has to stay above the neutral floor or no result
        // could ever be neutral.
        $success = self::clampFloat($values['successViewsRatio'] ?? null, $floor + 0.05, 10.0, 1.5);

        return new self(
            self::cleanModel($values['textModel'] ?? null),
            self::cleanModel($values['imageModel'] ?? null),
            self::clampInt($values['analyticsRequestDelayMs'] ?? null, self::DELAY_MIN, self::DELAY_MAX, 250),
            $success,
            $floor,
            self::clampFloat($values['negativeCtrRatio'] ?? null, 0.1, 1.0, 0.8),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toSettings(): array
    {
        return [
            SettingKey::GeminiTextModel->value => $this->textModel,
            SettingKey::GeminiImageModel->value => $this->imageModel,
            SettingKey::AnalyticsRequestDelayMs->value => $this->analyticsRequestDelayMs,
            SettingKey::VerdictSuccessViewsRatio->value => $this->successViewsRatio,
            SettingKey::VerdictNeutralFloorRatio->value => $this->neutralFloorRatio,
            SettingKey::VerdictNegativeCtrRatio->value => $this->negativeCtrRatio,
        ];
    }

    /**
     * An empty model name means "use the environment default", so it is kept empty.
     */
    private static function cleanModel(mixed $value): string
    {
        if (!\is_string($value)) {
            return '';
        }

        $clean = preg_replace('/[^a-zA-Z0-9._-]/', '', trim($value)) ?? '';

        return mb_substr($clean, 0, 64);
    }

    private static function clampInt(mixed $value, int $min, int $max, int $fallback): int
    {
        return is_numeric($value) ? max($min, min($max, (int) $value)) : $fallback;
    }

    private static function clampFloat(mixed $value, float $min, float $max, float $fallback): float
    {
        return is_numeric($value) ? max($min, min($max, round((float) $value, 3))) : $fallback;
    }
}
