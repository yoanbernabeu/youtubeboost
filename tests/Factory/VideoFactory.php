<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Catalog\Entity\ThumbnailSet;
use App\Catalog\Entity\Video;
use App\Catalog\Entity\Visibility;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Video>
 */
final class VideoFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Video::class;
    }

    protected function defaults(): array|callable
    {
        return [
            'youtubeId' => substr(bin2hex(random_bytes(8)), 0, 11),
            'title' => self::faker()->sentence(4),
            'description' => self::faker()->paragraph(),
            'publishedAt' => new \DateTimeImmutable('-2 years'),
            'durationSeconds' => self::faker()->numberBetween(300, 2400),
            'visibility' => Visibility::Public,
            'thumbnails' => ThumbnailSet::fromApiPayload([
                'high' => ['url' => 'https://i.ytimg.com/vi/x/hqdefault.jpg', 'width' => 480, 'height' => 360],
                'maxres' => ['url' => 'https://i.ytimg.com/vi/x/maxresdefault.jpg', 'width' => 1280, 'height' => 720],
            ]),
        ];
    }
}
