<?php

declare(strict_types=1);

namespace App\YouTube\Api;

/**
 * Column-name based access to an Analytics API result table.
 *
 * Google does not guarantee that the response columns follow the order of the
 * requested metrics, so values are never read by position.
 */
final readonly class ResultTable
{
    /**
     * @param array<string, int> $columns column name to index
     * @param list<list<mixed>>  $rows
     */
    private function __construct(
        private array $columns,
        private array $rows,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload): self
    {
        $columns = [];
        $headers = $payload['columnHeaders'] ?? [];
        if (\is_array($headers)) {
            $index = 0;
            foreach ($headers as $header) {
                if (\is_array($header) && \is_string($header['name'] ?? null)) {
                    $columns[$header['name']] = $index;
                }
                ++$index;
            }
        }

        $rows = [];
        $rawRows = $payload['rows'] ?? [];
        if (\is_array($rawRows)) {
            foreach ($rawRows as $row) {
                if (\is_array($row)) {
                    $rows[] = array_values($row);
                }
            }
        }

        return new self($columns, $rows);
    }

    /**
     * @return list<list<mixed>>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    public function isEmpty(): bool
    {
        return [] === $this->rows;
    }

    /**
     * @param list<mixed> $row
     */
    public function string(array $row, string $column): ?string
    {
        $value = $this->value($row, $column);

        return \is_scalar($value) ? (string) $value : null;
    }

    /**
     * @param list<mixed> $row
     */
    public function float(array $row, string $column): ?float
    {
        $value = $this->value($row, $column);

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @param list<mixed> $row
     */
    private function value(array $row, string $column): mixed
    {
        $index = $this->columns[$column] ?? null;

        return null === $index ? null : ($row[$index] ?? null);
    }
}
