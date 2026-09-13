<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Settings\Locale\AppLocale;
use App\Settings\SettingKey;

/**
 * The language switch, exercised the way the creator uses it: a click in the
 * sidebar, then the next page in the other language.
 */
final class LocaleTest extends FunctionalTestCase
{
    public function testTheInterfaceStartsInEnglish(): void
    {
        $this->completeOnboarding();
        $this->logIn();

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSame('en', $crawler->filter('html')->attr('lang'));
        self::assertStringContainsString('Relaunches', $crawler->filter('nav')->text());
    }

    public function testSwitchingToFrenchChangesTheInterfaceAndSticks(): void
    {
        $this->completeOnboarding();
        $this->logIn();
        $this->client->request('GET', '/');

        $this->client->request('POST', '/locale', [
            'locale' => 'fr',
            '_token' => $this->csrfToken('submit'),
        ]);

        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();

        self::assertSame('fr', $crawler->filter('html')->attr('lang'));
        self::assertStringContainsString('Relances', $crawler->filter('nav')->text());
        self::assertSame('fr', $this->settings()->getString(SettingKey::Locale));

        // The choice is a setting, so it survives the next request untouched.
        $later = $this->client->request('GET', '/');
        self::assertSame('fr', $later->filter('html')->attr('lang'));
    }

    public function testAnUnknownLanguageFallsBackOnTheDefault(): void
    {
        $this->completeOnboarding();
        $this->logIn();

        $this->client->request('POST', '/locale', [
            'locale' => 'kl',
            '_token' => $this->csrfToken('submit'),
        ]);

        self::assertResponseRedirects();
        self::assertSame(AppLocale::default()->value, $this->settings()->getString(SettingKey::Locale));
    }

    public function testSwitchingWithoutATokenIsRefused(): void
    {
        $this->completeOnboarding();
        $this->logIn();

        $this->client->request('POST', '/locale', ['locale' => 'fr']);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * A cross-origin submission never reaches the controller at all: the `submit`
     * token is stateless, so Symfony checks the origin of the request before the
     * action runs. The referer can therefore only ever be one of ours.
     */
    public function testACrossOriginSubmissionIsRefused(): void
    {
        $this->completeOnboarding();
        $this->logIn();

        $this->client->request('POST', '/locale', [
            'locale' => 'fr',
            '_token' => $this->csrfToken('submit'),
        ], [], ['HTTP_REFERER' => 'https://example.invalid/steal']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('', $this->settings()->getString(SettingKey::Locale));
    }

    public function testItComesBackToThePageTheSwitchWasUsedOn(): void
    {
        $video = $this->createVideo();
        $this->completeOnboarding();
        $this->logIn();

        $from = \sprintf('/video/%s', $video->getYoutubeId());
        $this->client->request('GET', $from);

        $this->client->request('POST', '/locale', [
            'locale' => 'fr',
            '_token' => $this->csrfToken('submit'),
        ], [], ['HTTP_REFERER' => 'http://localhost' . $from]);

        self::assertResponseRedirects('http://localhost' . $from);
    }
}
