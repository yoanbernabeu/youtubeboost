<?php

declare(strict_types=1);

namespace App\Tests\Integration\Job;

use App\Job\Entity\JobStatus;
use App\Job\Entity\JobType;
use App\Job\JobRunner;
use App\Job\JobTracker;
use App\Job\Repository\JobRepository;
use App\Shared\Progress\ProgressReporterInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * A job must always reach a finished state, against the real columns.
 *
 * The step is the trap: it usually quotes why a sub-task failed, and a provider
 * error can run to several hundred characters. An overflow aborts the flush and
 * closes the entity manager, after which the job cannot even be marked failed, so
 * the interface polls a row that will never move again.
 */
#[CoversClass(JobRunner::class)]
#[CoversClass(JobTracker::class)]
final class JobRunnerTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private JobRunner $runner;
    private JobTracker $tracker;
    private JobRepository $jobs;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->runner = self::getContainer()->get(JobRunner::class);
        $this->tracker = self::getContainer()->get(JobTracker::class);
        $this->jobs = self::getContainer()->get(JobRepository::class);
    }

    public function testAVerboseSummaryStillLeavesTheJobSucceeded(): void
    {
        $job = $this->tracker->create(JobType::GenerateThumbnail, 'wvHsqFCU_Sk');
        $id = (int) $job->getId();

        $this->runner->run($id, static function (ProgressReporterInterface $reporter): string {
            $reporter->progress(1, 1);

            return 'Miniature 5 en échec : ' . str_repeat('détail verbeux ', 40);
        });

        $reloaded = $this->jobs->find($id);
        self::assertNotNull($reloaded);
        self::assertSame(JobStatus::Succeeded, $reloaded->getStatus());
        self::assertNotNull($reloaded->getFinishedAt());
        self::assertSame(255, mb_strlen((string) $reloaded->getStep()));
    }

    public function testAVerboseFailureStillLeavesTheJobFailed(): void
    {
        $job = $this->tracker->create(JobType::AnalyzeVideo, 'wvHsqFCU_Sk');
        $id = (int) $job->getId();

        $this->runner->run($id, static function (ProgressReporterInterface $reporter): string {
            $reporter->progress(1, 3, 'Lecture de la vidéo par Gemini ' . str_repeat('…', 400));

            throw new \RuntimeException(str_repeat('erreur ', 300));
        });

        $reloaded = $this->jobs->find($id);
        self::assertNotNull($reloaded);
        self::assertSame(JobStatus::Failed, $reloaded->getStatus());
        self::assertNotNull($reloaded->getErrorMessage());
    }
}
