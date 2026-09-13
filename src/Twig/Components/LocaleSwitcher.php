<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Settings\Locale\AppLocale;
use App\Settings\Locale\LocaleResolver;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The language picker shown in the sidebar.
 *
 * The application has one user and no locale in its URLs, so switching language
 * is a setting write, not a navigation.
 */
#[AsTwigComponent]
final class LocaleSwitcher
{
    public function __construct(private readonly LocaleResolver $locales)
    {
    }

    /**
     * @return list<AppLocale>
     */
    public function getAvailable(): array
    {
        return AppLocale::cases();
    }

    public function getCurrent(): AppLocale
    {
        return $this->locales->current();
    }
}
