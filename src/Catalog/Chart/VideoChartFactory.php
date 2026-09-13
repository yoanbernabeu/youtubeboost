<?php

declare(strict_types=1);

namespace App\Catalog\Chart;

use App\Catalog\Model\DailyStatSeries;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

/**
 * Builds the charts shown on a video and on a relaunch.
 *
 * Chart.js reads its labels from a JSON payload rendered server-side, so this is
 * one of the few places that resolves a message instead of handing a
 * `TranslatableMessage` to the template.
 */
final class VideoChartFactory
{
    public function __construct(
        private readonly ChartBuilderInterface $charts,
        private readonly ViewsSeriesBuilder $seriesBuilder,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function lifetimeViews(DailyStatSeries $history, ?\DateTimeImmutable $until = null): ?Chart
    {
        $series = $this->seriesBuilder->build($history, $until);
        if ($series->isEmpty()) {
            return null;
        }

        $chart = $this->charts->createChart(Chart::TYPE_LINE);
        $chart->setData([
            'labels' => array_map(self::shortDate(...), $series->labels),
            'datasets' => [
                [
                    'label' => $this->render($series->viewsLabel()),
                    'data' => $series->views,
                    'borderColor' => 'rgb(120, 94, 230)',
                    'backgroundColor' => 'rgba(120, 94, 230, 0.12)',
                    'borderWidth' => 1.5,
                    'fill' => true,
                    'pointRadius' => 0,
                    'pointHitRadius' => 8,
                    'tension' => 0.25,
                ],
                [
                    'label' => $this->render($series->trendLabel()),
                    'data' => $series->trend,
                    'borderColor' => 'rgb(225, 110, 70)',
                    'borderWidth' => 2,
                    'borderDash' => [4, 4],
                    'fill' => false,
                    'pointRadius' => 0,
                    'spanGaps' => false,
                ],
            ],
        ]);
        $chart->setOptions(self::commonOptions());

        return $chart;
    }

    /**
     * Before and after a thumbnail change, as two bars per metric.
     *
     * @param array<string, array{before: float|null, after: float|null}> $metrics
     */
    public function beforeAfter(array $metrics): ?Chart
    {
        $labels = [];
        $before = [];
        $after = [];

        foreach ($metrics as $label => $values) {
            if (null === $values['before'] || null === $values['after']) {
                continue;
            }

            $labels[] = $label;
            // Normalised so metrics of very different magnitudes share one axis.
            $before[] = 100.0;
            $after[] = $values['before'] > 0.0 ? round(100 * $values['after'] / $values['before'], 1) : 0.0;
        }

        if ([] === $labels) {
            return null;
        }

        $chart = $this->charts->createChart(Chart::TYPE_BAR);
        $chart->setData([
            'labels' => $labels,
            'datasets' => [
                ['label' => $this->render(new TranslatableMessage('video.chart.before')), 'data' => $before, 'backgroundColor' => 'rgba(140, 140, 160, 0.45)'],
                ['label' => $this->render(new TranslatableMessage('video.chart.after')), 'data' => $after, 'backgroundColor' => 'rgba(120, 94, 230, 0.75)'],
            ],
        ]);
        $chart->setOptions([
            'responsive' => true,
            'maintainAspectRatio' => false,
            'plugins' => ['legend' => ['position' => 'bottom', 'labels' => ['boxWidth' => 12, 'usePointStyle' => true]]],
            'scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['callback' => null]]],
        ]);

        return $chart;
    }

    private function render(TranslatableInterface $label): string
    {
        return $label->trans($this->translator);
    }

    /**
     * @return array<string, mixed>
     */
    private static function commonOptions(): array
    {
        return [
            'responsive' => true,
            'maintainAspectRatio' => false,
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'plugins' => [
                'legend' => ['position' => 'bottom', 'labels' => ['boxWidth' => 12, 'usePointStyle' => true]],
                'tooltip' => ['displayColors' => true],
            ],
            'scales' => [
                'x' => ['grid' => ['display' => false], 'ticks' => ['maxTicksLimit' => 10, 'autoSkip' => true]],
                'y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]],
            ],
        ];
    }

    private static function shortDate(string $isoDate): string
    {
        return substr($isoDate, 8, 2) . '/' . substr($isoDate, 5, 2) . '/' . substr($isoDate, 2, 2);
    }
}
