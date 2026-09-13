<?php

declare(strict_types=1);

namespace App\Analysis\Model;

/**
 * What the language model came back with.
 */
final readonly class AnalysisPlan
{
    /**
     * @param list<ThumbnailAngle> $angles
     */
    public function __construct(
        public string $summary,
        public string $promise,
        public array $angles,
        public string $model,
    ) {
    }
}
