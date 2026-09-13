<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Catalog\Controller\CatalogController;
use App\Catalog\Controller\VideoController;
use App\Catalog\Entity\VideoType;
use App\Shared\Controller\ImageController;
use App\Thumbnail\Controller\ProposalController;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(CatalogController::class)]
#[CoversClass(VideoController::class)]
#[CoversClass(ProposalController::class)]
#[CoversClass(ImageController::class)]
final class CatalogTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->completeOnboarding();
        $this->logIn();
    }

    public function testTheWaitForImpressionDataIsExplained(): void
    {
        $this->createVideo('waiting', 50);

        $this->client->request('GET', '/');

        self::assertSelectorTextContains('body', 'Thumbnail impressions are on their way');
    }

    public function testTheExplanationDisappearsOnceImpressionsArrive(): void
    {
        $video = $this->createVideo('withReach', 50);
        self::getContainer()->get(\App\Catalog\Repository\DailyStatRepository::class)->upsertReach([
            new \App\YouTube\Api\Dto\ReachRow($video->getYoutubeId(), new \DateTimeImmutable('-2 days'), 1200, 4.1),
        ]);

        $this->client->request('GET', '/');

        self::assertSelectorTextNotContains('body', 'Thumbnail impressions are on their way');
    }

    public function testAnEmptyCatalogueInvitesASynchronisation(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Run a synchronisation');
    }

    public function testVideosAreListedByScore(): void
    {
        $this->createVideo('lowScored', 30);
        $this->createVideo('highScored', 90);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('tbody tr');
        self::assertCount(2, $rows);
        self::assertStringContainsString('highScored', (string) $rows->eq(0)->filter('a')->attr('href'));
    }

    public function testTheScoreFilterNarrowsTheList(): void
    {
        $this->createVideo('lowScored', 30);
        $this->createVideo('highScored', 90);

        $crawler = $this->client->request('GET', '/?min_score=60');

        self::assertCount(1, $crawler->filter('tbody tr'));
    }

    public function testTheSearchFilterLooksIntoTitles(): void
    {
        $video = $this->createVideo('searchable', 50);
        $video->updateMetadata('Une vidéo sur Symfony', 'Description', $video->getPublishedAt(), 600, $video->getVisibility(), $video->getThumbnails(), new \DateTimeImmutable());
        $this->entityManager()->flush();
        $this->createVideo('other', 50);

        $crawler = $this->client->request('GET', '/?q=symfony');

        self::assertCount(1, $crawler->filter('tbody tr'));
    }

    public function testShortsAreHiddenUnlessAskedFor(): void
    {
        $short = $this->createVideo('aShort', 0);
        $short->forceType(VideoType::Short);
        $this->entityManager()->flush();

        self::assertCount(0, $this->client->request('GET', '/')->filter('tbody tr'));
        self::assertCount(1, $this->client->request('GET', '/?tout=1')->filter('tbody tr'));
    }

    public function testTheAgeFilterKeepsTheOlderVideos(): void
    {
        $recent = $this->createVideo('recent', 50);
        $recent->updateMetadata($recent->getTitle(), '', new \DateTimeImmutable('-3 months'), 600, $recent->getVisibility(), $recent->getThumbnails(), new \DateTimeImmutable());
        $this->createVideo('old', 50);
        $this->entityManager()->flush();

        self::assertCount(1, $this->client->request('GET', '/?age=365-')->filter('tbody tr'));
        self::assertCount(1, $this->client->request('GET', '/?age=-180')->filter('tbody tr'));
        self::assertCount(2, $this->client->request('GET', '/?age=')->filter('tbody tr'));
    }

    public function testAnUnreadableAgeFilterIsIgnored(): void
    {
        $this->createVideo('any', 50);

        self::assertCount(1, $this->client->request('GET', '/?age=nimporte-quoi')->filter('tbody tr'));
    }

    public function testAnUnknownSortFallsBackOnTheScore(): void
    {
        $this->createVideo('a', 10);

        $this->client->request('GET', '/?tri=nimporte-quoi');

        self::assertResponseIsSuccessful();
    }

    public function testTheVideoPageShowsItsScoreBreakdown(): void
    {
        $this->createVideo('detailed', 77);

        $this->client->request('GET', '/video/detailed');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Why this score');
        self::assertSelectorTextContains('body', 'Decline');
        self::assertSelectorTextContains('body', 'Low CTR');
        self::assertSelectorTextContains('body', '77');
    }

    public function testARealYoutubeIdentifierIsRoutable(): void
    {
        // Base64url: letters, digits, underscore, dash.
        $this->createVideo('wvHsqFCU_Sk', 60);
        $this->createVideo('a-B_c9dEfGh', 40);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('/video/wvHsqFCU_Sk', (string) $crawler->filter('tbody tr')->eq(0)->filter('a')->attr('href'));

        $this->client->request('GET', '/video/wvHsqFCU_Sk');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/video/a-B_c9dEfGh');
        self::assertResponseIsSuccessful();
    }

    public function testAnUnknownVideoIsANotFound(): void
    {
        $this->client->request('GET', '/video/doesNotExist');

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheTypeOfAVideoCanBeCorrectedByHand(): void
    {
        $this->createVideo('misclassified', 40);

        $this->client->request('POST', '/video/misclassified/type', [
            '_token' => $this->csrfToken('submit'),
            'type' => 'short',
        ]);

        self::assertResponseRedirects('/video/misclassified');

        $reloaded = self::getContainer()->get(\App\Catalog\Repository\VideoRepository::class)->find('misclassified');
        self::assertNotNull($reloaded);
        self::assertSame(VideoType::Short, $reloaded->getType());
    }

    public function testCorrectingATypeRequiresAValidToken(): void
    {
        $this->createVideo('protected', 40);

        $this->client->request('POST', '/video/protected/type', ['_token' => 'wrong', 'type' => 'short']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheProposalsOfAnAnalysisAreShown(): void
    {
        $video = $this->createVideo('analysed', 60);
        $this->createAnalysis($video, readyProposals: 4);

        $this->client->request('GET', '/video/analysed');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Proposed thumbnails');
        self::assertSelectorTextContains('body', '4 of 5 ready');
    }

    public function testTheComparisonPageRendersTheFeedPreview(): void
    {
        $video = $this->createVideo('comparable', 60);
        $analysis = $this->createAnalysis($video);
        $proposal = self::getContainer()->get(\App\Thumbnail\Repository\ThumbnailProposalRepository::class)
            ->findForAnalysis((int) $analysis->getId())[0];

        $this->client->request('GET', \sprintf('/proposition/%d/apercu', $proposal->getId()));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'In a YouTube feed');
        self::assertSelectorTextContains('body', 'Prompt sent to Gemini');
    }

    public function testAProposalWithoutImageHasNoComparisonPage(): void
    {
        $video = $this->createVideo('pending', 60);
        $analysis = $this->createAnalysis($video, readyProposals: 0);
        $proposal = self::getContainer()->get(\App\Thumbnail\Repository\ThumbnailProposalRepository::class)
            ->findForAnalysis((int) $analysis->getId())[0];

        $this->client->request('GET', \sprintf('/proposition/%d/apercu', $proposal->getId()));

        self::assertResponseStatusCodeSame(404);
    }

    public function testGeneratedImagesAreServedBehindTheFirewall(): void
    {
        $video = $this->createVideo('withImage', 60);
        $analysis = $this->createAnalysis($video, readyProposals: 1);
        $proposal = self::getContainer()->get(\App\Thumbnail\Repository\ThumbnailProposalRepository::class)
            ->findForAnalysis((int) $analysis->getId())[0];

        $this->client->request('GET', '/images/miniature/' . $proposal->getImagePath());

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/png');
    }

    public function testAMissingImageIsANotFound(): void
    {
        $this->client->request('GET', '/images/miniature/nowhere/missing.png');

        self::assertResponseStatusCodeSame(404);
    }

    public function testDownloadingAProposalSendsAnAttachment(): void
    {
        $video = $this->createVideo('downloadable', 60);
        $analysis = $this->createAnalysis($video, readyProposals: 1);
        $proposal = self::getContainer()->get(\App\Thumbnail\Repository\ThumbnailProposalRepository::class)
            ->findForAnalysis((int) $analysis->getId())[0];

        $this->client->request('GET', \sprintf('/proposition/%d/telecharger', $proposal->getId()));
        self::assertResponseRedirects();

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
    }
}
