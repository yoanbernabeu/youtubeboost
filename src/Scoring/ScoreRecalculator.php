<?php

declare(strict_types=1);

namespace App\Scoring;

use App\Catalog\Repository\DailyStatRepository;
use App\Catalog\Repository\VideoRepository;
use App\Scoring\Calculator\ChannelBaselineCalculator;
use App\Scoring\Calculator\RelaunchScorer;
use App\Scoring\Model\ChannelBaseline;
use App\Scoring\Model\ScoringContext;
use App\Scoring\Model\VideoMetrics;
use App\Shared\Progress\ProgressReporterInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Recomputes the relaunch score of the whole catalogue.
 *
 * Two passes are needed: the channel medians a video is compared against can
 * only be known once every video has been looked at. Histories are streamed one
 * video at a time so a catalogue of several hundred thousand daily rows never
 * sits in memory at once.
 */
final class ScoreRecalculator
{
    public function __construct(
        private readonly VideoRepository $videos,
        private readonly DailyStatRepository $dailyStats,
        private readonly RelaunchScorer $scorer,
        private readonly ChannelBaselineCalculator $baselines,
        private readonly ScoringConfiguration $configuration,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return int the number of videos rescored
     */
    public function recalculate(\DateTimeImmutable $referenceDate, ProgressReporterInterface $reporter): int
    {
        $parameters = $this->configuration->parameters();
        $weights = $this->configuration->weights();

        $reporter->step(new TranslatableMessage('scoring.step.baselines'));
        $baseline = $this->baselines->calculate($this->streamRelaunchable(), $parameters, $referenceDate);

        $context = new ScoringContext($baseline, $parameters, $referenceDate);

        $reporter->step(new TranslatableMessage('scoring.step.scoring'));
        $videoIds = $this->videos->allIds();
        $total = \count($videoIds);
        $scored = 0;

        foreach ($videoIds as $index => $videoId) {
            $video = $this->videos->find($videoId);
            if (null === $video) {
                continue;
            }

            $metrics = new VideoMetrics($video, $this->dailyStats->loadSeries($videoId));
            $result = $this->scorer->score($metrics, $context, $video->getLastRelaunchAt(), $weights);
            $video->applyScore($result->score, $referenceDate);
            ++$scored;

            if (0 === ($index + 1) % 25) {
                $this->entityManager->flush();
            }

            $reporter->progress($index + 1, $total, new TranslatableMessage('scoring.step.scoring'));
        }

        $this->entityManager->flush();

        return $scored;
    }

    public function baselineFor(\DateTimeImmutable $referenceDate): ChannelBaseline
    {
        return $this->baselines->calculate($this->streamRelaunchable(), $this->configuration->parameters(), $referenceDate);
    }

    /**
     * @return \Generator<int, VideoMetrics>
     */
    private function streamRelaunchable(): \Generator
    {
        foreach ($this->videos->relaunchableIds() as $videoId) {
            $video = $this->videos->find($videoId);
            if (null === $video) {
                continue;
            }

            yield new VideoMetrics($video, $this->dailyStats->loadSeries($videoId));
        }
    }
}
