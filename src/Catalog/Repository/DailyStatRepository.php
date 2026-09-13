<?php

declare(strict_types=1);

namespace App\Catalog\Repository;

use App\Catalog\Entity\DailyStat;
use App\Catalog\Model\DailyPoint;
use App\Catalog\Model\DailyStatSeries;
use App\Shared\Type\Scalar;
use App\YouTube\Api\Dto\ReachRow;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Bulk access to the daily statistics.
 *
 * A 300-video catalogue with a full history is a few hundred thousand rows, so
 * reads and writes go through DBAL rather than hydrating entities.
 */
/**
 * @extends ServiceEntityRepository<DailyStat>
 */
final class DailyStatRepository extends ServiceEntityRepository
{
    private const string TABLE = 'daily_stat';

    /** Rows per INSERT statement; keeps the query well under parameter limits. */
    private const int CHUNK_SIZE = 200;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DailyStat::class);
    }

    /**
     * Writes the Analytics metrics of one video, leaving reach columns untouched.
     *
     * @param list<DailyPoint> $points
     */
    public function upsertAnalytics(string $videoId, array $points): void
    {
        $this->upsert(
            $points,
            ['views', 'estimated_minutes_watched', 'average_view_duration', 'average_view_percentage'],
            static fn (DailyPoint $point): array => [
                $point->date->format('Y-m-d'),
                $point->views,
                $point->estimatedMinutesWatched,
                $point->averageViewDuration,
                $point->averageViewPercentage,
            ],
            static fn (): string => $videoId,
        );
    }

    /**
     * Writes the thumbnail reach metrics, leaving Analytics columns untouched.
     *
     * @param list<ReachRow> $rows
     */
    public function upsertReach(array $rows): void
    {
        $this->upsert(
            $rows,
            ['impressions', 'click_through_rate'],
            static fn (ReachRow $row): array => [
                $row->date->format('Y-m-d'),
                $row->impressions,
                $row->clickThroughRate,
            ],
            static fn (ReachRow $row): string => $row->videoId,
        );
    }

    public function loadSeries(string $videoId): DailyStatSeries
    {
        $sql = \sprintf(
            'SELECT stat_date, views, estimated_minutes_watched, average_view_duration, average_view_percentage, impressions, click_through_rate
             FROM %s WHERE video_id = :video ORDER BY stat_date ASC',
            self::TABLE,
        );

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative($sql, ['video' => $videoId]);

        return DailyStatSeries::fromPoints(array_map(self::toPoint(...), $rows));
    }

    /**
     * Loads the history of each video one at a time, so a full catalogue never
     * sits in memory at once.
     *
     * @param iterable<string> $videoIds
     *
     * @return \Generator<string, DailyStatSeries>
     */
    public function streamSeries(iterable $videoIds): \Generator
    {
        foreach ($videoIds as $videoId) {
            yield $videoId => $this->loadSeries($videoId);
        }
    }

    /**
     * Last day already imported for a video, used to resume an incremental sync.
     */
    public function lastImportedDay(string $videoId): ?\DateTimeImmutable
    {
        $day = $this->getEntityManager()->getConnection()->fetchOne(
            \sprintf('SELECT MAX(stat_date) FROM %s WHERE video_id = :video AND views > 0', self::TABLE),
            ['video' => $videoId],
        );

        return \is_string($day) && '' !== $day ? new \DateTimeImmutable($day . ' 00:00:00') : null;
    }

    /**
     * Whether any thumbnail reach data has been imported yet.
     *
     * Nothing exists until the Reporting API has produced its first daily report,
     * which is why the interface explains the wait instead of showing two empty
     * signals without a word.
     */
    public function hasImpressionData(): bool
    {
        return (bool) $this->getEntityManager()->getConnection()->fetchOne(
            \sprintf('SELECT EXISTS (SELECT 1 FROM %s WHERE impressions IS NOT NULL)', self::TABLE),
        );
    }

    /**
     * @param list<string> $videoIds
     */
    public function deleteForVideos(array $videoIds): void
    {
        if ([] === $videoIds) {
            return;
        }

        $this->getEntityManager()->getConnection()->executeStatement(
            \sprintf('DELETE FROM %s WHERE video_id IN (:ids)', self::TABLE),
            ['ids' => $videoIds],
            ['ids' => ArrayParameterType::STRING],
        );
    }

    /**
     * @template T of object
     *
     * @param list<T>                  $items
     * @param list<string>             $updatedColumns
     * @param callable(T): list<mixed> $toValues       day first, then the updated columns
     * @param callable(T): string      $toVideoId
     */
    private function upsert(array $items, array $updatedColumns, callable $toValues, callable $toVideoId): void
    {
        if ([] === $items) {
            return;
        }

        $columns = array_merge(['video_id', 'stat_date'], $updatedColumns);
        $assignments = implode(', ', array_map(static fn (string $c): string => \sprintf('%1$s = EXCLUDED.%1$s', $c), $updatedColumns));
        $placeholderRow = '(' . implode(', ', array_fill(0, \count($columns), '?')) . ')';
        $connection = $this->getEntityManager()->getConnection();

        foreach (array_chunk($items, self::CHUNK_SIZE) as $chunk) {
            $parameters = [];
            $types = [];
            foreach ($chunk as $item) {
                $values = $toValues($item);
                $parameters[] = $toVideoId($item);
                $types[] = ParameterType::STRING;
                foreach ($values as $value) {
                    $parameters[] = $value;
                    $types[] = self::parameterType($value);
                }
            }

            $connection->executeStatement(
                \sprintf(
                    'INSERT INTO %s (%s) VALUES %s ON CONFLICT (video_id, stat_date) DO UPDATE SET %s',
                    self::TABLE,
                    implode(', ', $columns),
                    implode(', ', array_fill(0, \count($chunk), $placeholderRow)),
                    $assignments,
                ),
                $parameters,
                $types,
            );
        }
    }

    private static function parameterType(mixed $value): ParameterType
    {
        return match (true) {
            null === $value => ParameterType::NULL,
            \is_int($value) => ParameterType::INTEGER,
            default => ParameterType::STRING,
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function toPoint(array $row): DailyPoint
    {
        $date = Scalar::nullableString($row['stat_date'] ?? null) ?? '1970-01-01';

        return new DailyPoint(
            new \DateTimeImmutable($date . ' 00:00:00'),
            Scalar::int($row['views'] ?? null),
            Scalar::nullableInt($row['impressions'] ?? null),
            Scalar::nullableFloat($row['click_through_rate'] ?? null),
            Scalar::nullableFloat($row['average_view_percentage'] ?? null),
            Scalar::nullableInt($row['average_view_duration'] ?? null),
            Scalar::int($row['estimated_minutes_watched'] ?? null),
        );
    }
}
