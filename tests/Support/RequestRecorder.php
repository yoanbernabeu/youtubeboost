<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Records what a mocked HTTP client was asked to send.
 *
 * Clearer than a by-reference capture, and it keeps the recorded shape typed.
 */
final class RequestRecorder
{
    /** @var list<array{method: string, url: string, body: string}> */
    private array $requests = [];

    /**
     * @param list<MockResponse> $responses replayed in order
     */
    public function client(array $responses): MockHttpClient
    {
        $queue = $responses;

        return new MockHttpClient(function (string $method, string $url, array $options) use (&$queue): ResponseInterface {
            $body = $options['body'] ?? '';
            $this->requests[] = [
                'method' => $method,
                'url' => $url,
                'body' => \is_string($body) ? $body : '',
            ];

            return array_shift($queue) ?? new MockResponse('', ['http_code' => 500]);
        });
    }

    public function count(): int
    {
        return \count($this->requests);
    }

    public function url(int $index = 0): string
    {
        return $this->requests[$index]['url'] ?? '';
    }

    public function body(int $index = 0): string
    {
        return $this->requests[$index]['body'] ?? '';
    }

    public function method(int $index = 0): string
    {
        return $this->requests[$index]['method'] ?? '';
    }
}
