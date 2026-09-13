<?php

declare(strict_types=1);

namespace App\Scoring\Model;

/**
 * Immutable collection of signal values, where null means "no data".
 *
 * Missing signals are neutralised by the score instead of being counted as zero,
 * so that an old video without impression data is not unfairly penalised.
 */
final readonly class SignalSet
{
    /**
     * @param array<non-empty-string, float|null> $values indexed by {@see Signal} value
     */
    private function __construct(private array $values)
    {
    }

    public static function empty(): self
    {
        $values = [];
        foreach (Signal::cases() as $signal) {
            $values[$signal->value] = null;
        }

        return new self($values);
    }

    /**
     * @param array<array-key, mixed> $values
     */
    public static function fromArray(array $values): self
    {
        $set = self::empty();
        foreach (Signal::cases() as $signal) {
            $raw = $values[$signal->value] ?? null;
            if (is_numeric($raw)) {
                $set = $set->with($signal, (float) $raw);
            }
        }

        return $set;
    }

    public function with(Signal $signal, ?float $value): self
    {
        $values = $this->values;
        $values[$signal->value] = null === $value ? null : max(0.0, min(1.0, $value));

        return new self($values);
    }

    public function get(Signal $signal): ?float
    {
        return $this->values[$signal->value];
    }

    public function has(Signal $signal): bool
    {
        return null !== $this->values[$signal->value];
    }

    /**
     * @return array<non-empty-string, float|null>
     */
    public function toArray(): array
    {
        return $this->values;
    }
}
