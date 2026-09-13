<?php

declare(strict_types=1);

namespace App\Catalog\Chart;

use Symfony\Component\Translation\TranslatableMessage;

final readonly class ViewsSeries
{
    /**
     * @param list<string>     $labels ISO dates
     * @param list<int>        $views
     * @param list<float|null> $trend  moving average, null while the window is incomplete
     */
    public function __construct(
        public array $labels,
        public array $views,
        public array $trend,
        public bool $isWeekly,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->labels;
    }

    public function viewsLabel(): TranslatableMessage
    {
        return new TranslatableMessage($this->isWeekly ? 'video.chart.views_per_week' : 'video.chart.views_per_day');
    }

    public function trendLabel(): TranslatableMessage
    {
        return new TranslatableMessage('video.chart.trend');
    }
}
