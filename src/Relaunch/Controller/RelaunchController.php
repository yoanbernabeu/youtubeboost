<?php

declare(strict_types=1);

namespace App\Relaunch\Controller;

use App\Catalog\Chart\VideoChartFactory;
use App\Catalog\Repository\DailyStatRepository;
use App\Relaunch\Entity\Relaunch;
use App\Relaunch\Exception\RelaunchFailedException;
use App\Relaunch\Model\Comparison;
use App\Relaunch\RelaunchLauncher;
use App\Relaunch\Repository\RelaunchRepository;
use App\YouTube\Exception\YouTubeException;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The history of every thumbnail change, with its verdict.
 */
final class RelaunchController extends AbstractController
{
    public function __construct(
        private readonly RelaunchRepository $relaunches,
        private readonly RelaunchLauncher $launcher,
        private readonly DailyStatRepository $dailyStats,
        private readonly VideoChartFactory $charts,
        private readonly ClockInterface $clock,
        // The chart dataset labels end up inside a JSON configuration read by
        // Chart.js, never inside a Twig node, so `|trans` can never reach them.
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/relances', name: 'app_relaunches', methods: ['GET'])]
    public function index(): Response
    {
        $relaunches = $this->relaunches->findAllRecentFirst();

        $now = \DateTimeImmutable::createFromInterface($this->clock->now());

        $comparisons = [];
        $countdowns = [];
        foreach ($relaunches as $relaunch) {
            $id = (int) $relaunch->getId();
            $after = $relaunch->getSnapshotAt28() ?? $relaunch->getSnapshotAt14();
            $comparisons[$id] = null === $after
                ? null
                : new Comparison($relaunch->getSnapshotBefore(), $after);
            $countdowns[$id] = $relaunch->daysUntilFirstMilestone($now);
        }

        return $this->render('relaunch/index.html.twig', [
            'relaunches' => $relaunches,
            'comparisons' => $comparisons,
            'countdowns' => $countdowns,
            'statistics' => $this->relaunches->statistics(),
        ]);
    }

    #[Route('/relances/{id<\d+>}', name: 'app_relaunch_show', methods: ['GET'])]
    public function show(Relaunch $relaunch): Response
    {
        $after = $relaunch->getSnapshotAt28() ?? $relaunch->getSnapshotAt14();
        $before = $relaunch->getSnapshotBefore();

        return $this->render('relaunch/show.html.twig', [
            'relaunch' => $relaunch,
            'video' => $relaunch->getVideo(),
            'comparison' => null === $after ? null : new Comparison($before, $after),
            'chart' => $this->charts->lifetimeViews($this->dailyStats->loadSeries($relaunch->getVideo()->getYoutubeId())),
            'beforeAfterChart' => null === $after ? null : $this->charts->beforeAfter([
                $this->translator->trans('relaunch.metric.views_per_day') => ['before' => $before->viewsPerDay, 'after' => $after->viewsPerDay],
                $this->translator->trans('relaunch.metric.impressions_per_day') => ['before' => $before->impressionsPerDay, 'after' => $after->impressionsPerDay],
                $this->translator->trans('relaunch.metric.ctr') => ['before' => $before->clickThroughRate, 'after' => $after->clickThroughRate],
                $this->translator->trans('relaunch.metric.average_duration') => ['before' => $before->averageViewDuration, 'after' => $after->averageViewDuration],
            ]),
            'revertQuotaCost' => RelaunchLauncher::quotaCost(),
        ]);
    }

    #[Route('/relances/{id<\d+>}/annuler', name: 'app_relaunch_revert', methods: ['POST'])]
    public function revert(Request $request, Relaunch $relaunch): Response
    {
        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        if (!$relaunch->canBeReverted()) {
            $this->addFlash('warning', new TranslatableMessage('relaunch.flash.already_reverted'));

            return $this->redirectToRoute('app_relaunch_show', ['id' => $relaunch->getId()]);
        }

        try {
            $this->launcher->revert($relaunch);
        } catch (RelaunchFailedException $exception) {
            $this->addFlash('error', $exception->translatableMessage());

            return $this->redirectToRoute('app_relaunch_show', ['id' => $relaunch->getId()]);
        } catch (YouTubeException $exception) {
            $this->addFlash('error', $exception->getUserMessage());

            return $this->redirectToRoute('app_relaunch_show', ['id' => $relaunch->getId()]);
        }

        $this->addFlash('success', new TranslatableMessage('relaunch.flash.reverting'));

        return $this->redirectToRoute('app_relaunch_show', ['id' => $relaunch->getId()], Response::HTTP_SEE_OTHER);
    }
}
