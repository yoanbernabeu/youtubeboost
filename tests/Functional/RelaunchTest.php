<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Relaunch\Controller\RelaunchController;
use App\Relaunch\Model\Verdict;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(RelaunchController::class)]
final class RelaunchTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->completeOnboarding();
        $this->logIn();
    }

    public function testAnEmptyHistoryExplainsWhatToDo(): void
    {
        $this->client->request('GET', '/relances');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'No relaunch yet');
    }

    public function testARelaunchIsListedWithItsVerdict(): void
    {
        $video = $this->createVideo('relaunched', 70);
        $this->createRelaunch($video, Verdict::Successful);

        $this->client->request('GET', '/relances');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Successful');
        self::assertSelectorTextContains('body', $video->getTitle());
    }

    public function testTheDetailPageShowsBothMilestones(): void
    {
        $video = $this->createVideo('detailed', 70);
        $relaunch = $this->createRelaunch($video, Verdict::Successful);

        $this->client->request('GET', '/relances/' . $relaunch->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', '14 days');
        self::assertSelectorTextContains('body', '28 days');
        self::assertSelectorTextContains('body', 'Numbers side by side');
    }

    public function testANegativeVerdictSuggestsRollingBack(): void
    {
        $video = $this->createVideo('negative', 70);
        $relaunch = $this->createRelaunch($video, Verdict::Negative);

        $this->client->request('GET', '/relances/' . $relaunch->getId());

        self::assertSelectorTextContains('body', 'pushed the numbers down');
        self::assertSelectorTextContains('body', 'Back to the old one');
    }

    /**
     * The countdown is rendered from a clock the controller owns: Twig's `date()`
     * hands back a mutable `DateTime`, which used to turn this page into a 500.
     */
    public function testARelaunchWithoutMilestoneYetShowsTheCountdown(): void
    {
        $video = $this->createVideo('waiting', 70);
        $this->createRelaunch($video);

        $this->client->request('GET', '/relances');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Pending');
        self::assertSelectorTextContains('body', 'First milestone');
    }

    public function testRevertingRequiresAValidToken(): void
    {
        $video = $this->createVideo('protectedRelaunch', 70);
        $relaunch = $this->createRelaunch($video, Verdict::Negative);

        $this->client->request('POST', '/relances/' . $relaunch->getId() . '/annuler', ['_token' => 'wrong']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnAlreadyRevertedRelaunchCannotBeRevertedTwice(): void
    {
        $video = $this->createVideo('alreadyReverted', 70);
        $relaunch = $this->createRelaunch($video, Verdict::Negative);
        $relaunch->markReverted(new \DateTimeImmutable());
        $this->entityManager()->flush();

        $this->client->request('POST', '/relances/' . $relaunch->getId() . '/annuler', ['_token' => $this->csrfToken('submit')]);

        self::assertResponseRedirects('/relances/' . $relaunch->getId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'already been rolled back');
    }

    public function testAnUnknownRelaunchIsANotFound(): void
    {
        $this->client->request('GET', '/relances/999999');

        self::assertResponseStatusCodeSame(404);
    }
}
