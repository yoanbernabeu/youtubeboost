<?php

declare(strict_types=1);

namespace App\Settings\Storage;

use App\Shared\Storage\AbstractFileStore;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Where the reference photos of the creator live.
 */
final class ReferencePhotoStore extends AbstractFileStore
{
    public function __construct(
        #[Autowire(service: 'references.storage')]
        FilesystemOperator $filesystem,
    ) {
        parent::__construct($filesystem);
    }
}
