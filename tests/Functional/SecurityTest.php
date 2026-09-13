<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Security\Controller\SecurityController;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(SecurityController::class)]
final class SecurityTest extends FunctionalTestCase
{
    public function testTheLoginPageIsPublic(): void
    {
        $this->client->request('GET', '/connexion');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'YoutubeBoost');
        self::assertSelectorExists('input[name="_password"]');
    }

    public function testEveryOtherPageRequiresTheApplicationPassword(): void
    {
        foreach (['/', '/relances', '/reglages', '/bienvenue'] as $path) {
            $this->client->request('GET', $path);

            self::assertResponseRedirects('http://localhost/connexion', null, \sprintf('%s must be protected.', $path));
        }
    }

    public function testTheRightPasswordOpensTheApplication(): void
    {
        $this->completeOnboarding();

        $this->logIn();

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Catalogue');
    }

    public function testAWrongPasswordIsRefused(): void
    {
        $this->client->request('GET', '/connexion');
        $this->client->submitForm('Sign in', ['_password' => 'not-the-password']);
        $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Sign-in refused');
    }

    public function testAnAuthenticatedVisitorIsSentAwayFromTheLoginPage(): void
    {
        $this->completeOnboarding();
        $this->logIn();

        $this->client->request('GET', '/connexion');

        self::assertResponseRedirects('/');
    }

    public function testTheLoginFormSubmitsTheOnlyUsername(): void
    {
        $crawler = $this->client->request('GET', '/connexion');

        self::assertSame(SecurityController::USERNAME, $crawler->filter('input[name="_username"]')->attr('value'));
    }

    public function testLoggingOutEndsTheSession(): void
    {
        $this->completeOnboarding();
        $this->logIn();
        $this->client->followRedirect();

        $this->client->request('POST', '/deconnexion', ['_csrf_token' => $this->csrfToken('logout')]);

        self::assertResponseRedirects();
        $this->client->request('GET', '/');
        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);
    }
}
