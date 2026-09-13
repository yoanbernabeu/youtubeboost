<?php

declare(strict_types=1);

namespace App\Settings\EventListener;

use App\Settings\Settings;
use App\YouTube\Repository\ChannelRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Keeps the creator on the onboarding assistant until it is finished.
 *
 * Nothing in the application works without a connected channel and at least the
 * three reference photos, so every other page would just show an empty state.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
final readonly class OnboardingGuard
{
    /**
     * Routes that have to stay reachable while onboarding is unfinished.
     *
     * @var list<string>
     */
    private const array ALLOWED_PREFIXES = [
        'app_onboarding',
        'app_oauth',
        'app_login',
        'app_logout',
        'app_image',
        '_',
    ];

    public function __construct(
        private Settings $settings,
        private ChannelRepository $channels,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $route = $event->getRequest()->attributes->getString('_route');
        if ('' === $route || $this->isAllowed($route)) {
            return;
        }

        if ($this->settings->isOnboardingCompleted() && null !== $this->channels->findConnected()) {
            return;
        }

        $event->setResponse(new RedirectResponse($this->urls->generate('app_onboarding')));
    }

    private function isAllowed(string $route): bool
    {
        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($route, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
