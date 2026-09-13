<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\YouTube\Entity\Channel;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Channel>
 */
final class ChannelFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Channel::class;
    }

    protected function defaults(): array|callable
    {
        return [
            'youtubeId' => 'UC' . bin2hex(random_bytes(8)),
            'title' => 'Ma chaîne',
            'uploadsPlaylistId' => 'UU' . bin2hex(random_bytes(8)),
            'encryptedRefreshToken' => 'encrypted-refresh-token',
            'scopes' => ['https://www.googleapis.com/auth/youtube.readonly'],
            'connectedAt' => new \DateTimeImmutable('-1 month'),
        ];
    }
}
