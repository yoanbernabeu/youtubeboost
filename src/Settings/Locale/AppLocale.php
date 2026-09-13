<?php

declare(strict_types=1);

namespace App\Settings\Locale;

/**
 * The languages the application ships with.
 *
 * Adding a case here is not enough on its own: the matching
 * `translations/messages.<locale>.yaml` and `translations/validators.<locale>.yaml`
 * have to exist, and `framework.enabled_locales` has to list the code.
 */
enum AppLocale: string
{
    case English = 'en';
    case French = 'fr';

    public static function default(): self
    {
        return self::English;
    }

    /**
     * Falls back on the default rather than throwing: the value comes from a
     * settings row, and a row left over from an older version must not take the
     * whole application down.
     */
    public static function fromCode(?string $code): self
    {
        return self::tryFrom(trim((string) $code)) ?? self::default();
    }

    /**
     * The name of the language, written in that language, for the switcher.
     */
    public function nativeName(): string
    {
        return match ($this) {
            self::English => 'English',
            self::French => 'Français',
        };
    }

    /**
     * The English name of the language, which is what a language model
     * understands best when it is told which language to write in.
     */
    public function englishName(): string
    {
        return match ($this) {
            self::English => 'English',
            self::French => 'French',
        };
    }
}
