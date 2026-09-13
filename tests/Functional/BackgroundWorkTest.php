<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Analysis\Controller\AnalysisController;
use App\Analysis\Message\AnalyzeVideo;
use App\Catalog\Message\SynchronizeCatalog;
use App\Job\Entity\JobType;
use App\Job\Repository\JobRepository;
use App\Relaunch\Message\ApplyThumbnail;
use App\Thumbnail\Message\GenerateThumbnail;
use App\Thumbnail\Message\IterateThumbnail;
use App\Thumbnail\Repository\ThumbnailProposalRepository;
use App\YouTube\Entity\QuotaUsage;
use App\YouTube\Quota\YouTubeEndpoint;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The expensive operations must never run inside a web request: every button
 * only queues a message and creates a job the interface can follow.
 */
#[CoversClass(AnalysisController::class)]
final class BackgroundWorkTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->completeOnboarding();
        $this->logIn();
    }

    public function testAnalysingAVideoQueuesAMessageAndCreatesAJob(): void
    {
        $this->createVideo('toAnalyse', 80);

        $this->client->request('POST', '/video/toAnalyse/analyser', ['_token' => $this->csrfToken('submit')]);

        self::assertResponseRedirects('/video/toAnalyse');
        self::assertCount(1, $this->queued(AnalyzeVideo::class));

        $jobs = self::getContainer()->get(JobRepository::class)->findForSubject(JobType::AnalyzeVideo, 'toAnalyse');
        self::assertCount(1, $jobs);
        self::assertSame(0, $jobs[0]->getProgress());
    }

    public function testAnalysingTwiceReusesTheRunningJob(): void
    {
        $this->createVideo('twice', 80);

        $this->client->request('POST', '/video/twice/analyser', ['_token' => $this->csrfToken('submit')]);
        self::assertCount(1, $this->queued(AnalyzeVideo::class));

        // The transport is reset between requests; what matters is that the second
        // click adds neither a message nor a second job.
        $this->client->request('POST', '/video/twice/analyser', ['_token' => $this->csrfToken('submit')]);

        self::assertCount(0, $this->queued(AnalyzeVideo::class));
        self::assertCount(1, self::getContainer()->get(JobRepository::class)->findForSubject(JobType::AnalyzeVideo, 'twice'));
    }

    public function testAnalysingIsRefusedWhenTheQuotaCannotPayForIt(): void
    {
        $this->createVideo('expensive', 80);
        $this->exhaustQuota();

        $this->client->request('POST', '/video/expensive/analyser', ['_token' => $this->csrfToken('submit')]);

        self::assertResponseRedirects('/video/expensive');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'quota');
        self::assertCount(0, $this->queued(AnalyzeVideo::class));
    }

    public function testRegeneratingEveryThumbnailQueuesFiveMessages(): void
    {
        $video = $this->createVideo('regenerate', 80);
        $analysis = $this->createAnalysis($video);

        $this->client->request('POST', '/analyse/' . $analysis->getId() . '/regenerer', ['_token' => $this->csrfToken('submit')]);

        self::assertResponseRedirects('/video/regenerate');
        self::assertCount(5, $this->queued(GenerateThumbnail::class));
    }

    public function testIteratingOnAProposalQueuesAChild(): void
    {
        $video = $this->createVideo('iterate', 80);
        $analysis = $this->createAnalysis($video);
        $proposal = $this->firstProposal($analysis->getId());

        $this->client->request('POST', '/proposition/' . $proposal->getId() . '/iterer', [
            '_token' => $this->csrfToken('submit'),
            'instruction' => 'Agrandis le texte',
        ]);

        self::assertResponseRedirects('/video/iterate');
        self::assertCount(1, $this->queued(IterateThumbnail::class));

        $proposals = self::getContainer()->get(ThumbnailProposalRepository::class)->findForAnalysis((int) $analysis->getId());
        self::assertCount(6, $proposals);
    }

    public function testIteratingWithoutAnInstructionIsRefused(): void
    {
        $video = $this->createVideo('noInstruction', 80);
        $analysis = $this->createAnalysis($video);
        $proposal = $this->firstProposal($analysis->getId());

        $this->client->request('POST', '/proposition/' . $proposal->getId() . '/iterer', [
            '_token' => $this->csrfToken('submit'),
            'instruction' => '   ',
        ]);

        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Describe the change you want');
        self::assertCount(0, $this->queued(IterateThumbnail::class));
    }

    public function testIteratingOnAnUngeneratedProposalIsRefused(): void
    {
        $video = $this->createVideo('notReady', 80);
        $analysis = $this->createAnalysis($video, readyProposals: 0);
        $proposal = $this->firstProposal($analysis->getId());

        $this->client->request('POST', '/proposition/' . $proposal->getId() . '/iterer', [
            '_token' => $this->csrfToken('submit'),
            'instruction' => 'Agrandis le texte',
        ]);

        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Wait for this thumbnail to be generated');
    }

    public function testApplyingAThumbnailQueuesTheWrite(): void
    {
        $video = $this->createVideo('apply', 80);
        $analysis = $this->createAnalysis($video);
        $proposal = $this->firstProposal($analysis->getId());

        $this->client->request('POST', '/proposition/' . $proposal->getId() . '/appliquer', ['_token' => $this->csrfToken('submit')]);

        self::assertResponseRedirects('/video/apply');
        self::assertCount(1, $this->queued(ApplyThumbnail::class));
    }

    public function testApplyingIsRefusedWhenTheQuotaIsExhausted(): void
    {
        $video = $this->createVideo('noQuota', 80);
        $analysis = $this->createAnalysis($video);
        $proposal = $this->firstProposal($analysis->getId());
        $this->exhaustQuota();

        $this->client->request('POST', '/proposition/' . $proposal->getId() . '/appliquer', ['_token' => $this->csrfToken('submit')]);

        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'quota');
        self::assertCount(0, $this->queued(ApplyThumbnail::class));
    }

    public function testEveryBackgroundActionIsCsrfProtected(): void
    {
        $video = $this->createVideo('csrf', 80);
        $analysis = $this->createAnalysis($video);
        $proposal = $this->firstProposal($analysis->getId());

        $paths = [
            '/video/csrf/analyser',
            '/analyse/' . $analysis->getId() . '/regenerer',
            '/proposition/' . $proposal->getId() . '/iterer',
            '/proposition/' . $proposal->getId() . '/appliquer',
            '/proposition/' . $proposal->getId() . '/regenerer',
        ];

        foreach ($paths as $path) {
            $this->client->request('POST', $path, ['_token' => 'wrong']);
            self::assertResponseStatusCodeSame(403, \sprintf('%s must reject a bad token.', $path));
        }

        self::assertSame([], $this->transport()->getSent());
    }

    public function testTheSynchronisationMessageIsNeverSentByAGetRequest(): void
    {
        $this->client->request('GET', '/');

        self::assertCount(0, $this->queued(SynchronizeCatalog::class));
    }

    private function firstProposal(?int $analysisId): \App\Thumbnail\Entity\ThumbnailProposal
    {
        $proposals = self::getContainer()->get(ThumbnailProposalRepository::class)->findForAnalysis((int) $analysisId);
        self::assertNotEmpty($proposals);

        return $proposals[0];
    }

    /**
     * @param class-string $messageClass
     *
     * @return list<object>
     */
    private function queued(string $messageClass): array
    {
        $found = [];
        foreach ($this->transport()->getSent() as $envelope) {
            if ($envelope->getMessage() instanceof $messageClass) {
                $found[] = $envelope->getMessage();
            }
        }

        return $found;
    }

    private function transport(): InMemoryTransport
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');

        return $transport;
    }

    private function exhaustQuota(): void
    {
        $day = \App\YouTube\Quota\QuotaWindow::day(new \DateTimeImmutable());
        $this->entityManager()->persist(new QuotaUsage($day, YouTubeEndpoint::CaptionsDownload, 10000, new \DateTimeImmutable()));
        $this->entityManager()->flush();
    }
}
