<?php

declare(strict_types=1);

namespace App\Shared\Controller;

use App\Relaunch\Storage\ArchiveStore;
use App\Settings\Storage\ReferencePhotoStore;
use App\Shared\Storage\AbstractFileStore;
use App\Shared\Storage\StorageFailedException;
use App\Thumbnail\Storage\ThumbnailStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Serves the stored images.
 *
 * Generated thumbnails, reference photos and archived thumbnails deliberately
 * live outside public/, so that the firewall protects them like any other page.
 */
final class ImageController extends AbstractController
{
    private const string KIND_THUMBNAIL = 'miniature';
    private const string KIND_REFERENCE = 'reference';
    private const string KIND_ARCHIVE = 'archive';

    public function __construct(
        private readonly ThumbnailStore $thumbnails,
        private readonly ReferencePhotoStore $references,
        private readonly ArchiveStore $archive,
    ) {
    }

    #[Route('/images/{kind}/{path}', name: 'app_image', requirements: ['kind' => 'miniature|reference|archive', 'path' => Requirement::CATCH_ALL], methods: ['GET'])]
    public function show(string $kind, string $path, #[MapQueryParameter] bool $download = false): Response
    {
        $store = $this->storeFor($kind);

        try {
            $binary = $store->read($path);
        } catch (StorageFailedException) {
            throw $this->createNotFoundException('Image not found.');
        }

        $response = new Response($binary, Response::HTTP_OK, [
            'Content-Type' => self::mimeTypeOf($path),
            // Generated files never change, so the browser may keep them, but only
            // in a private cache: they are behind the firewall.
            'Cache-Control' => 'private, max-age=604800',
        ]);

        if ($download) {
            $response->headers->set('Content-Disposition', \sprintf('attachment; filename="%s"', basename($path)));
        }

        return $response;
    }

    private function storeFor(string $kind): AbstractFileStore
    {
        return match ($kind) {
            self::KIND_THUMBNAIL => $this->thumbnails,
            self::KIND_REFERENCE => $this->references,
            self::KIND_ARCHIVE => $this->archive,
            default => throw $this->createNotFoundException('Unknown image kind.'),
        };
    }

    private static function mimeTypeOf(string $path): string
    {
        return match (strtolower(pathinfo($path, \PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'image/jpeg',
        };
    }
}
