<?php

declare(strict_types=1);

namespace App\Settings\Locale;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Puts the language chosen in the settings on every request.
 *
 * The priority sits just above Symfony's own locale listener (16), which only
 * overrides the locale when the route carries a `_locale` attribute — none does
 * here. Setting it this early leaves `LocaleAwareListener` (15) free to hand the
 * same locale to the translator.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 17)]
final class LocaleListener
{
    public function __construct(
        private readonly LocaleResolver $locales,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $event->getRequest()->setLocale($this->locales->current()->value);
    }
}
