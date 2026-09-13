<?php

declare(strict_types=1);

namespace App\YouTube\Repository;

use App\YouTube\Entity\Channel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Channel>
 */
final class ChannelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Channel::class);
    }

    /**
     * The application is mono-channel: there is at most one row.
     */
    public function findConnected(): ?Channel
    {
        return $this->findOneBy([], ['id' => 'ASC']);
    }
}
