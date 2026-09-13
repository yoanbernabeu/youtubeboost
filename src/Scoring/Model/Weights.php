<?php

declare(strict_types=1);

namespace App\Scoring\Model;

/**
 * Immutable set of user-tunable signal weights.
 *
 * Weights are relative: the score normalises them, so the sum is free.
 */
final readonly class Weights
{
    /**
     * @param array<non-empty-string, float> $values indexed by {@see Signal} value
     */
    private function __construct(private array $values)
    {
    }

    public static function defaults(): self
    {
        $values = [];
        foreach (Signal::cases() as $signal) {
            $values[$signal->value] = $signal->defaultWeight();
        }

        return new self($values);
    }

    /**
     * Builds a set from a partial map, falling back on the default weight of
     * every signal the map does not mention. Unknown keys are ignored.
     *
     * @param array<array-key, mixed> $values
     *
     * @throws \InvalidArgumentException when a weight is negative or all weights are zero
     */
    public static function fromArray(array $values): self
    {
        $normalised = [];
        foreach (Signal::cases() as $signal) {
            $raw = $values[$signal->value] ?? $signal->defaultWeight();

            if (!is_numeric($raw)) {
                throw new \InvalidArgumentException(\sprintf('Weight for signal "%s" must be numeric, got "%s".', $signal->value, get_debug_type($raw)));
            }

            $weight = (float) $raw;
            if ($weight < 0) {
                throw new \InvalidArgumentException(\sprintf('Weight for signal "%s" cannot be negative.', $signal->value));
            }

            $normalised[$signal->value] = $weight;
        }

        if (0.0 === array_sum($normalised)) {
            throw new \InvalidArgumentException('At least one signal weight must be greater than zero.');
        }

        return new self($normalised);
    }

    public function get(Signal $signal): float
    {
        return $this->values[$signal->value];
    }

    public function total(): float
    {
        return array_sum($this->values);
    }

    /**
     * @return array<non-empty-string, float>
     */
    public function toArray(): array
    {
        return $this->values;
    }
}
