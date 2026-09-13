<?php

declare(strict_types=1);

namespace App\Tests\Unit\Job;

use App\Job\Entity\Job;
use App\Job\Entity\JobStatus;
use App\Job\Entity\JobType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Job::class)]
#[CoversClass(JobStatus::class)]
final class JobTest extends TestCase
{
    public function testANewJobIsPendingAndEmpty(): void
    {
        $job = new Job(JobType::SyncCatalog);

        self::assertSame(JobStatus::Pending, $job->getStatus());
        self::assertSame(0, $job->getProgress());
        self::assertFalse($job->isFinished());
        self::assertNull($job->getStartedAt());
        self::assertNull($job->getFinishedAt());
        self::assertNull($job->getSubjectId());
    }

    public function testItRemembersWhatItWorksOn(): void
    {
        $job = new Job(JobType::AnalyzeVideo, 'dQw4w9WgXcQ');

        self::assertSame('dQw4w9WgXcQ', $job->getSubjectId());
    }

    public function testStartingAJobRecordsTheTime(): void
    {
        $job = new Job(JobType::SyncCatalog);

        $job->start(new \DateTimeImmutable('2026-06-30 10:00'), 'Reading the catalogue');

        self::assertSame(JobStatus::Running, $job->getStatus());
        self::assertSame('2026-06-30 10:00', $job->getStartedAt()?->format('Y-m-d H:i'));
        self::assertSame('Reading the catalogue', $job->getStep());
    }

    public function testProgressIsDerivedFromTheCounters(): void
    {
        $job = new Job(JobType::SyncCatalog);

        $job->advance(25, 200, 'Statistiques');

        self::assertSame(12, $job->getProgress());
        self::assertSame(25, $job->getDone());
        self::assertSame(200, $job->getTotal());
        self::assertSame('Statistiques', $job->getStep());
    }

    public function testProgressStaysAtZeroWhenTheTotalIsUnknown(): void
    {
        $job = new Job(JobType::SyncCatalog);

        $job->advance(10, 0, 'Starting up');

        self::assertSame(0, $job->getProgress());
    }

    public function testProgressNeverExceedsOneHundred(): void
    {
        $job = new Job(JobType::SyncCatalog);

        $job->advance(300, 200, 'Statistiques');

        self::assertSame(100, $job->getProgress());
    }

    public function testAdvancingKeepsThePreviousStepWhenNoneIsGiven(): void
    {
        $job = new Job(JobType::SyncCatalog);
        $job->advance(1, 10, 'Statistiques');

        $job->advance(2, 10);

        self::assertSame('Statistiques', $job->getStep());
    }

    public function testSucceedingCompletesTheProgress(): void
    {
        $job = new Job(JobType::SyncCatalog);
        $job->advance(5, 200, 'Statistiques');

        $job->succeed(new \DateTimeImmutable('2026-06-30 10:05'), '200 videos synced');

        self::assertSame(JobStatus::Succeeded, $job->getStatus());
        self::assertSame(100, $job->getProgress());
        self::assertTrue($job->isFinished());
        self::assertSame('200 videos synced', $job->getStep());
        self::assertSame('2026-06-30 10:05', $job->getFinishedAt()?->format('Y-m-d H:i'));
        self::assertNull($job->getErrorMessage());
    }

    public function testFailingKeepsTheProgressAndStoresTheMessage(): void
    {
        $job = new Job(JobType::SyncCatalog);
        $job->advance(5, 200, 'Statistiques');

        $job->fail(new \DateTimeImmutable('2026-06-30 10:05'), 'Quota exceeded');

        self::assertSame(JobStatus::Failed, $job->getStatus());
        self::assertSame(2, $job->getProgress());
        self::assertTrue($job->isFinished());
        self::assertSame('Quota exceeded', $job->getErrorMessage());
    }

    public function testALongErrorMessageIsTruncated(): void
    {
        $job = new Job(JobType::SyncCatalog);

        $job->fail(new \DateTimeImmutable(), str_repeat('a', 2000));

        self::assertSame(1000, \strlen((string) $job->getErrorMessage()));
    }

    /**
     * A step usually quotes the reason a sub-task failed, and a provider can be
     * arbitrarily verbose. Overflowing the column aborts the flush and closes the
     * entity manager, after which the job cannot even be marked failed.
     */
    public function testALongStepIsTruncatedAtEveryTransition(): void
    {
        $verbose = str_repeat('é', 600);

        foreach (self::jobsWithStep($verbose) as $label => $job) {
            self::assertSame(255, mb_strlen((string) $job->getStep()), $label);
        }
    }

    /**
     * @return iterable<string, Job>
     */
    private static function jobsWithStep(string $step): iterable
    {
        $at = new \DateTimeImmutable('2026-06-30 10:05');

        $starting = new Job(JobType::GenerateThumbnail);
        $starting->start($at, $step);
        yield 'start' => $starting;

        $advancing = new Job(JobType::GenerateThumbnail);
        $advancing->advance(1, 1, $step);
        yield 'advance' => $advancing;

        $succeeding = new Job(JobType::GenerateThumbnail);
        $succeeding->succeed($at, $step);
        yield 'succeed' => $succeeding;
    }
}
