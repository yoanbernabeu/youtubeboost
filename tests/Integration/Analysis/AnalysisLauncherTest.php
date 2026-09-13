<?php

declare(strict_types=1);

namespace App\Tests\Integration\Analysis;

use App\Analysis\AnalysisLauncher;
use App\Analysis\Exception\AnalysisFailedException;
use App\Catalog\Entity\Video;
use App\Job\Entity\JobType;
use App\Job\JobTracker;
use App\Job\Repository\JobRepository;
use App\Settings\Settings;
use App\Settings\Store\InMemorySettingStore;
use App\Tests\Factory\VideoFactory;
use App\YouTube\Entity\QuotaUsage;
use App\YouTube\Exception\QuotaExhaustedException;
use App\YouTube\Quota\QuotaTracker;
use App\YouTube\Quota\QuotaWindow;
use App\YouTube\Quota\YouTubeEndpoint;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

#[CoversClass(AnalysisLauncher::class)]
final class AnalysisLauncherTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testItQueuesTheAnalysisAndCreatesAJob(): void
    {
        $video = $this->video();

        $job = $this->launcher(apiKey: 'AIza-test')->launch($video);

        self::assertSame(JobType::AnalyzeVideo, $job->getType());
        self::assertSame($video->getYoutubeId(), $job->getSubjectId());
    }

    public function testWithoutAGeminiKeyTheAnalysisIsRefusedBeforeAnythingIsQueued(): void
    {
        $video = $this->video();

        try {
            $this->launcher(apiKey: '')->launch($video);
            self::fail('An exception was expected.');
        } catch (AnalysisFailedException $exception) {
            self::assertStringContainsString('GEMINI_API_KEY', $exception->getMessage());
        }

        self::assertSame([], self::getContainer()->get(JobRepository::class)->findForSubject(JobType::AnalyzeVideo, $video->getYoutubeId()));
    }

    public function testAWhitespaceOnlyKeyCountsAsMissing(): void
    {
        $this->expectException(AnalysisFailedException::class);

        $this->launcher(apiKey: "  \n ")->launch($this->video());
    }

    public function testAnExhaustedQuotaIsCheckedAfterTheKey(): void
    {
        $video = $this->video();
        $this->exhaustQuota();

        $this->expectException(QuotaExhaustedException::class);

        $this->launcher(apiKey: 'AIza-test')->launch($video);
    }

    public function testARunningAnalysisIsReusedRatherThanDuplicated(): void
    {
        $video = $this->video();
        $launcher = $this->launcher(apiKey: 'AIza-test');

        $first = $launcher->launch($video);
        $second = $launcher->launch($video);

        self::assertSame($first->getId(), $second->getId());
    }

    public function testTheAnnouncedCostCoversListingAndDownloadingCaptions(): void
    {
        self::assertSame(
            YouTubeEndpoint::CaptionsList->quotaCost() + YouTubeEndpoint::CaptionsDownload->quotaCost(),
            AnalysisLauncher::quotaCost(),
        );
    }

    private function launcher(string $apiKey): AnalysisLauncher
    {
        $container = self::getContainer();

        return new AnalysisLauncher(
            $container->get(JobRepository::class),
            $container->get(JobTracker::class),
            $container->get(QuotaTracker::class),
            new Settings(new InMemorySettingStore(), 'gemini-text', 'gemini-image', $apiKey),
            $container->get(MessageBusInterface::class),
        );
    }

    private function video(): Video
    {
        $video = VideoFactory::createOne(['youtubeId' => 'launchable']);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        return $video;
    }

    private function exhaustQuota(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new QuotaUsage(
            QuotaWindow::day(new \DateTimeImmutable()),
            YouTubeEndpoint::CaptionsDownload,
            QuotaWindow::DAILY_LIMIT,
            new \DateTimeImmutable(),
        ));
        $entityManager->flush();
    }
}
