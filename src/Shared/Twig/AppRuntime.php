<?php

declare(strict_types=1);

namespace App\Shared\Twig;

use App\Scoring\Model\Signal;
use App\Settings\Settings;
use App\Shared\Time\IsoDuration;
use App\YouTube\Entity\Channel;
use App\YouTube\Repository\ChannelRepository;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * The handful of helpers the templates need.
 *
 * This is the view layer, so it translates and formats here rather than handing
 * back a `TranslatableMessage`: the templates call these as plain filters
 * (`{{ value|views }}`) and must be able to print the result as it comes.
 *
 * Numbers and percentages go through ICU rather than `number_format()`, because
 * the separators, the grouping and the space before the percent sign are not the
 * same in English and in French.
 */
final class AppRuntime implements RuntimeExtensionInterface
{
    /** Shown wherever a value is simply not known. */
    private const string NO_VALUE = '—';

    /** @var array<string, \NumberFormatter> */
    private array $formatters = [];

    public function __construct(
        private readonly ChannelRepository $channels,
        private readonly Settings $settings,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function connectedChannel(): ?Channel
    {
        return $this->channels->findConnected();
    }

    public function isOnboardingCompleted(): bool
    {
        return $this->settings->isOnboardingCompleted() && null !== $this->connectedChannel();
    }

    /**
     * Semantic colour band of a relaunch score.
     */
    public function scoreTone(int $score): string
    {
        return match (true) {
            $score >= 70 => 'hot',
            $score >= 45 => 'warm',
            $score >= 20 => 'muted',
            default => 'cold',
        };
    }

    public function signalLabel(Signal|string $signal): string
    {
        return $this->translator->trans(\sprintf('shared.signal.%s.label', $this->toSignal($signal)->value));
    }

    public function signalExplanation(Signal|string $signal): string
    {
        return $this->translator->trans(\sprintf('shared.signal.%s.explanation', $this->toSignal($signal)->value));
    }

    public function formatNumber(int|float|null $value): string
    {
        if (null === $value) {
            return self::NO_VALUE;
        }

        return $this->format((float) $value, \NumberFormatter::DECIMAL, 0);
    }

    public function formatDecimal(int|float|null $value, int $decimals = 1): string
    {
        if (null === $value) {
            return self::NO_VALUE;
        }

        return $this->format((float) $value, \NumberFormatter::DECIMAL, $decimals);
    }

    /**
     * The value is already expressed in percent, hence the division: ICU wants the
     * ratio, and gives back the sign, the separator and the spacing of the locale.
     */
    public function formatPercent(int|float|null $value, int $decimals = 1): string
    {
        if (null === $value) {
            return self::NO_VALUE;
        }

        return $this->format((float) $value / 100, \NumberFormatter::PERCENT, $decimals);
    }

    public function formatVariation(int|float|null $value, int $decimals = 0): string
    {
        if (null === $value) {
            return self::NO_VALUE;
        }

        // ICU writes the minus sign itself; a gain has to say so explicitly.
        return ($value > 0 ? '+' : '') . $this->formatPercent($value, $decimals);
    }

    public function formatDuration(?int $seconds): string
    {
        return null === $seconds ? self::NO_VALUE : IsoDuration::format($seconds);
    }

    /**
     * Age of a video, rounded the way a creator talks about it.
     *
     * The wording is the bare duration — "3 months", "3 mois" — because the
     * templates place it inside their own sentence.
     */
    public function formatAge(?\DateTimeInterface $date): string
    {
        if (null === $date) {
            return self::NO_VALUE;
        }

        $days = (int) $date->diff(new \DateTimeImmutable())->format('%a');

        if ($days < 1) {
            return $this->translator->trans('shared.age.today');
        }

        if ($days < 2) {
            return $this->translator->trans('shared.age.yesterday');
        }

        if ($days < 31) {
            return $this->translator->trans('shared.age.days', ['%count%' => $days]);
        }

        if ($days < 365) {
            $months = max(1, (int) round($days / 30.4));

            return $this->translator->trans('shared.age.months', ['%count%' => $months]);
        }

        // Rounded before the plural is chosen, so that the number the sentence
        // shows and the number that picked the sentence are the same one.
        $years = round($days / 365.25, 1);

        return $this->translator->trans('shared.age.years', [
            '%count%' => $years,
            '%value%' => $this->formatDecimal($years, 1),
        ]);
    }

    public function formatByteSize(?int $bytes): string
    {
        if (null === $bytes) {
            return self::NO_VALUE;
        }

        if ($bytes < 1024) {
            return $this->translator->trans('shared.byte_size.bytes', ['%size%' => $this->formatNumber($bytes)]);
        }

        if ($bytes < 1024 * 1024) {
            return $this->translator->trans('shared.byte_size.kilobytes', ['%size%' => $this->formatDecimal($bytes / 1024, 0)]);
        }

        return $this->translator->trans('shared.byte_size.megabytes', ['%size%' => $this->formatDecimal($bytes / (1024 * 1024), 1)]);
    }

    private function toSignal(Signal|string $signal): Signal
    {
        return $signal instanceof Signal ? $signal : (Signal::tryFrom($signal) ?? Signal::Decline);
    }

    /**
     * @param \NumberFormatter::DECIMAL|\NumberFormatter::PERCENT $style
     */
    private function format(float $value, int $style, int $decimals): string
    {
        $formatted = $this->formatter($style, $decimals)->format($value);

        // ICU only fails on values PHP cannot represent, such as NAN.
        return false === $formatted ? self::NO_VALUE : $formatted;
    }

    private function formatter(int $style, int $decimals): \NumberFormatter
    {
        $locale = $this->locale();
        $key = \sprintf('%s:%d:%d', $locale, $style, $decimals);

        if (!isset($this->formatters[$key])) {
            $formatter = new \NumberFormatter($locale, $style);
            $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
            $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $decimals);

            $this->formatters[$key] = $formatter;
        }

        return $this->formatters[$key];
    }

    /**
     * The locale of the current request, which the framework also mirrors into
     * ICU's own default, so a worker rendering outside a request still formats
     * with the configured language.
     */
    private function locale(): string
    {
        if ($this->translator instanceof LocaleAwareInterface) {
            return $this->translator->getLocale();
        }

        return \Locale::getDefault();
    }
}
