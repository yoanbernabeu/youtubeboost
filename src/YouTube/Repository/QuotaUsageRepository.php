<?php

declare(strict_types=1);

namespace App\YouTube\Repository;

use App\YouTube\Entity\QuotaUsage;
use App\YouTube\Quota\YouTubeEndpoint;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QuotaUsage>
 */
final class QuotaUsageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuotaUsage::class);
    }

    public function totalCostForDay(\DateTimeImmutable $quotaDay): int
    {
        return (int) $this->createQueryBuilder('q')
            ->select('COALESCE(SUM(q.cost), 0)')
            ->andWhere('q.quotaDay = :day')
            ->setParameter('day', $quotaDay, Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return array<string, int> cost per endpoint for the given quota day, most expensive first
     */
    public function costPerEndpointForDay(\DateTimeImmutable $quotaDay): array
    {
        /** @var list<array{endpoint: YouTubeEndpoint|string, cost: numeric-string|int}> $rows */
        $rows = $this->createQueryBuilder('q')
            ->select('q.endpoint AS endpoint, SUM(q.cost) AS cost')
            ->andWhere('q.quotaDay = :day')
            ->setParameter('day', $quotaDay, Types::DATE_IMMUTABLE)
            ->groupBy('q.endpoint')
            ->orderBy('cost', 'DESC')
            ->getQuery()
            ->getArrayResult();

        $costs = [];
        foreach ($rows as $row) {
            $endpoint = $row['endpoint'];
            $costs[$endpoint instanceof YouTubeEndpoint ? $endpoint->value : (string) $endpoint] = (int) $row['cost'];
        }

        return $costs;
    }

    public function deleteBefore(\DateTimeImmutable $quotaDay): int
    {
        return (int) $this->createQueryBuilder('q')
            ->delete()
            ->andWhere('q.quotaDay < :day')
            ->setParameter('day', $quotaDay, Types::DATE_IMMUTABLE)
            ->getQuery()
            ->execute();
    }
}
