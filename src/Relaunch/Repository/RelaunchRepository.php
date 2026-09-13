<?php

declare(strict_types=1);

namespace App\Relaunch\Repository;

use App\Relaunch\Entity\Relaunch;
use App\Relaunch\Entity\RelaunchStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Relaunch>
 */
final class RelaunchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Relaunch::class);
    }

    /**
     * @return list<Relaunch>
     */
    public function findAllRecentFirst(int $limit = 100): array
    {
        /** @var list<Relaunch> $relaunches */
        $relaunches = $this->createQueryBuilder('r')
            ->orderBy('r.appliedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $relaunches;
    }

    /**
     * Relaunches whose 28 day milestone is not in yet.
     *
     * @return list<Relaunch>
     */
    public function findAwaitingMilestones(): array
    {
        /** @var list<Relaunch> $relaunches */
        $relaunches = $this->createQueryBuilder('r')
            ->andWhere('r.verdictAt28 IS NULL')
            ->orderBy('r.appliedAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $relaunches;
    }

    public function findLatestForVideo(string $videoId): ?Relaunch
    {
        return $this->findOneBy(['video' => $videoId], ['appliedAt' => 'DESC', 'id' => 'DESC']);
    }

    /**
     * @return list<Relaunch>
     */
    public function findAllForVideo(string $videoId): array
    {
        /** @var list<Relaunch> $relaunches */
        $relaunches = $this->createQueryBuilder('r')
            ->andWhere('r.video = :video')
            ->setParameter('video', $videoId)
            ->orderBy('r.appliedAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $relaunches;
    }

    /**
     * @return array{total: int, tracking: int, successful: int, neutral: int, negative: int, reverted: int}
     */
    public function statistics(): array
    {
        /** @var array{total: numeric-string|int, tracking: numeric-string|int, successful: numeric-string|int, neutral: numeric-string|int, negative: numeric-string|int, reverted: numeric-string|int} $row */
        $row = $this->createQueryBuilder('r')
            ->select('COUNT(r.id) AS total')
            ->addSelect('SUM(CASE WHEN r.status = :tracking THEN 1 ELSE 0 END) AS tracking')
            ->addSelect("SUM(CASE WHEN COALESCE(r.verdictAt28, r.verdictAt14) = 'successful' THEN 1 ELSE 0 END) AS successful")
            ->addSelect("SUM(CASE WHEN COALESCE(r.verdictAt28, r.verdictAt14) = 'neutral' THEN 1 ELSE 0 END) AS neutral")
            ->addSelect("SUM(CASE WHEN COALESCE(r.verdictAt28, r.verdictAt14) = 'negative' THEN 1 ELSE 0 END) AS negative")
            ->addSelect('SUM(CASE WHEN r.status = :reverted THEN 1 ELSE 0 END) AS reverted')
            ->setParameter('tracking', RelaunchStatus::Tracking)
            ->setParameter('reverted', RelaunchStatus::Reverted)
            ->getQuery()
            ->getSingleResult();

        return [
            'total' => (int) $row['total'],
            'tracking' => (int) $row['tracking'],
            'successful' => (int) $row['successful'],
            'neutral' => (int) $row['neutral'],
            'negative' => (int) $row['negative'],
            'reverted' => (int) $row['reverted'],
        ];
    }
}
