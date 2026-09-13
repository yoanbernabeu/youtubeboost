<?php

declare(strict_types=1);

namespace App\Analysis;

use App\Analysis\Entity\Transcript;
use App\Analysis\Model\VideoBrief;
use App\Catalog\Entity\Video;
use App\Catalog\Model\VideoStatsSummarizer;
use App\Catalog\Repository\DailyStatRepository;
use App\Scoring\ScoringConfiguration;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Thumbnail\Generation\ReferenceImageProvider;
use App\YouTube\Repository\ChannelRepository;
use Psr\Clock\ClockInterface;

/**
 * Gathers everything the language model is told about a video.
 */
final class VideoBriefFactory
{
    public function __construct(
        private readonly ChannelRepository $channels,
        private readonly DailyStatRepository $dailyStats,
        private readonly VideoStatsSummarizer $summarizer,
        private readonly ScoringConfiguration $scoring,
        private readonly ReferenceImageProvider $references,
        private readonly Settings $settings,
        private readonly ClockInterface $clock,
    ) {
    }

    public function create(Video $video, ?Transcript $transcript): VideoBrief
    {
        $channel = $this->channels->findConnected();
        $parameters = $this->scoring->parameters();
        $referenceDate = $channel?->getAnalyticsAvailableUntil()
            ?? \DateTimeImmutable::createFromInterface($this->clock->now())->setTime(0, 0);

        $summary = $this->summarizer->summarize(
            $video,
            $this->dailyStats->loadSeries($video->getYoutubeId()),
            $referenceDate,
            $parameters->windowDays,
            $parameters->warmupDays,
        );

        return new VideoBrief(
            // The brief is written in English around the data, so is this fallback.
            $channel?->getTitle() ?? 'the channel',
            $video->getTitle(),
            $video->getDescription(),
            $video->getPublishedAt(),
            $video->getDurationSeconds(),
            null !== $transcript && $transcript->isUsable() ? $transcript->getText() : null,
            $this->settings->getString(SettingKey::StyleGuidelines),
            $summary->toPromptSummary(),
            $this->references->availableAngles(),
        );
    }
}
