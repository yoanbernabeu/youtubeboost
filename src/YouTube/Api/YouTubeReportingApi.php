<?php

declare(strict_types=1);

namespace App\YouTube\Api;

use App\YouTube\Api\Dto\ReportRef;
use App\YouTube\Exception\ApiCallFailedException;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * The YouTube Reporting API, the only source of thumbnail impressions and CTR.
 *
 * Unlike the Analytics API, reports are produced asynchronously: a job has to
 * exist first, and it only generates data from its creation date on. The job is
 * therefore created during onboarding, before anything needs it.
 */
final class YouTubeReportingApi
{
    /** Daily thumbnail impressions and CTR per video. */
    public const string REACH_REPORT_TYPE = 'channel_reach_basic_a1';
    private const string BASE_URL = 'https://youtubereporting.googleapis.com/v1/';

    public function __construct(private readonly GoogleApiRequester $requester)
    {
    }

    /**
     * Returns the identifier of the job producing the given report type,
     * creating it when the channel does not have one yet.
     */
    public function findOrCreateJob(string $reportTypeId = self::REACH_REPORT_TYPE, string $name = 'youtubeboost-reach'): string
    {
        $existing = $this->findJob($reportTypeId);
        if (null !== $existing) {
            return $existing;
        }

        $payload = $this->requester->postJson(self::BASE_URL . 'jobs', [
            'reportTypeId' => $reportTypeId,
            'name' => $name,
        ]);

        $id = $payload['id'] ?? null;
        if (!\is_string($id) || '' === $id) {
            throw new ApiCallFailedException('Creating the impressions report failed.')->withUserMessage(new TranslatableMessage('youtube.error.reporting_job_failed'));
        }

        return $id;
    }

    public function findJob(string $reportTypeId = self::REACH_REPORT_TYPE): ?string
    {
        $payload = $this->requester->getJson(self::BASE_URL . 'jobs');
        $jobs = $payload['jobs'] ?? [];
        if (!\is_array($jobs)) {
            return null;
        }

        foreach ($jobs as $job) {
            if (\is_array($job) && ($job['reportTypeId'] ?? null) === $reportTypeId && \is_string($job['id'] ?? null)) {
                return $job['id'];
            }
        }

        return null;
    }

    /**
     * Reports available for a job, oldest first.
     *
     * @return list<ReportRef>
     */
    public function listReports(string $jobId, ?\DateTimeImmutable $createdAfter = null): array
    {
        $reports = [];
        $pageToken = null;

        do {
            $query = ['pageSize' => 100];
            if (null !== $createdAfter) {
                $query['createdAfter'] = $createdAfter->format(\DATE_RFC3339);
            }
            if (null !== $pageToken) {
                $query['pageToken'] = $pageToken;
            }

            $payload = $this->requester->getJson(self::BASE_URL . 'jobs/' . urlencode($jobId) . '/reports', $query);

            foreach ($this->reportsOf($payload) as $report) {
                $reports[] = $report;
            }

            $pageToken = \is_string($payload['nextPageToken'] ?? null) ? $payload['nextPageToken'] : null;
        } while (null !== $pageToken);

        usort($reports, static fn (ReportRef $a, ReportRef $b): int => $a->startTime <=> $b->startTime);

        return $reports;
    }

    /**
     * Downloads a report and returns its CSV content, decompressed if needed.
     */
    public function downloadReport(ReportRef $report): string
    {
        $content = $this->requester->getRaw($report->downloadUrl);

        // The transport usually decompresses for us; some setups hand back the
        // raw gzip stream instead.
        if (str_starts_with($content, "\x1f\x8b")) {
            $decoded = @gzdecode($content);
            if (false === $decoded) {
                throw new ApiCallFailedException('The downloaded impressions report is unreadable.')->withUserMessage(new TranslatableMessage('youtube.error.reporting_unreadable'));
            }

            return $decoded;
        }

        return $content;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<ReportRef>
     */
    private function reportsOf(array $payload): array
    {
        $rows = $payload['reports'] ?? [];
        if (!\is_array($rows)) {
            return [];
        }

        $reports = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $id = $row['id'] ?? null;
            $downloadUrl = $row['downloadUrl'] ?? null;
            if (!\is_string($id) || !\is_string($downloadUrl)) {
                continue;
            }

            $reports[] = new ReportRef(
                $id,
                \is_string($row['jobId'] ?? null) ? $row['jobId'] : '',
                self::timestamp($row['startTime'] ?? null),
                self::timestamp($row['endTime'] ?? null),
                self::timestamp($row['createTime'] ?? null),
                $downloadUrl,
            );
        }

        return $reports;
    }

    private static function timestamp(mixed $value): \DateTimeImmutable
    {
        if (!\is_string($value) || '' === $value) {
            return new \DateTimeImmutable('@0');
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return new \DateTimeImmutable('@0');
        }
    }
}
