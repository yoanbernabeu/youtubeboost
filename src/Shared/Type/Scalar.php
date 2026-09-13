<?php

declare(strict_types=1);

namespace App\Shared\Type;

/**
 * Narrows values coming from JSON payloads, SQL rows and request bodies.
 *
 * These three sources all hand back `mixed`; reading them through one place keeps
 * the guard explicit instead of scattering casts that hide a wrong assumption.
 */
final class Scalar
{
    private function __construct()
    {
    }

    public static function int(mixed $value, int $default = 0): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    public static function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    public static function float(mixed $value, float $default = 0.0): float
    {
        return is_numeric($value) ? (float) $value : $default;
    }

    public static function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    public static function string(mixed $value, string $default = ''): string
    {
        return \is_scalar($value) ? (string) $value : $default;
    }

    public static function nullableString(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }

    public static function bool(mixed $value, bool $default = false): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        if (\is_scalar($value)) {
            return filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? $default;
        }

        return $default;
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function array(mixed $value): array
    {
        return \is_array($value) ? $value : [];
    }
}
