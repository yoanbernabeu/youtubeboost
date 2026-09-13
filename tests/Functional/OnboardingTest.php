<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Settings\Controller\OnboardingController;
use App\Settings\EventListener\OnboardingGuard;
use App\Settings\Repository\ReferencePhotoRepository;
use App\Settings\SettingKey;
use App\Tests\Factory\ChannelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\File\UploadedFile;

#[CoversClass(OnboardingController::class)]
#[CoversClass(OnboardingGuard::class)]
final class OnboardingTest extends FunctionalTestCase
{
    public function testAFreshInstallLandsOnTheAssistant(): void
    {
        $this->logIn();

        $this->client->request('GET', '/');

        self::assertResponseRedirects('/bienvenue');
    }

    public function testTheFirstStepAsksForTheChannel(): void
    {
        $this->logIn();

        $this->client->request('GET', '/bienvenue');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Connect your channel');
        self::assertSelectorTextContains('body', 'https://boost.test/oauth/callback');
    }

    public function testTheSecondStepIsReachedOnceTheChannelIsConnected(): void
    {
        $this->logIn();
        ChannelFactory::createOne();

        $this->client->request('GET', '/bienvenue');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Your reference photos');
    }

    public function testPhotosAreUploadedAndStored(): void
    {
        $this->logIn();
        ChannelFactory::createOne();

        $this->client->request('POST', '/bienvenue/photos', [
            '_token' => $this->csrfToken('submit'),
            'angle' => 'front',
            'label' => 'Studio',
        ], [
            'photos' => [$this->jpegUpload()],
        ]);

        self::assertResponseRedirects('/bienvenue?etape=photos');

        $photos = self::getContainer()->get(ReferencePhotoRepository::class)->findAllOrdered();
        self::assertCount(1, $photos);
        self::assertSame('Studio', $photos[0]->getLabel());
        self::assertSame('image/jpeg', $photos[0]->getMimeType());
    }

    public function testAPhotoCanBeDeletedWithoutLeavingTheAssistant(): void
    {
        $this->logIn();
        ChannelFactory::createOne();

        $this->client->request('POST', '/bienvenue/photos', [
            '_token' => $this->csrfToken('submit'),
            'angle' => 'front',
            'label' => 'Raté',
        ], [
            'photos' => [$this->jpegUpload()],
        ]);

        $photos = self::getContainer()->get(ReferencePhotoRepository::class)->findAllOrdered();
        self::assertCount(1, $photos);

        $this->client->request('POST', '/bienvenue/photos/' . $photos[0]->getId() . '/supprimer', [
            '_token' => $this->csrfToken('submit'),
        ]);

        self::assertResponseRedirects('/bienvenue?etape=photos');
        self::assertCount(0, self::getContainer()->get(ReferencePhotoRepository::class)->findAllOrdered());

        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Photo deleted');
    }

    public function testTheDeleteButtonIsOfferedForEveryStoredPhoto(): void
    {
        $this->logIn();
        ChannelFactory::createOne();

        $this->client->request('POST', '/bienvenue/photos', [
            '_token' => $this->csrfToken('submit'),
            'angle' => 'front',
            'label' => 'Studio',
        ], [
            'photos' => [$this->jpegUpload()],
        ]);

        $photo = self::getContainer()->get(ReferencePhotoRepository::class)->findAllOrdered()[0];
        $crawler = $this->client->followRedirect();

        self::assertCount(
            1,
            $crawler->filter('form[action="/bienvenue/photos/' . $photo->getId() . '/supprimer"]'),
            'The stored photo must carry its own delete form.',
        );
    }

    public function testDeletingAPhotoIsCsrfProtected(): void
    {
        $this->logIn();
        ChannelFactory::createOne();

        $this->client->request('POST', '/bienvenue/photos', [
            '_token' => $this->csrfToken('submit'),
            'angle' => 'front',
        ], [
            'photos' => [$this->jpegUpload()],
        ]);

        $photo = self::getContainer()->get(ReferencePhotoRepository::class)->findAllOrdered()[0];

        $this->client->request('POST', '/bienvenue/photos/' . $photo->getId() . '/supprimer', ['_token' => 'wrong']);

        self::assertResponseStatusCodeSame(403);
        self::assertCount(1, self::getContainer()->get(ReferencePhotoRepository::class)->findAllOrdered());
    }

    public function testANonImageUploadIsRefusedWithAMessage(): void
    {
        $this->logIn();
        ChannelFactory::createOne();

        $path = tempnam(sys_get_temp_dir(), 'yb') . '.txt';
        file_put_contents($path, 'certainly not an image');

        $this->client->request('POST', '/bienvenue/photos', [
            '_token' => $this->csrfToken('submit'),
            'angle' => 'front',
        ], [
            'photos' => [new UploadedFile($path, 'notes.txt', 'text/plain', null, true)],
        ]);

        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'is neither a JPEG');
        self::assertCount(0, self::getContainer()->get(ReferencePhotoRepository::class)->findAllOrdered());
    }

    public function testFinishingWithoutEveryAngleSendsBackToThePhotos(): void
    {
        $this->logIn();
        ChannelFactory::createOne();

        $this->client->request('POST', '/bienvenue/style', [
            '_token' => $this->csrfToken('submit'),
            'styleGuidelines' => 'Fond sombre.',
        ]);

        self::assertResponseRedirects('/bienvenue?etape=photos');
        self::assertFalse($this->settings()->isOnboardingCompleted());
        self::assertSame('Fond sombre.', $this->settings()->getString(SettingKey::StyleGuidelines));
    }

    public function testFinishingOpensTheCatalogue(): void
    {
        $this->logIn();
        $this->completeOnboardingWithoutFlag();

        $this->client->request('POST', '/bienvenue/style', [
            '_token' => $this->csrfToken('submit'),
            'styleGuidelines' => 'Palette jaune.',
        ]);

        self::assertResponseRedirects('/');
        self::assertTrue($this->settings()->isOnboardingCompleted());
    }

    public function testTheAssistantStaysReachableOnceFinished(): void
    {
        $this->completeOnboarding();
        $this->logIn();

        $this->client->request('GET', '/bienvenue');

        self::assertResponseIsSuccessful();
    }

    private function completeOnboardingWithoutFlag(): void
    {
        ChannelFactory::createOne();
        foreach (['front', 'right', 'left'] as $angle) {
            $this->client->request('POST', '/bienvenue/photos', [
                '_token' => $this->csrfToken('submit'),
                'angle' => $angle,
            ], [
                'photos' => [$this->jpegUpload()],
            ]);
        }
    }

    private function jpegUpload(): UploadedFile
    {
        $image = imagecreatetruecolor(400, 400);
        self::assertNotFalse($image);
        ob_start();
        imagejpeg($image, null, 80);
        $binary = (string) ob_get_clean();

        $path = tempnam(sys_get_temp_dir(), 'yb') . '.jpg';
        file_put_contents($path, $binary);

        return new UploadedFile($path, 'portrait.jpg', 'image/jpeg', null, true);
    }
}
