<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Scoring\Model\Signal;
use App\Scoring\ScoringConfiguration;
use App\Settings\Controller\SettingsController;
use App\Settings\Repository\ReferencePhotoRepository;
use App\Settings\SettingKey;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SettingsController::class)]
final class SettingsTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->completeOnboarding();
        $this->logIn();
    }

    public function testThePageShowsEverySection(): void
    {
        $this->client->request('GET', '/reglages');

        self::assertResponseIsSuccessful();
        foreach (['Relaunch scoring', 'Style guidelines', 'Reference photos', 'Models, pacing and verdicts', 'YouTube channel and quota'] as $section) {
            self::assertSelectorTextContains('body', $section);
        }
    }

    public function testTheWeightsAreSaved(): void
    {
        $this->client->request('POST', '/reglages/scoring', [
            '_token' => $this->csrfToken('submit'),
            'weights' => ['decline' => 50, 'low_ctr' => 10, 'impressions' => 5, 'potential' => 35],
            'minAgeDays' => 90,
            'windowDays' => 14,
            'warmupDays' => 21,
            'ctrFloorRatio' => 0.4,
            'retentionTargetRatio' => 1.5,
            'relaunchCooldownDays' => 42,
        ]);

        self::assertResponseRedirects('/reglages');

        $configuration = self::getContainer()->get(ScoringConfiguration::class);
        self::assertSame(50.0, $configuration->weights()->get(Signal::Decline));
        self::assertSame(90, $configuration->parameters()->minAgeDays);
        self::assertSame(14, $configuration->parameters()->windowDays);
        self::assertSame(42, $configuration->parameters()->relaunchCooldownDays);
    }

    public function testOutOfRangeWeightsAreClampedInsteadOfCrashing(): void
    {
        $this->client->request('POST', '/reglages/scoring', [
            '_token' => $this->csrfToken('submit'),
            'weights' => ['decline' => 9999, 'low_ctr' => -50],
            'windowDays' => 1,
        ]);

        self::assertResponseRedirects('/reglages');

        $configuration = self::getContainer()->get(ScoringConfiguration::class);
        self::assertSame(100.0, $configuration->weights()->get(Signal::Decline));
        self::assertSame(0.0, $configuration->weights()->get(Signal::LowCtr));
        self::assertSame(7, $configuration->parameters()->windowDays);
    }

    public function testTheStyleGuidelinesAreSaved(): void
    {
        $this->client->request('POST', '/reglages/style', [
            '_token' => $this->csrfToken('submit'),
            'styleGuidelines' => "  Fond sombre.\nTypo jaune.  ",
        ]);

        self::assertResponseRedirects('/reglages');
        self::assertSame("Fond sombre.\nTypo jaune.", $this->settings()->getString(SettingKey::StyleGuidelines));
    }

    public function testTheModelsAndThresholdsAreSaved(): void
    {
        $this->client->request('POST', '/reglages/generation', [
            '_token' => $this->csrfToken('submit'),
            'textModel' => 'gemini-3.5-flash',
            'imageModel' => '',
            'analyticsRequestDelayMs' => 400,
            'successViewsRatio' => 2.0,
            'neutralFloorRatio' => 0.75,
            'negativeCtrRatio' => 0.9,
        ]);

        self::assertResponseRedirects('/reglages');
        self::assertSame('gemini-3.5-flash', $this->settings()->getTextModel());
        self::assertSame('gemini-3.1-flash-image', $this->settings()->getImageModel(), 'An empty field falls back on the environment.');
        self::assertSame(400, $this->settings()->getInt(SettingKey::AnalyticsRequestDelayMs));
        self::assertSame(2.0, $this->settings()->getFloat(SettingKey::VerdictSuccessViewsRatio));
    }

    public function testAPhotoCanBeRelabelledAndDeleted(): void
    {
        $photos = self::getContainer()->get(ReferencePhotoRepository::class)->findAllOrdered();
        $photo = $photos[0];

        $this->client->request('POST', '/reglages/photos/' . $photo->getId(), [
            '_token' => $this->csrfToken('submit'),
            'angle' => 'other',
            'label' => 'Sourire',
        ]);
        self::assertResponseRedirects('/reglages');

        $this->client->request('POST', '/reglages/photos/' . $photo->getId() . '/supprimer', [
            '_token' => $this->csrfToken('submit'),
        ]);
        self::assertResponseRedirects('/reglages');

        self::assertCount(2, self::getContainer()->get(ReferencePhotoRepository::class)->findAllOrdered());
    }

    public function testEverySettingsFormIsCsrfProtected(): void
    {
        foreach (['/reglages/scoring', '/reglages/style', '/reglages/generation', '/reglages/photos'] as $path) {
            $this->client->request('POST', $path, ['_token' => 'wrong']);

            self::assertResponseStatusCodeSame(403, \sprintf('%s must reject a bad token.', $path));
        }
    }

    public function testAnExpiredAuthorisationIsAnnouncedOnEveryPage(): void
    {
        $channel = self::getContainer()->get(\App\YouTube\Repository\ChannelRepository::class)->findConnected();
        self::assertNotNull($channel);
        $channel->markReconnectionRequired();
        $this->entityManager()->flush();

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'The YouTube connection has expired');
    }

    public function testAHealthyChannelShowsNoBanner(): void
    {
        $this->client->request('GET', '/');

        self::assertSelectorTextNotContains('body', 'The YouTube connection has expired');
    }

    public function testDisconnectingTheChannelSendsBackToTheAssistant(): void
    {
        $this->client->request('POST', '/oauth/deconnecter', ['_token' => $this->csrfToken('submit')]);

        self::assertResponseRedirects('/bienvenue');
    }
}
