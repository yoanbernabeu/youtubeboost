<?php

declare(strict_types=1);

namespace App\YouTube\Api;

use App\YouTube\Exception\ApiCallFailedException;
use App\YouTube\Exception\AuthorizationRequiredException;
use App\YouTube\Exception\QuotaExhaustedException;
use App\YouTube\Exception\RateLimitedException;
use App\YouTube\OAuth\AccessTokenProviderInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Single place where an authenticated Google API call is made and its errors
 * are translated into the application's exceptions.
 *
 * Google signals very different situations with the same HTTP status, so the
 * decision is made on `error.errors[0].reason`, never on the status alone.
 */
final class GoogleApiRequester
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly AccessTokenProviderInterface $tokens,
    ) {
    }

    /**
     * @param array<string, scalar|array<array-key, scalar>> $query
     *
     * @return array<string, mixed>
     */
    public function getJson(string $url, array $query = []): array
    {
        $response = $this->send('GET', $url, ['query' => $query]);

        try {
            /** @var array<string, mixed> $payload */
            $payload = $response->toArray();
        } catch (HttpExceptionInterface $exception) {
            throw new ApiCallFailedException('Unexpected response from the Google API.', 0, null, $exception)->withUserMessage(new TranslatableMessage('youtube.error.unexpected_response'));
        }

        return $payload;
    }

    /**
     * @param array<string, scalar|array<array-key, scalar>> $query
     */
    public function getRaw(string $url, array $query = []): string
    {
        try {
            return $this->send('GET', $url, ['query' => $query])->getContent();
        } catch (HttpExceptionInterface $exception) {
            throw new ApiCallFailedException('Unexpected response from the Google API.', 0, null, $exception)->withUserMessage(new TranslatableMessage('youtube.error.unexpected_response'));
        }
    }

    /**
     * Uploads a binary body, as required by `thumbnails.set`.
     *
     * @param array<string, scalar> $query
     *
     * @return array<string, mixed>
     */
    public function postBinary(string $url, array $query, string $contentType, string $body): array
    {
        $response = $this->send('POST', $url, [
            'query' => $query,
            'headers' => ['Content-Type' => $contentType],
            'body' => $body,
        ]);

        try {
            /** @var array<string, mixed> $payload */
            $payload = $response->toArray();
        } catch (HttpExceptionInterface $exception) {
            throw new ApiCallFailedException('Unexpected response from the Google API.', 0, null, $exception)->withUserMessage(new TranslatableMessage('youtube.error.unexpected_response'));
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function postJson(string $url, array $body): array
    {
        $response = $this->send('POST', $url, ['json' => $body]);

        try {
            /** @var array<string, mixed> $payload */
            $payload = $response->toArray();
        } catch (HttpExceptionInterface $exception) {
            throw new ApiCallFailedException('Unexpected response from the Google API.', 0, null, $exception)->withUserMessage(new TranslatableMessage('youtube.error.unexpected_response'));
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function send(string $method, string $url, array $options): ResponseInterface
    {
        $headers = $options['headers'] ?? [];
        $options['headers'] = array_merge(
            ['Authorization' => 'Bearer ' . $this->tokens->getAccessToken()],
            \is_array($headers) ? $headers : [],
        );

        try {
            $response = $this->httpClient->request($method, $url, $options);
            $status = $response->getStatusCode();
        } catch (HttpExceptionInterface $exception) {
            throw new ApiCallFailedException(\sprintf('Calling %s %s failed.', $method, $url), 0, null, $exception)->withUserMessage(new TranslatableMessage('youtube.error.unreachable'));
        }

        if ($status < 400) {
            return $response;
        }

        throw $this->mapError($response, $status, $url);
    }

    private function mapError(ResponseInterface $response, int $status, string $url): \RuntimeException
    {
        try {
            /** @var array<string, mixed> $payload */
            $payload = $response->toArray(false);
        } catch (HttpExceptionInterface) {
            $payload = [];
        }

        $error = \is_array($payload['error'] ?? null) ? $payload['error'] : [];
        $errors = \is_array($error['errors'] ?? null) ? $error['errors'] : [];
        $first = \is_array($errors[0] ?? null) ? $errors[0] : [];
        $reason = \is_string($first['reason'] ?? null) ? $first['reason'] : null;
        $message = \is_string($error['message'] ?? null) ? $error['message'] : 'Unknown error';

        if (401 === $status) {
            $this->tokens->invalidate();

            return AuthorizationRequiredException::refreshFailed($reason ?? 'unauthorized');
        }

        if ('quotaExceeded' === $reason || 'dailyLimitExceeded' === $reason) {
            return new QuotaExhaustedException('The daily YouTube API quota is exhausted.')
                ->withUserMessage(new TranslatableMessage('youtube.error.quota_exhausted'));
        }

        if (429 === $status || \in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded', 'backendError'], true) || $status >= 500) {
            return new RateLimitedException(\sprintf('The YouTube API asked us to slow down (%s).', $reason ?? (string) $status))
                ->withUserMessage(new TranslatableMessage('youtube.error.rate_limited', ['%reason%' => $reason ?? (string) $status]));
        }

        return new ApiCallFailedException(
            \sprintf('%s failed: %s', $url, $message),
            $status,
            $reason,
        )->withUserMessage(new TranslatableMessage('youtube.error.call_failed', ['%message%' => $message]));
    }
}
