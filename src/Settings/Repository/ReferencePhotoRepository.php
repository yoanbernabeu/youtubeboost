<?php

declare(strict_types=1);

namespace App\Settings\Repository;

use App\Settings\Entity\ReferenceAngle;
use App\Settings\Entity\ReferencePhoto;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ReferencePhoto>
 */
final class ReferencePhotoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReferencePhoto::class);
    }

    /**
     * @return list<ReferencePhoto>
     */
    public function findAllOrdered(): array
    {
        /** @var list<ReferencePhoto> $photos */
        $photos = $this->createQueryBuilder('p')
            ->orderBy('p.angle', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $photos;
    }

    /**
     * @return list<ReferenceAngle>
     */
    public function coveredAngles(): array
    {
        /** @var list<array{angle: ReferenceAngle}> $rows */
        $rows = $this->createQueryBuilder('p')
            ->select('DISTINCT p.angle AS angle')
            ->getQuery()
            ->getArrayResult();

        return array_values(array_map(static fn (array $row): ReferenceAngle => $row['angle'], $rows));
    }

    public function hasMinimumCoverage(): bool
    {
        $covered = $this->coveredAngles();

        foreach ([ReferenceAngle::Front, ReferenceAngle::Right, ReferenceAngle::Left] as $required) {
            if (!\in_array($required, $covered, true)) {
                return false;
            }
        }

        return true;
    }
}
