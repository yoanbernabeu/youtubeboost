<?php

declare(strict_types=1);

namespace App\Relaunch\Apply;

use App\Catalog\Entity\ThumbnailSet;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Downloads the thumbnail currently live on YouTube, in the best size available.
 *
 * `maxresdefault.jpg` is advertised even on videos that never had it, so the sizes
 * are tried from largest to smallest until one actually answers.
 */
final class CurrentThumbnailFetcher
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function fetch(ThumbnailSet $thumbnails): ?FetchedThumbnail
    {
        foreach ($this->urlsByDescendingSize($thumbnails) as $url) {
            try {
                $response = $this->httpClient->request('GET', $url);
                if (200 !== $response->getStatusCode()) {
                    continue;
                }

                $content = $response->getContent();
                if ('' === $content) {
                    continue;
                }

                $headers = $response->getHeaders(false);
                $contentType = $headers['content-type'][0] ?? 'image/jpeg';

                return new FetchedThumbnail($content, explode(';', $contentType)[0], $url);
            } catch (HttpExceptionInterface $exception) {
                $this->logger->info('A thumbnail size was not available.', ['url' => $url, 'exception' => $exception]);
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function urlsByDescendingSize(ThumbnailSet $thumbnails): array
    {
        $images = $thumbnails->toArray();
        uasort($images, static fn (array $a, array $b): int => $b['width'] <=> $a['width']);

        return array_values(array_map(static fn (array $image): string => $image['url'], $images));
    }
}
