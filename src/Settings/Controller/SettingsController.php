<?php

declare(strict_types=1);

namespace App\Settings\Controller;

use App\Scoring\Model\Signal;
use App\Scoring\ScoringConfiguration;
use App\Settings\Entity\ReferenceAngle;
use App\Settings\Entity\ReferencePhoto;
use App\Settings\Input\GenerationSettingsInput;
use App\Settings\Input\ScoringSettingsInput;
use App\Settings\InvalidReferencePhotoException;
use App\Settings\ReferencePhotoManager;
use App\Settings\Repository\ReferencePhotoRepository;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\YouTube\OAuth\GoogleOAuthClient;
use App\YouTube\Quota\QuotaTracker;
use App\YouTube\Repository\ChannelRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\TranslatableMessage;

#[Route('/reglages')]
final class SettingsController extends AbstractController
{
    public function __construct(
        private readonly Settings $settings,
        private readonly ScoringConfiguration $scoring,
        private readonly ReferencePhotoRepository $photos,
        private readonly ReferencePhotoManager $photoManager,
        private readonly ChannelRepository $channels,
        private readonly GoogleOAuthClient $oauth,
        private readonly QuotaTracker $quota,
    ) {
    }

    #[Route('', name: 'app_settings', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('settings/index.html.twig', [
            'weights' => $this->scoring->weights(),
            'parameters' => $this->scoring->parameters(),
            'signals' => Signal::cases(),
            'styleGuidelines' => $this->settings->getString(SettingKey::StyleGuidelines),
            'textModel' => $this->settings->getString(SettingKey::GeminiTextModel),
            'imageModel' => $this->settings->getString(SettingKey::GeminiImageModel),
            'effectiveTextModel' => $this->settings->getTextModel(),
            'effectiveImageModel' => $this->settings->getImageModel(),
            'analyticsDelay' => $this->settings->getInt(SettingKey::AnalyticsRequestDelayMs),
            'verdict' => [
                'success' => $this->settings->getFloat(SettingKey::VerdictSuccessViewsRatio),
                'floor' => $this->settings->getFloat(SettingKey::VerdictNeutralFloorRatio),
                'ctr' => $this->settings->getFloat(SettingKey::VerdictNegativeCtrRatio),
            ],
            'photos' => $this->photos->findAllOrdered(),
            'missingAngles' => $this->photoManager->missingRequiredAngles(),
            'angles' => ReferenceAngle::cases(),
            'channel' => $this->channels->findConnected(),
            'redirectUri' => $this->oauth->getRedirectUri(),
            'quota' => $this->quota->snapshot(),
            'quotaBreakdown' => $this->quota->todayPerEndpoint(),
            'imageConfigStyle' => $this->settings->getString(SettingKey::GeminiImageConfigStyle),
            'geminiConfigured' => $this->settings->isGeminiConfigured(),
        ]);
    }

    #[Route('/scoring', name: 'app_settings_scoring', methods: ['POST'])]
    public function saveScoring(Request $request): Response
    {
        $this->assertCsrf($request);

        $this->settings->setMany(ScoringSettingsInput::fromArray($request->request->all())->toSettings());
        $this->addFlash('success', new TranslatableMessage('settings.flash.scoring_saved'));

        return $this->redirectToRoute('app_settings', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/style', name: 'app_settings_style', methods: ['POST'])]
    public function saveStyle(Request $request): Response
    {
        $this->assertCsrf($request);

        $this->settings->set(SettingKey::StyleGuidelines, trim((string) $request->request->get('styleGuidelines', '')));
        $this->addFlash('success', new TranslatableMessage('settings.flash.style_saved'));

        return $this->redirectToRoute('app_settings', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/generation', name: 'app_settings_generation', methods: ['POST'])]
    public function saveGeneration(Request $request): Response
    {
        $this->assertCsrf($request);

        $this->settings->setMany(GenerationSettingsInput::fromArray($request->request->all())->toSettings());
        $this->addFlash('success', new TranslatableMessage('settings.flash.generation_saved'));

        return $this->redirectToRoute('app_settings', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/photos', name: 'app_settings_photos_add', methods: ['POST'])]
    public function addPhotos(Request $request): Response
    {
        $this->assertCsrf($request);

        $angle = ReferenceAngle::tryFrom((string) $request->request->get('angle', '')) ?? ReferenceAngle::Other;
        $label = (string) $request->request->get('label', '');

        $uploaded = $request->files->all()['photos'] ?? null;
        $files = array_values(array_filter(
            \is_array($uploaded) ? $uploaded : [],
            static fn (mixed $file): bool => $file instanceof UploadedFile,
        ));

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
        } elseif ([] === $files) {
            $this->addFlash('warning', new TranslatableMessage('settings.flash.choose_photo'));
        }

        return $this->redirectToRoute('app_settings', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/photos/{id<\d+>}', name: 'app_settings_photo_update', methods: ['POST'])]
    public function updatePhoto(Request $request, ReferencePhoto $photo): Response
    {
        $this->assertCsrf($request);

        $angle = ReferenceAngle::tryFrom((string) $request->request->get('angle', '')) ?? $photo->getAngle();
        $this->photoManager->relabel($photo, $angle, (string) $request->request->get('label', ''));
        $this->addFlash('success', new TranslatableMessage('settings.flash.photo_updated'));

        return $this->redirectToRoute('app_settings', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/photos/{id<\d+>}/supprimer', name: 'app_settings_photo_delete', methods: ['POST'])]
    public function deletePhoto(Request $request, ReferencePhoto $photo): Response
    {
        $this->assertCsrf($request);

        $this->photoManager->remove($photo);
        $this->addFlash('success', new TranslatableMessage('settings.flash.photo_deleted'));

        return $this->redirectToRoute('app_settings', [], Response::HTTP_SEE_OTHER);
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }
}
