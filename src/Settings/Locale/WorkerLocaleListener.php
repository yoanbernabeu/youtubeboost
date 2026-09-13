<?php

declare(strict_types=1);

namespace App\Settings\Locale;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Gives the worker the same language as the interface.
 *
 * A handler has no request to read a locale from, yet it writes text the creator
 * reads: the step of a running job, the message of a failure. Without this the
 * worker would answer in the default language whatever the setting says.
 */
#[AsEventListener(event: WorkerMessageReceivedEvent::class)]
final class WorkerLocaleListener
{
    public function __construct(
        private readonly LocaleResolver $locales,
        #[Autowire(service: 'translation.locale_switcher')]
        private readonly LocaleSwitcher $localeSwitcher,
    ) {
    }

    public function __invoke(WorkerMessageReceivedEvent $event): void
    {
        $this->locales->reset();
        $this->localeSwitcher->setLocale($this->locales->current()->value);
    }
}
