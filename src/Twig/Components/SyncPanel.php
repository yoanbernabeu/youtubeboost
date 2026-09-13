<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Catalog\Repository\VideoRepository;
use App\Catalog\Sync\SyncLauncher;
use App\Job\Entity\Job;
use App\YouTube\Api\YouTubeDataApi;
use App\YouTube\Entity\Channel;
use App\YouTube\Exception\YouTubeException;
use App\YouTube\Quota\YouTubeEndpoint;
use App\YouTube\Repository\ChannelRepository;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The synchronisation card: one button, and the progress of the running job.
 *
 * Polls only while a job is running; the re-render that marks it finished emits a
 * root element without the poll attribute, which stops the traffic.
 */
#[AsLiveComponent]
final class SyncPanel
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    public function __construct(
        private readonly SyncLauncher $launcher,
        private readonly ChannelRepository $channels,
        private readonly VideoRepository $videos,
        // The failure leaves PHP inside a browser event payload, where no Twig
        // filter can reach it, so it has to be translated here.
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getJob(): ?Job
    {
        return $this->launcher->runningJob() ?? $this->launcher->latestJob();
    }

    public function isRunning(): bool
    {
        return null !== $this->launcher->runningJob();
    }

    public function getChannel(): ?Channel
    {
        return $this->channels->findConnected();
    }

    /**
     * Quota a synchronisation costs, from the size of the catalogue.
     *
     * One `channels.list`, then one `playlistItems.list` and one `videos.list`
     * per page of fifty. The Analytics and Reporting calls draw on other quotas.
     */
    public function getEstimatedQuotaCost(): int
    {
        $known = $this->videos->statistics()['total'];
        $pages = (int) ceil(max(1, $known) / YouTubeDataApi::VIDEO_BATCH_SIZE);

        return YouTubeEndpoint::ChannelsList->quotaCost()
            + $pages * YouTubeEndpoint::PlaylistItemsList->quotaCost()
            + $pages * YouTubeEndpoint::VideosList->quotaCost();
    }

    #[LiveAction]
    public function synchronize(): void
    {
        try {
            $this->launcher->launch();
        } catch (YouTubeException $exception) {
            $this->dispatchBrowserEvent('youtubeboost:error', ['message' => $exception->getUserMessage()->trans($this->translator)]);
        }
    }
}
