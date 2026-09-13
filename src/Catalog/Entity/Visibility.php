<?php

declare(strict_types=1);

namespace App\Catalog\Entity;

enum Visibility: string
{
    case Public = 'public';
    case Unlisted = 'unlisted';
    case Private = 'private';

    public static function fromPrivacyStatus(string $status): self
    {
        return match ($status) {
            'public' => self::Public,
            'unlisted' => self::Unlisted,
            default => self::Private,
        };
    }
}
