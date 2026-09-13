<?php

declare(strict_types=1);

namespace App\Relaunch\Storage;

use App\Shared\Storage\AbstractFileStore;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Where the thumbnails replaced on YouTube are kept, so a relaunch can be undone.
 */
final class ArchiveStore extends AbstractFileStore
{
    public function __construct(
        #[Autowire(service: 'archive.storage')]
        FilesystemOperator $filesystem,
    ) {
        parent::__construct($filesystem);
    }
}
