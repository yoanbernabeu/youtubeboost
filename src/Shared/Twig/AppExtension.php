<?php

declare(strict_types=1);

namespace App\Shared\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * The handful of helpers the templates need.
 *
 * Everything they render — numbers, percentages, ages, file sizes, signal
 * wording — follows the language the creator picked, so they all delegate to
 * the runtime rather than formatting anything themselves.
 */
final class AppExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('connected_channel', [AppRuntime::class, 'connectedChannel']),
            new TwigFunction('onboarding_completed', [AppRuntime::class, 'isOnboardingCompleted']),
            new TwigFunction('score_tone', [AppRuntime::class, 'scoreTone']),
            new TwigFunction('signal_label', [AppRuntime::class, 'signalLabel']),
            new TwigFunction('signal_explanation', [AppRuntime::class, 'signalExplanation']),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('views', [AppRuntime::class, 'formatNumber']),
            new TwigFilter('decimal', [AppRuntime::class, 'formatDecimal']),
            new TwigFilter('percent', [AppRuntime::class, 'formatPercent']),
            new TwigFilter('variation', [AppRuntime::class, 'formatVariation']),
            new TwigFilter('duration', [AppRuntime::class, 'formatDuration']),
            new TwigFilter('age', [AppRuntime::class, 'formatAge']),
            new TwigFilter('weight', [AppRuntime::class, 'formatByteSize']),
        ];
    }
}
