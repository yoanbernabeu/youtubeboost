<?php

declare(strict_types=1);

namespace App\YouTube\Api;

use App\YouTube\Api\Dto\ReachRow;

/**
 * Parses a `channel_reach_basic_a1` CSV report.
 *
 * The CTR column is documented as a ratio, but bulk reports have been seen with
 * percentages. The scale is therefore decided per file: if no value exceeds 1,
 * the column holds ratios and is converted to percentages. A uniform scale
 * mistake would not change the scoring anyway, since the CTR signal only
 * compares a video to the channel median.
 */
final class ReachReportParser
{
    private const string COLUMN_DATE = 'date';
    private const string COLUMN_VIDEO = 'video_id';
    private const string COLUMN_IMPRESSIONS = 'video_thumbnail_impressions';
    private const string COLUMN_CTR = 'video_thumbnail_impressions_ctr';

    /**
     * @return list<ReachRow>
     */
    public function parse(string $csv): array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($csv));
        if (false === $lines || \count($lines) < 2) {
            return [];
        }

        $columns = self::indexColumns(str_getcsv(array_shift($lines), ',', '"', '\\'));
        foreach ([self::COLUMN_DATE, self::COLUMN_VIDEO, self::COLUMN_IMPRESSIONS, self::COLUMN_CTR] as $required) {
            if (!isset($columns[$required])) {
                return [];
            }
        }

        $parsed = [];
        $maxRate = 0.0;
        foreach ($lines as $line) {
            if ('' === trim($line)) {
                continue;
            }

            $fields = str_getcsv($line, ',', '"', '\\');
            $videoId = self::field($fields, $columns, self::COLUMN_VIDEO);
            $date = self::parseDate(self::field($fields, $columns, self::COLUMN_DATE));
            if (null === $date || '' === $videoId) {
                continue;
            }

            $rate = (float) self::field($fields, $columns, self::COLUMN_CTR);
            $maxRate = max($maxRate, $rate);

            $parsed[] = [
                'videoId' => $videoId,
                'date' => $date,
                'impressions' => (int) self::field($fields, $columns, self::COLUMN_IMPRESSIONS),
                'rate' => $rate,
            ];
        }

        $scale = $maxRate <= 1.0 ? 100.0 : 1.0;

        return array_map(
            static fn (array $row): ReachRow => new ReachRow($row['videoId'], $row['date'], $row['impressions'], $row['rate'] * $scale),
            $parsed,
        );
    }

    /**
     * @param list<string|null> $header
     *
     * @return array<string, int>
     */
    private static function indexColumns(array $header): array
    {
        $columns = [];
        foreach ($header as $index => $name) {
            if (\is_string($name)) {
                $columns[strtolower(trim($name))] = $index;
            }
        }

        return $columns;
    }

    /**
     * @param list<string|null>  $fields
     * @param array<string, int> $columns
     */
    private static function field(array $fields, array $columns, string $column): string
    {
        $index = $columns[$column] ?? null;

        return null === $index ? '' : trim((string) ($fields[$index] ?? ''));
    }

    private static function parseDate(string $value): ?\DateTimeImmutable
    {
        foreach (['Ymd', 'Y-m-d'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $value);
            if (false !== $date) {
                return $date;
            }
        }

        return null;
    }
}
