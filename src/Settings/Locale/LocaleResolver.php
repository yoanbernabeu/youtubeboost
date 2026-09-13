<?php

declare(strict_types=1);

namespace App\Settings\Locale;

use App\Settings\SettingKey;
use App\Settings\Settings;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The single source of truth for the language of the application.
 *
 * The choice lives in the settings rather than in the URL: the tool has one user,
 * and prefixing every route with a locale would buy nothing. Reading it here means
 * the web request and the worker agree on the same answer.
 */
final class LocaleResolver implements ResetInterface
{
    private ?AppLocale $resolved = null;

    public function __construct(
        private readonly Settings $settings,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.default_locale%')]
        private readonly string $defaultLocale = 'en',
    ) {
    }

    public function current(): AppLocale
    {
        if (null !== $this->resolved) {
            return $this->resolved;
        }

        try {
            $stored = $this->settings->getString(SettingKey::Locale);
        } catch (\Throwable $exception) {
            // Before the first migration the settings table does not exist yet,
            // and a request must still be able to render the error page.
            $this->logger->debug('Could not read the locale setting.', ['exception' => $exception]);
            $stored = '';
        }

        return $this->resolved = '' !== $stored
            ? AppLocale::fromCode($stored)
            : AppLocale::fromCode($this->defaultLocale);
    }

    public function change(AppLocale $locale): void
    {
        $this->settings->set(SettingKey::Locale, $locale->value);
        $this->resolved = $locale;
    }

    /**
     * Drops the memoised value so a long-running worker picks up a change made
     * from the interface between two messages.
     */
    public function reset(): void
    {
        $this->resolved = null;
    }
}
