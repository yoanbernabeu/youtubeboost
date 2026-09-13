<?php

declare(strict_types=1);

namespace App\Settings\Controller;

use App\Settings\Entity\ReferenceAngle;
use App\Settings\Entity\ReferencePhoto;
use App\Settings\InvalidReferencePhotoException;
use App\Settings\ReferencePhotoManager;
use App\Settings\Repository\ReferencePhotoRepository;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\YouTube\OAuth\GoogleOAuthClient;
use App\YouTube\Repository\ChannelRepository;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * The three step assistant shown until the instance is usable.
 */
#[Route('/bienvenue')]
final class OnboardingController extends AbstractController
{
    private const string STEP_GOOGLE = 'google';
    private const string STEP_PHOTOS = 'photos';
    private const string STEP_STYLE = 'style';

    public function __construct(
        private readonly ChannelRepository $channels,
        private readonly ReferencePhotoRepository $photos,
        private readonly ReferencePhotoManager $photoManager,
        private readonly Settings $settings,
        private readonly GoogleOAuthClient $oauth,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('', name: 'app_onboarding', methods: ['GET'])]
    public function show(Request $request): Response
    {
        $channel = $this->channels->findConnected();
        $hasPhotos = $this->photos->hasMinimumCoverage();

        $requested = (string) $request->query->get('etape', '');
        $step = match (true) {
            null === $channel => self::STEP_GOOGLE,
            \in_array($requested, [self::STEP_GOOGLE, self::STEP_PHOTOS, self::STEP_STYLE], true) => $requested,
            !$hasPhotos => self::STEP_PHOTOS,
            default => self::STEP_STYLE,
        };

        return $this->render('onboarding/show.html.twig', [
            'step' => $step,
            'channel' => $channel,
            'oauthConfigured' => $this->oauth->isConfigured(),
            'redirectUri' => $this->oauth->getRedirectUri(),
            'photos' => $this->photos->findAllOrdered(),
            'missingAngles' => $this->photoManager->missingRequiredAngles(),
            'hasPhotos' => $hasPhotos,
            'angles' => ReferenceAngle::cases(),
            'styleGuidelines' => $this->settings->getString(SettingKey::StyleGuidelines),
            'completed' => $this->settings->isOnboardingCompleted(),
            'geminiConfigured' => $this->settings->isGeminiConfigured(),
        ]);
    }

    #[Route('/photos', name: 'app_onboarding_photos', methods: ['POST'])]
    public function uploadPhotos(Request $request): Response
    {
        $this->assertCsrf($request);

        $angle = ReferenceAngle::tryFrom((string) $request->request->get('angle', '')) ?? ReferenceAngle::Other;
        $label = (string) $request->request->get('label', '');

        $uploaded = $request->files->all()['photos'] ?? null;
        $files = array_values(array_filter(
            \is_array($uploaded) ? $uploaded : [],
            static fn (mixed $file): bool => $file instanceof UploadedFile,
        ));

        if ([] === $files) {
            $this->addFlash('warning', new TranslatableMessage('settings.flash.choose_photo'));

            return $this->redirectToRoute('app_onboarding', ['etape' => 'photos']);
        }

        $added = 0;
        foreach ($files as $file) {
            try {
                $this->photoManager->add($file, $angle, $label);
                ++$added;
            } catch (InvalidReferencePhotoException $exception) {
                $this->addFlash('error', $exception->userMessage);
            }
        }

        if ($added > 0) {
            $this->addFlash('success', new TranslatableMessage('settings.flash.photos_added', ['%count%' => $added]));
        }

        return $this->redirectToRoute('app_onboarding', ['etape' => 'photos']);
    }

    /**
     * Deleting has to be possible here too: a wrong angle or a bad shot is noticed
     * while the assistant is still open, and sending the creator to the settings
     * screen to undo it would drop them out of the three steps.
     */
    #[Route('/photos/{id<\d+>}/supprimer', name: 'app_onboarding_photo_delete', methods: ['POST'])]
    public function deletePhoto(Request $request, ReferencePhoto $photo): Response
    {
        $this->assertCsrf($request);

        $this->photoManager->remove($photo);
        $this->addFlash('success', new TranslatableMessage('settings.flash.photo_deleted'));

        return $this->redirectToRoute('app_onboarding', ['etape' => self::STEP_PHOTOS], Response::HTTP_SEE_OTHER);
    }

    #[Route('/style', name: 'app_onboarding_style', methods: ['POST'])]
    public function saveStyle(Request $request): Response
    {
        $this->assertCsrf($request);

        $this->settings->set(SettingKey::StyleGuidelines, trim((string) $request->request->get('styleGuidelines', '')));

        if (null === $this->channels->findConnected()) {
            $this->addFlash('warning', new TranslatableMessage('onboarding.flash.connect_channel_first'));

            return $this->redirectToRoute('app_onboarding', ['etape' => 'google']);
        }

        if (!$this->photos->hasMinimumCoverage()) {
            $this->addFlash('warning', new TranslatableMessage('onboarding.flash.add_every_angle'));

            return $this->redirectToRoute('app_onboarding', ['etape' => 'photos']);
        }

        $this->settings->set(
            SettingKey::OnboardingCompletedAt,
            \DateTimeImmutable::createFromInterface($this->clock->now())->format(\DATE_ATOM),
        );
        $this->addFlash('success', new TranslatableMessage('onboarding.flash.completed'));

        return $this->redirectToRoute('app_catalog');
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }
}
