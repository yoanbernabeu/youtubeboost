<?php

declare(strict_types=1);

namespace App\Analysis\Controller;

use App\Analysis\AnalysisLauncher;
use App\Analysis\Entity\Analysis;
use App\Analysis\Exception\AnalysisFailedException;
use App\Catalog\Entity\Video;
use App\Thumbnail\ProposalLauncher;
use App\YouTube\Exception\YouTubeException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\TranslatableMessage;

final class AnalysisController extends AbstractController
{
    public function __construct(
        private readonly AnalysisLauncher $launcher,
        private readonly ProposalLauncher $proposals,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/video/{id}/analyser', name: 'app_analysis_start', requirements: ['id' => Video::ID_PATTERN], methods: ['POST'])]
    public function start(Request $request, #[MapEntity(mapping: ['id' => 'youtubeId'])] Video $video): Response
    {
        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        try {
            $this->launcher->launch($video);
        } catch (AnalysisFailedException $exception) {
            $this->addFlash('error', $exception->translatableMessage());

            return $this->redirectToRoute('app_video', ['id' => $video->getYoutubeId()]);
        } catch (YouTubeException $exception) {
            $this->addFlash('error', $exception->getUserMessage());

            return $this->redirectToRoute('app_video', ['id' => $video->getYoutubeId()]);
        }

        $this->addFlash('success', new TranslatableMessage('analysis.flash.started'));

        return $this->redirectToRoute('app_video', ['id' => $video->getYoutubeId()], Response::HTTP_SEE_OTHER);
    }

    /**
     * Queues the five thumbnails of an existing analysis again, without paying
     * for the transcript and the text model a second time.
     */
    #[Route('/analyse/{id<\d+>}/regenerer', name: 'app_analysis_regenerate', methods: ['POST'])]
    public function regenerateAll(Request $request, Analysis $analysis): Response
    {
        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->proposals->launchForAnalysis($analysis);
        $this->entityManager->flush();

        $this->addFlash('success', new TranslatableMessage('analysis.flash.regenerating'));

        return $this->redirectToRoute('app_video', ['id' => $analysis->getVideo()->getYoutubeId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/analyse/{id<\d+>}/angle/{index<\d+>}', name: 'app_analysis_edit_prompt', methods: ['POST'])]
    public function editPrompt(Request $request, Analysis $analysis, int $index): Response
    {
        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $prompt = trim((string) $request->request->get('imagePrompt', ''));
        if ('' === $prompt) {
            $this->addFlash('warning', new TranslatableMessage('analysis.flash.prompt_required'));

            return $this->redirectToRoute('app_video', ['id' => $analysis->getVideo()->getYoutubeId()]);
        }

        try {
            $analysis->replaceAnglePrompt($index, $prompt);
        } catch (\InvalidArgumentException) {
            $this->addFlash('error', new TranslatableMessage('analysis.flash.unknown_angle'));

            return $this->redirectToRoute('app_video', ['id' => $analysis->getVideo()->getYoutubeId()]);
        }

        $this->entityManager->flush();
        $this->addFlash('success', new TranslatableMessage('analysis.flash.prompt_saved'));

        return $this->redirectToRoute('app_video', ['id' => $analysis->getVideo()->getYoutubeId()], Response::HTTP_SEE_OTHER);
    }
}
