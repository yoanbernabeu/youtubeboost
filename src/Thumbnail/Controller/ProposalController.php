<?php

declare(strict_types=1);

namespace App\Thumbnail\Controller;

use App\Relaunch\Exception\RelaunchFailedException;
use App\Relaunch\RelaunchLauncher;
use App\Thumbnail\Entity\ThumbnailProposal;
use App\Thumbnail\ProposalLauncher;
use App\Thumbnail\Repository\ThumbnailProposalRepository;
use App\YouTube\Exception\YouTubeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Actions on one thumbnail proposal.
 *
 * Plain form posts rather than live actions: each of these either costs money or
 * writes to YouTube, so a real navigation that ends on a flash message is the
 * clearer contract.
 */
#[Route('/proposition/{id<\d+>}')]
final class ProposalController extends AbstractController
{
    public function __construct(
        private readonly ThumbnailProposalRepository $proposals,
        private readonly ProposalLauncher $launcher,
        private readonly RelaunchLauncher $relaunches,
    ) {
    }

    #[Route('/regenerer', name: 'app_proposal_regenerate', methods: ['POST'])]
    public function regenerate(Request $request, ThumbnailProposal $proposal): Response
    {
        $this->assertCsrf($request);

        $this->launcher->regenerate($proposal, (string) $request->request->get('prompt', ''));
        $this->addFlash('success', new TranslatableMessage('thumbnail.flash.regenerated', ['%number%' => $proposal->getAngleIndex() + 1]));

        return $this->backToVideo($proposal);
    }

    #[Route('/iterer', name: 'app_proposal_iterate', methods: ['POST'])]
    public function iterate(Request $request, ThumbnailProposal $proposal): Response
    {
        $this->assertCsrf($request);

        $instruction = trim((string) $request->request->get('instruction', ''));
        if ('' === $instruction) {
            $this->addFlash('warning', new TranslatableMessage('thumbnail.flash.instruction_required'));

            return $this->backToVideo($proposal);
        }

        if (!$proposal->isReady()) {
            $this->addFlash('warning', new TranslatableMessage('thumbnail.flash.not_ready_yet'));

            return $this->backToVideo($proposal);
        }

        $this->launcher->iterate($proposal, $instruction);
        $this->addFlash('success', new TranslatableMessage('thumbnail.flash.iterating'));

        return $this->backToVideo($proposal);
    }

    #[Route('/appliquer', name: 'app_proposal_apply', methods: ['POST'])]
    public function apply(Request $request, ThumbnailProposal $proposal): Response
    {
        $this->assertCsrf($request);

        try {
            $this->relaunches->apply($proposal);
        } catch (RelaunchFailedException $exception) {
            $this->addFlash('error', $exception->translatableMessage());

            return $this->backToVideo($proposal);
        } catch (YouTubeException $exception) {
            $this->addFlash('error', $exception->getUserMessage());

            return $this->backToVideo($proposal);
        }

        $this->addFlash('success', new TranslatableMessage('thumbnail.flash.applying'));

        return $this->backToVideo($proposal);
    }

    #[Route('/apercu', name: 'app_proposal_preview', methods: ['GET'])]
    public function preview(ThumbnailProposal $proposal): Response
    {
        if (!$proposal->isReady()) {
            throw $this->createNotFoundException('Cette proposition n\'a pas encore d\'image.');
        }

        return $this->render('thumbnail/preview.html.twig', [
            'proposal' => $proposal,
            'video' => $proposal->getVideo(),
            'chain' => $this->proposals->findChain($proposal),
            'currentThumbnail' => $proposal->getVideo()->getThumbnails()->preview(),
            'applyQuotaCost' => RelaunchLauncher::quotaCost(),
        ]);
    }

    #[Route('/telecharger', name: 'app_proposal_download', methods: ['GET'])]
    public function download(ThumbnailProposal $proposal): Response
    {
        if (!$proposal->isReady()) {
            throw $this->createNotFoundException('Cette proposition n\'a pas encore d\'image.');
        }

        return $this->redirectToRoute('app_image', [
            'kind' => 'miniature',
            'path' => (string) $proposal->getImagePath(),
            'download' => true,
        ]);
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }

    private function backToVideo(ThumbnailProposal $proposal): Response
    {
        return $this->redirectToRoute('app_video', ['id' => $proposal->getVideo()->getYoutubeId()], Response::HTTP_SEE_OTHER);
    }
}
