<?php

declare(strict_types=1);

namespace App\Thumbnail\Repository;

use App\Thumbnail\Entity\ProposalStatus;
use App\Thumbnail\Entity\ThumbnailProposal;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ThumbnailProposal>
 */
final class ThumbnailProposalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ThumbnailProposal::class);
    }

    /**
     * @return list<ThumbnailProposal>
     */
    public function findForAnalysis(int $analysisId): array
    {
        /** @var list<ThumbnailProposal> $proposals */
        $proposals = $this->createQueryBuilder('p')
            ->andWhere('p.analysis = :analysis')
            ->setParameter('analysis', $analysisId)
            ->orderBy('p.angleIndex', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $proposals;
    }

    /**
     * The latest proposal of each angle, which is what the grid shows.
     *
     * @return array<int, ThumbnailProposal> indexed by angle
     */
    public function findLatestPerAngle(int $analysisId): array
    {
        $latest = [];
        foreach ($this->findForAnalysis($analysisId) as $proposal) {
            $current = $latest[$proposal->getAngleIndex()] ?? null;
            if (null === $current || $proposal->getId() > $current->getId()) {
                $latest[$proposal->getAngleIndex()] = $proposal;
            }
        }

        ksort($latest);

        return $latest;
    }

    /**
     * @return list<ThumbnailProposal>
     */
    public function findChain(ThumbnailProposal $proposal): array
    {
        $chain = [$proposal];
        $parent = $proposal->getParent();
        while (null !== $parent) {
            $chain[] = $parent;
            $parent = $parent->getParent();
        }

        return array_reverse($chain);
    }

    public function countUnfinishedForAnalysis(int $analysisId): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.analysis = :analysis')
            ->andWhere('p.status IN (:statuses)')
            ->setParameter('analysis', $analysisId)
            ->setParameter('statuses', [ProposalStatus::Pending, ProposalStatus::Generating])
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<ThumbnailProposal>
     */
    public function findReadyForVideo(string $videoId, int $limit = 50): array
    {
        /** @var list<ThumbnailProposal> $proposals */
        $proposals = $this->createQueryBuilder('p')
            ->andWhere('p.video = :video')
            ->andWhere('p.status = :ready')
            ->setParameter('video', $videoId)
            ->setParameter('ready', ProposalStatus::Ready)
            ->orderBy('p.generatedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $proposals;
    }
}
