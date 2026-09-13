<?php

declare(strict_types=1);

namespace App\Analysis\Repository;

use App\Analysis\Entity\Analysis;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Analysis>
 */
final class AnalysisRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Analysis::class);
    }

    public function findLatestForVideo(string $videoId): ?Analysis
    {
        return $this->findOneBy(['video' => $videoId], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }

    /**
     * @return list<Analysis>
     */
    public function findAllForVideo(string $videoId): array
    {
        /** @var list<Analysis> $analyses */
        $analyses = $this->createQueryBuilder('a')
            ->andWhere('a.video = :video')
            ->setParameter('video', $videoId)
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $analyses;
    }
}
