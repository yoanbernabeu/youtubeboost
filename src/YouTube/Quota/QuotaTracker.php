<?php

declare(strict_types=1);

namespace App\YouTube\Quota;

use App\YouTube\Entity\QuotaUsage;
use App\YouTube\Exception\QuotaExhaustedException;
use App\YouTube\Repository\QuotaUsageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Journalises every Data API call and answers how much quota is left today.
 */
#[AsAlias(QuotaRecorderInterface::class)]
final class QuotaTracker implements QuotaRecorderInterface
{
    public function __construct(
        private readonly QuotaUsageRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function record(YouTubeEndpoint $endpoint, int $calls = 1): void
    {
        if ($calls < 1) {
            return;
        }

        $now = $this->now();
        $this->entityManager->persist(new QuotaUsage(
            QuotaWindow::day($now),
            $endpoint,
            $endpoint->quotaCost() * $calls,
            $now,
        ));
        $this->entityManager->flush();
    }

    public function snapshot(): QuotaSnapshot
    {
        $now = $this->now();

        return new QuotaSnapshot(
            $this->repository->totalCostForDay(QuotaWindow::day($now)),
            QuotaWindow::nextReset($now),
        );
    }

    /**
     * @return array<string, int>
     */
    public function todayPerEndpoint(): array
    {
        return $this->repository->costPerEndpointForDay(QuotaWindow::day($this->now()));
    }

    /**
     * Refuses an operation that today's remaining quota cannot pay for.
     *
     * @throws QuotaExhaustedException
     */
    public function assertCanAfford(int $cost): void
    {
        $snapshot = $this->snapshot();
        if (!$snapshot->canAfford($cost)) {
            throw QuotaExhaustedException::forCost($cost, $snapshot);
        }
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
