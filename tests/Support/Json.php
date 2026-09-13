<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Reads a value out of a JSON request or response body.
 *
 * Assertions on a decoded payload are naturally untyped; going through one dotted
 * path helper keeps the tests readable and keeps `mixed` in a single place.
 */
final class Json
{
    private function __construct()
    {
    }

    /**
     * Value at a dotted path, or null when any segment is missing.
     *
     * Numeric segments address list positions: `contents.0.parts.1.text`.
     */
    public static function get(string $raw, string $path): mixed
    {
        try {
            $cursor = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        foreach (explode('.', $path) as $segment) {
            if (!\is_array($cursor)) {
                return null;
            }

            $key = ctype_digit($segment) ? (int) $segment : $segment;
            if (!\array_key_exists($key, $cursor)) {
                return null;
            }

            $cursor = $cursor[$key];
        }

        return $cursor;
    }

    /**
     * Same as {@see self::get()}, narrowed to a string for message assertions.
     */
    public static function getString(string $raw, string $path): string
    {
        $value = self::get($raw, $path);

        return \is_scalar($value) ? (string) $value : '';
    }

    public static function isValid(string $raw): bool
    {
        try {
            json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        return true;
    }
}
