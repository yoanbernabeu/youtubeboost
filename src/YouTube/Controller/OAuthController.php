<?php

declare(strict_types=1);

namespace App\YouTube\Controller;

use App\YouTube\ChannelConnector;
use App\YouTube\Exception\YouTubeException;
use App\YouTube\OAuth\GoogleOAuthClient;
use App\YouTube\OAuth\PkcePair;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * The Google OAuth round trip.
 */
#[Route('/oauth')]
final class OAuthController extends AbstractController
{
    private const string SESSION_STATE = 'oauth_state';
    private const string SESSION_VERIFIER = 'oauth_code_verifier';

    public function __construct(
        private readonly GoogleOAuthClient $oauth,
        private readonly ChannelConnector $connector,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/connecter', name: 'app_oauth_connect', methods: ['GET'])]
    public function connect(Request $request): Response
    {
        if (!$this->oauth->isConfigured()) {
            $this->addFlash('error', new TranslatableMessage('youtube.flash.credentials_missing'));

            return $this->redirectToRoute('app_onboarding');
        }

        $state = bin2hex(random_bytes(16));
        $pkce = PkcePair::generate();

        $session = $request->getSession();
        $session->set(self::SESSION_STATE, $state);
        $session->set(self::SESSION_VERIFIER, $pkce->verifier);

        return $this->redirect($this->oauth->authorizationUrl($state, $pkce));
    }

    #[Route('/callback', name: 'app_oauth_callback', methods: ['GET'])]
    public function callback(Request $request): Response
    {
        $session = $request->getSession();
        $expectedState = $session->remove(self::SESSION_STATE);
        $verifier = $session->remove(self::SESSION_VERIFIER);

        $error = $request->query->get('error');
        if (\is_string($error) && '' !== $error) {
            $this->addFlash('error', new TranslatableMessage('youtube.flash.authorisation_refused', ['%error%' => $error]));

            return $this->redirectToRoute('app_onboarding');
        }

        $code = $request->query->get('code');
        $state = $request->query->get('state');

        if (!\is_string($code) || '' === $code || !\is_string($verifier)) {
            $this->addFlash('error', new TranslatableMessage('youtube.flash.incomplete_response'));

            return $this->redirectToRoute('app_onboarding');
        }

        if (!\is_string($expectedState) || !\is_string($state) || !hash_equals($expectedState, $state)) {
            $this->addFlash('error', new TranslatableMessage('youtube.flash.state_mismatch'));

            return $this->redirectToRoute('app_onboarding');
        }

        try {
            $channel = $this->connector->connect($this->oauth->exchangeAuthorizationCode($code, $verifier));
        } catch (YouTubeException $exception) {
            $this->logger->error('Connecting the channel failed.', ['exception' => $exception]);
            $this->addFlash('error', $exception->getUserMessage());

            return $this->redirectToRoute('app_onboarding');
        }

        $this->addFlash('success', new TranslatableMessage('youtube.flash.connected', ['%name%' => $channel->getTitle()]));

        return $this->redirectToRoute('app_onboarding', ['etape' => 'photos']);
    }

    #[Route('/deconnecter', name: 'app_oauth_disconnect', methods: ['POST'])]
    public function disconnect(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->connector->disconnect();
        $this->addFlash('success', new TranslatableMessage('youtube.flash.disconnected'));

        return $this->redirectToRoute('app_onboarding');
    }
}
