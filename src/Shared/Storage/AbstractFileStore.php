<?php

declare(strict_types=1);

namespace App\Shared\Storage;

use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;

/**
 * Stores binary files on a Flysystem filesystem, under a readable path.
 *
 * Images never live under public/: they are served by a controller so that the
 * firewall applies to them like to everything else.
 */
abstract class AbstractFileStore
{
    public function __construct(private readonly FilesystemOperator $filesystem)
    {
    }

    /**
     * @param string $scope  grouping directory, typically a video identifier
     * @param string $suffix appended to the generated name, for readability
     *
     * @throws StorageFailedException
     */
    public function write(string $binary, string $scope, string $suffix = '', string $extension = 'png'): string
    {
        $path = $this->buildPath($scope, $suffix, $extension);

        try {
            $this->filesystem->write($path, $binary);
        } catch (FilesystemException $exception) {
            throw StorageFailedException::cannotWrite($path, $exception);
        }

        return $path;
    }

    /**
     * @throws StorageFailedException
     */
    public function read(string $path): string
    {
        try {
            return $this->filesystem->read($path);
        } catch (FilesystemException $exception) {
            throw StorageFailedException::cannotRead($path, $exception);
        }
    }

    public function exists(string $path): bool
    {
        try {
            return $this->filesystem->fileExists($path);
        } catch (FilesystemException) {
            return false;
        }
    }

    public function delete(string $path): void
    {
        try {
            $this->filesystem->delete($path);
        } catch (FilesystemException) {
            // A file that is already gone is not a problem.
        }
    }

    public function size(string $path): int
    {
        try {
            return $this->filesystem->fileSize($path);
        } catch (FilesystemException) {
            return 0;
        }
    }

    private function buildPath(string $scope, string $suffix, string $extension): string
    {
        $directory = self::slug($scope);
        $safeExtension = self::slug($extension);

        $parts = array_filter([
            new \DateTimeImmutable()->format('Ymd-His'),
            self::slug($suffix),
            bin2hex(random_bytes(4)),
        ], static fn (string $part): bool => '' !== $part);

        return \sprintf(
            '%s/%s.%s',
            '' === $directory ? 'misc' : $directory,
            implode('-', $parts),
            '' === $safeExtension ? 'bin' : $safeExtension,
        );
    }

    private static function slug(string $value): string
    {
        return trim(preg_replace('/[^A-Za-z0-9_-]+/', '-', $value) ?? '', '-');
    }
}
