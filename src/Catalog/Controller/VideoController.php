<?php

declare(strict_types=1);

namespace App\Catalog\Controller;

use App\Analysis\AnalysisLauncher;
use App\Analysis\Repository\AnalysisRepository;
use App\Analysis\Repository\TranscriptRepository;
use App\Catalog\Chart\VideoChartFactory;
use App\Catalog\Entity\Video;
use App\Catalog\Entity\VideoType;
use App\Catalog\Model\VideoStatsSummarizer;
use App\Catalog\Repository\DailyStatRepository;
use App\Relaunch\Repository\RelaunchRepository;
use App\Scoring\ScoringConfiguration;
use App\YouTube\Repository\ChannelRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Everything about one video: why it is ranked here, and what to do about it.
 */
#[Route('/video/{id}', requirements: ['id' => Video::ID_PATTERN])]
final class VideoController extends AbstractController
{
    public function __construct(
        private readonly DailyStatRepository $dailyStats,
        private readonly VideoStatsSummarizer $summarizer,
        private readonly ScoringConfiguration $scoring,
        private readonly ChannelRepository $channels,
        private readonly AnalysisRepository $analyses,
        private readonly TranscriptRepository $transcripts,
        private readonly RelaunchRepository $relaunches,
        private readonly VideoChartFactory $charts,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'app_video', methods: ['GET'])]
    public function show(#[MapEntity(mapping: ['id' => 'youtubeId'])] Video $video): Response
    {
        $parameters = $this->scoring->parameters();
        $channel = $this->channels->findConnected();
        $referenceDate = $channel?->getAnalyticsAvailableUntil() ?? new \DateTimeImmutable('-3 days');
        $history = $this->dailyStats->loadSeries($video->getYoutubeId());

        return $this->render('video/show.html.twig', [
            'video' => $video,
            'channel' => $channel,
            'stats' => $this->summarizer->summarize($video, $history, $referenceDate, $parameters->windowDays, $parameters->warmupDays),
            'parameters' => $parameters,
            'referenceDate' => $referenceDate,
            'chart' => $this->charts->lifetimeViews($history, $referenceDate),
            'analysis' => $this->analyses->findLatestForVideo($video->getYoutubeId()),
            'transcript' => $this->transcripts->findForVideo($video->getYoutubeId()),
            'relaunches' => $this->relaunches->findAllForVideo($video->getYoutubeId()),
            'analysisQuotaCost' => AnalysisLauncher::quotaCost(),
        ]);
    }

    #[Route('/type', name: 'app_video_type', methods: ['POST'])]
    public function forceType(Request $request, #[MapEntity(mapping: ['id' => 'youtubeId'])] Video $video): Response
    {
        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $type = VideoType::tryFrom((string) $request->request->get('type', ''));
        if (null === $type) {
            $this->addFlash('error', new TranslatableMessage('video.flash.unknown_type'));

            return $this->redirectToRoute('app_video', ['id' => $video->getYoutubeId()]);
        }

        $video->forceType($type);
        $this->entityManager->flush();

        $this->addFlash('success', new TranslatableMessage('video.flash.type_corrected'));

        return $this->redirectToRoute('app_video', ['id' => $video->getYoutubeId()]);
    }
}
