<?php

declare(strict_types=1);

namespace App\YouTube\OAuth;

use App\YouTube\Exception\ApiCallFailedException;
use App\YouTube\Exception\AuthorizationRequiredException;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The Google OAuth 2.0 web server flow, limited to what this application needs.
 */
final class GoogleOAuthClient
{
    public const string AUTHORIZATION_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
    public const string TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    public const string REVOKE_ENDPOINT = 'https://oauth2.googleapis.com/revoke';

    /**
     * `youtube.force-ssl` already covers the read endpoints, but `youtube.readonly`
     * is kept so the consent screen spells out that nothing else is read.
     *
     * @var list<string>
     */
    public const array SCOPES = [
        'https://www.googleapis.com/auth/youtube.readonly',
        'https://www.googleapis.com/auth/yt-analytics.readonly',
        'https://www.googleapis.com/auth/youtube.force-ssl',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ClockInterface $clock,
        #[Autowire(env: 'GOOGLE_CLIENT_ID')]
        private readonly string $clientId,
        #[Autowire(env: 'GOOGLE_CLIENT_SECRET')]
        private readonly string $clientSecret,
        #[Autowire(env: 'GOOGLE_REDIRECT_URI')]
        private readonly string $redirectUri,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== $this->clientId && '' !== $this->clientSecret && '' !== $this->redirectUri;
    }

    public function getRedirectUri(): string
    {
        return $this->redirectUri;
    }

    /**
     * `access_type=offline` plus `prompt=consent` is what actually yields a
     * refresh token, including when the creator reconnects the same channel.
     */
    public function authorizationUrl(string $state, PkcePair $pkce): string
    {
        return self::AUTHORIZATION_ENDPOINT . '?' . http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
            'code_challenge' => $pkce->challenge,
            'code_challenge_method' => 'S256',
        ], '', '&', \PHP_QUERY_RFC3986);
    }

    public function exchangeAuthorizationCode(string $code, string $codeVerifier): OAuthTokens
    {
        $tokens = $this->requestTokens([
            'code' => $code,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUri,
            'grant_type' => 'authorization_code',
            'code_verifier' => $codeVerifier,
        ]);

        if (null === $tokens->refreshToken) {
            throw new ApiCallFailedException('Google returned no refresh token.')->withUserMessage(new TranslatableMessage('youtube.error.no_refresh_token'));
        }

        return $tokens;
    }

    /**
     * @throws AuthorizationRequiredException when Google refuses the refresh token
     */
    public function refreshAccessToken(string $refreshToken): OAuthTokens
    {
        try {
            return $this->requestTokens([
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ]);
        } catch (ApiCallFailedException $exception) {
            if (400 === $exception->statusCode || 401 === $exception->statusCode) {
                throw AuthorizationRequiredException::refreshFailed($exception->reason ?? 'invalid_grant');
            }

            throw $exception;
        }
    }

    public function revoke(string $token): void
    {
        try {
            $this->httpClient->request('POST', self::REVOKE_ENDPOINT, [
                'body' => ['token' => $token],
            ])->getStatusCode();
        } catch (HttpExceptionInterface) {
            // Revocation is best effort: a token Google already forgot is fine.
        }
    }

    /**
     * @param array<string, string> $body
     */
    private function requestTokens(array $body): OAuthTokens
    {
        try {
            $response = $this->httpClient->request('POST', self::TOKEN_ENDPOINT, ['body' => $body]);
            $statusCode = $response->getStatusCode();
            $payload = $response->toArray(false);
        } catch (HttpExceptionInterface $exception) {
            throw new ApiCallFailedException('Google could not be reached to obtain a token.', 0, null, $exception)->withUserMessage(new TranslatableMessage('youtube.error.token_unreachable'));
        }

        if ($statusCode >= 400) {
            $error = \is_string($payload['error'] ?? null) ? $payload['error'] : 'unknown_error';
            $description = \is_string($payload['error_description'] ?? null) ? $payload['error_description'] : '';

            throw new ApiCallFailedException(trim(\sprintf('Google refused the token request: %s %s', $error, $description)), $statusCode, $error)->withUserMessage(new TranslatableMessage('youtube.error.token_refused', ['%error%' => $error, '%description%' => $description]));
        }

        return OAuthTokens::fromTokenResponse($payload, \DateTimeImmutable::createFromInterface($this->clock->now()));
    }
}
