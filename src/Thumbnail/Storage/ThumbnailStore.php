<?php

declare(strict_types=1);

namespace App\Thumbnail\Storage;

use App\Shared\Storage\AbstractFileStore;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Where the generated thumbnails live.
 */
final class ThumbnailStore extends AbstractFileStore
{
    public function __construct(
        #[Autowire(service: 'thumbnails.storage')]
        FilesystemOperator $filesystem,
    ) {
        parent::__construct($filesystem);
    }
}
