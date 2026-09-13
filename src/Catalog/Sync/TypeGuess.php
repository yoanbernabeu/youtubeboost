<?php

declare(strict_types=1);

namespace App\Catalog\Sync;

use App\Catalog\Entity\TypeSource;
use App\Catalog\Entity\VideoType;

final readonly class TypeGuess
{
    public function __construct(
        public VideoType $type,
        public TypeSource $source,
    ) {
    }
}
