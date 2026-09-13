<?php

declare(strict_types=1);

namespace App\Catalog\Entity;

enum VideoType: string
{
    case Standard = 'standard';
    case Short = 'short';
    case Live = 'live';
}
