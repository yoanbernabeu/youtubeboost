<?php

declare(strict_types=1);

namespace App\Analysis\Repository;

use App\Analysis\Entity\Transcript;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Transcript>
 */
final class TranscriptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Transcript::class);
    }

    public function findForVideo(string $videoId): ?Transcript
    {
        return $this->findOneBy(['video' => $videoId]);
    }
}
