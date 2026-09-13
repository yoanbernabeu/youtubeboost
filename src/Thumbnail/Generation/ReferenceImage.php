<?php

declare(strict_types=1);

namespace App\Thumbnail\Generation;

use App\Settings\Entity\ReferenceAngle;

final readonly class ReferenceImage
{
    public function __construct(
        public string $binary,
        public string $mimeType,
        public ReferenceAngle $angle,
        public string $label,
    ) {
    }
}
