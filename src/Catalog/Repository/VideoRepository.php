<?php

declare(strict_types=1);

namespace App\Catalog\Repository;

use App\Catalog\Entity\RelaunchState;
use App\Catalog\Entity\Video;
use App\Catalog\Entity\VideoType;
use App\Catalog\Entity\Visibility;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Video>
 */
final class VideoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Video::class);
    }

    /**
     * @return array<string, Video> indexed by YouTube identifier
     */
    public function findAllIndexedById(): array
    {
        $indexed = [];
        foreach ($this->findAll() as $video) {
            $indexed[$video->getYoutubeId()] = $video;
        }

        return $indexed;
    }

    /**
     * Identifiers of the videos the scoring pass has to look at.
     *
     * @return list<string>
     */
    public function relaunchableIds(): array
    {
        /** @var list<array{youtubeId: string}> $rows */
        $rows = $this->createQueryBuilder('v')
            ->select('v.youtubeId')
            ->andWhere('v.type = :type')
            ->andWhere('v.visibility != :private')
            ->setParameter('type', VideoType::Standard)
            ->setParameter('private', Visibility::Private)
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'youtubeId');
    }

    /**
     * @return list<string>
     */
    public function allIds(): array
    {
        /** @var list<array{youtubeId: string}> $rows */
        $rows = $this->createQueryBuilder('v')
            ->select('v.youtubeId')
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'youtubeId');
    }

    /**
     * Videos whose type has never been settled by the Analytics API.
     *
     * @return list<string>
     */
    public function idsWithUnconfirmedType(): array
    {
        /** @var list<array{youtubeId: string}> $rows */
        $rows = $this->createQueryBuilder('v')
            ->select('v.youtubeId')
            ->andWhere('v.typeSource IN (:sources)')
            ->setParameter('sources', ['unknown', 'heuristic'])
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'youtubeId');
    }

    /**
     * @return list<Video>
     */
    public function findByFilter(VideoFilter $filter, int $limit = 100, int $offset = 0): array
    {
        /** @var list<Video> $videos */
        $videos = $this->filteredQueryBuilder($filter)
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();

        return $videos;
    }

    public function countByFilter(VideoFilter $filter): int
    {
        $builder = $this->filteredQueryBuilder($filter, withOrder: false);

        return (int) $builder->select('COUNT(v.youtubeId)')->getQuery()->getSingleScalarResult();
    }

    /**
     * @return list<Video>
     */
    public function findTopCandidates(int $limit = 5): array
    {
        /** @var list<Video> $videos */
        $videos = $this->createQueryBuilder('v')
            ->andWhere('v.score > 0')
            ->orderBy('v.score', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $videos;
    }

    /**
     * @return array{total: int, standard: int, scored: int, relaunched: int}
     */
    public function statistics(): array
    {
        /** @var array{total: numeric-string|int, standard: numeric-string|int, scored: numeric-string|int, relaunched: numeric-string|int} $row */
        $row = $this->createQueryBuilder('v')
            ->select('COUNT(v.youtubeId) AS total')
            ->addSelect('SUM(CASE WHEN v.type = :standard THEN 1 ELSE 0 END) AS standard')
            ->addSelect('SUM(CASE WHEN v.score > 0 THEN 1 ELSE 0 END) AS scored')
            ->addSelect('SUM(CASE WHEN v.relaunchState != :none THEN 1 ELSE 0 END) AS relaunched')
            ->setParameter('standard', VideoType::Standard)
            ->setParameter('none', RelaunchState::None)
            ->getQuery()
            ->getSingleResult();

        return [
            'total' => (int) $row['total'],
            'standard' => (int) $row['standard'],
            'scored' => (int) $row['scored'],
            'relaunched' => (int) $row['relaunched'],
        ];
    }

    /**
     * Removes videos that are no longer returned by the channel.
     *
     * @param list<string> $knownIds
     */
    public function deleteMissing(array $knownIds): int
    {
        $builder = $this->createQueryBuilder('v')->delete();

        if ([] !== $knownIds) {
            $builder->andWhere('v.youtubeId NOT IN (:ids)')->setParameter('ids', $knownIds);
        }

        return (int) $builder->getQuery()->execute();
    }

    private function filteredQueryBuilder(VideoFilter $filter, bool $withOrder = true): QueryBuilder
    {
        $builder = $this->createQueryBuilder('v');

        if (!$filter->includeIneligible) {
            $builder->andWhere('v.type = :standardType')
                ->andWhere('v.visibility != :privateVisibility')
                ->setParameter('standardType', VideoType::Standard)
                ->setParameter('privateVisibility', Visibility::Private);
        }

        if (null !== $filter->search && '' !== trim($filter->search)) {
            $builder->andWhere('LOWER(v.title) LIKE :search OR LOWER(v.description) LIKE :search')
                ->setParameter('search', '%' . mb_strtolower(trim($filter->search)) . '%');
        }

        if ($filter->minScore > 0) {
            $builder->andWhere('v.score >= :minScore')->setParameter('minScore', $filter->minScore);
        }

        if (null !== $filter->minAgeDays) {
            $builder->andWhere('v.publishedAt <= :publishedBefore')
                ->setParameter('publishedBefore', new \DateTimeImmutable(\sprintf('-%d days', $filter->minAgeDays)));
        }

        if (null !== $filter->maxAgeDays) {
            $builder->andWhere('v.publishedAt >= :publishedAfter')
                ->setParameter('publishedAfter', new \DateTimeImmutable(\sprintf('-%d days', $filter->maxAgeDays)));
        }

        if (null !== $filter->relaunchState) {
            $builder->andWhere('v.relaunchState = :relaunchState')->setParameter('relaunchState', $filter->relaunchState);
        }

        if (!$withOrder) {
            return $builder;
        }

        return match ($filter->sortOrFallback()) {
            VideoFilter::SORT_RECENT => $builder->orderBy('v.publishedAt', 'DESC'),
            VideoFilter::SORT_OLDEST => $builder->orderBy('v.publishedAt', 'ASC'),
            VideoFilter::SORT_VIEWS => $builder->orderBy('v.viewCount', 'DESC'),
            VideoFilter::SORT_TITLE => $builder->orderBy('v.title', 'ASC'),
            default => $builder->orderBy('v.score', 'DESC')->addOrderBy('v.viewCount', 'DESC'),
        };
    }
}
