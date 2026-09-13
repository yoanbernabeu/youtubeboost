<?php

declare(strict_types=1);

namespace App\Job\Repository;

use App\Job\Entity\Job;
use App\Job\Entity\JobStatus;
use App\Job\Entity\JobType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Job>
 */
final class JobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Job::class);
    }

    public function findLatestOfType(JobType $type): ?Job
    {
        return $this->findOneBy(['type' => $type], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }

    /**
     * @param list<JobType> $types
     *
     * @return list<Job>
     */
    public function findRunning(array $types = []): array
    {
        $builder = $this->createQueryBuilder('j')
            ->andWhere('j.status IN (:statuses)')
            ->setParameter('statuses', [JobStatus::Pending, JobStatus::Running])
            ->orderBy('j.createdAt', 'DESC');

        if ([] !== $types) {
            $builder->andWhere('j.type IN (:types)')->setParameter('types', $types);
        }

        /** @var list<Job> $jobs */
        $jobs = $builder->getQuery()->getResult();

        return $jobs;
    }

    /**
     * @return list<Job>
     */
    public function findForSubject(JobType $type, string $subjectId): array
    {
        /** @var list<Job> $jobs */
        $jobs = $this->createQueryBuilder('j')
            ->andWhere('j.type = :type')
            ->andWhere('j.subjectId = :subject')
            ->setParameter('type', $type)
            ->setParameter('subject', $subjectId)
            ->orderBy('j.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $jobs;
    }

    public function deleteFinishedBefore(\DateTimeImmutable $threshold): int
    {
        return (int) $this->createQueryBuilder('j')
            ->delete()
            ->andWhere('j.finishedAt IS NOT NULL')
            ->andWhere('j.finishedAt < :threshold')
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->execute();
    }
}
