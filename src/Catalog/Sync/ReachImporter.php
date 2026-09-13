<?php

declare(strict_types=1);

namespace App\Catalog\Sync;

use App\Catalog\Repository\DailyStatRepository;
use App\Shared\Progress\ProgressReporterInterface;
use App\YouTube\Api\ReachReportParser;
use App\YouTube\Api\YouTubeReportingApi;
use App\YouTube\Entity\Channel;
use App\YouTube\Exception\YouTubeException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Third pass of a synchronisation: thumbnail impressions and their CTR.
 *
 * These numbers only exist in the Reporting API, as daily CSV files generated
 * from the moment the reporting job was created. Nothing exists before that, so
 * the impression signals stay neutral on an instance that was just installed.
 */
final class ReachImporter
{
    /** Safety margin so a report straddling the previous sync is not skipped. */
    private const int LOOKBACK_DAYS = 2;

    public function __construct(
        private readonly YouTubeReportingApi $api,
        private readonly ReachReportParser $parser,
        private readonly DailyStatRepository $dailyStats,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<string> $knownVideoIds only rows about known videos are kept
     *
     * @return int the number of daily rows imported
     */
    public function import(Channel $channel, array $knownVideoIds, ProgressReporterInterface $reporter): int
    {
        $reporter->step(new TranslatableMessage('sync.step.reach'));

        try {
            $jobId = $channel->getReportingJobId() ?? $this->api->findOrCreateJob();
        } catch (YouTubeException $exception) {
            $this->logger->warning('Thumbnail reach reporting is unavailable.', ['exception' => $exception]);

            return 0;
        }

        if ($jobId !== $channel->getReportingJobId()) {
            $channel->attachReportingJob($jobId);
            $this->entityManager->flush();
        }

        $createdAfter = $channel->getLastSyncedAt()?->modify(\sprintf('-%d days', self::LOOKBACK_DAYS));

        try {
            $reports = $this->api->listReports($jobId, $createdAfter);
        } catch (YouTubeException $exception) {
            $this->logger->warning('Could not list thumbnail reach reports.', ['exception' => $exception]);

            return 0;
        }

        $known = array_fill_keys($knownVideoIds, true);
        $total = \count($reports);
        $imported = 0;

        foreach ($reports as $index => $report) {
            try {
                $rows = $this->parser->parse($this->api->downloadReport($report));
            } catch (YouTubeException $exception) {
                $this->logger->warning('Could not download a thumbnail reach report.', [
                    'report' => $report->id,
                    'exception' => $exception,
                ]);
                continue;
            }

            $relevant = array_values(array_filter($rows, static fn ($row): bool => isset($known[$row->videoId])));
            $this->dailyStats->upsertReach($relevant);
            $imported += \count($relevant);

            $channel->markReportImported($report->id);
            $reporter->progress($index + 1, $total, new TranslatableMessage('sync.step.reach'));
        }

        $this->entityManager->flush();

        return $imported;
    }
}
