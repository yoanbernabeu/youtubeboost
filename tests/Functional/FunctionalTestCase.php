<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Analysis\Entity\Analysis;
use App\Analysis\Model\ThumbnailAngle;
use App\Catalog\Entity\Video;
use App\Relaunch\Entity\Relaunch;
use App\Relaunch\Model\PerformanceSnapshot;
use App\Relaunch\Model\Verdict;
use App\Relaunch\Storage\ArchiveStore;
use App\Settings\Entity\ReferenceAngle;
use App\Settings\Entity\ReferencePhoto;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\VideoFactory;
use App\Thumbnail\Entity\ThumbnailProposal;
use App\Thumbnail\Storage\ThumbnailStore;
use App\YouTube\Entity\Channel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Boots a browser against a ready-to-use instance: connected channel, reference
 * photos in place, onboarding finished.
 */
abstract class FunctionalTestCase extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    protected function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function settings(): Settings
    {
        return self::getContainer()->get(Settings::class);
    }

    protected function logIn(): void
    {
        $this->client->request('GET', '/connexion');
        $this->client->submitForm('Sign in', ['_password' => 'test-password']);
    }

    protected function completeOnboarding(): Channel
    {
        $channel = ChannelFactory::createOne();
        $channel->markSynced(new \DateTimeImmutable('-1 day'), new \DateTimeImmutable('-3 days'));

        foreach ([ReferenceAngle::Front, ReferenceAngle::Right, ReferenceAngle::Left] as $angle) {
            $this->entityManager()->persist(new ReferencePhoto(
                \sprintf('%s/photo.jpg', $angle->value),
                $angle,
                ucfirst($angle->value),
                'image/jpeg',
                12345,
                new \DateTimeImmutable(),
            ));
        }
        $this->entityManager()->flush();

        $this->settings()->set(SettingKey::OnboardingCompletedAt, new \DateTimeImmutable()->format(\DATE_ATOM));

        return $channel;
    }

    /**
     * The default identifier deliberately carries an underscore and a dash: real
     * YouTube identifiers do, and a slug-shaped fixture would hide a routing bug.
     */
    protected function createVideo(string $id = 'wvHsqFCU_Sk', int $score = 0): Video
    {
        $video = VideoFactory::createOne([
            'youtubeId' => $id,
            'publishedAt' => new \DateTimeImmutable('-1 year'),
        ]);
        $video->updateStatistics(50000, 800, 60);

        if ($score > 0) {
            $video->applyScore(
                new \App\Scoring\Model\Score(max(0, min(100, $score)), \App\Scoring\Model\SignalSet::fromArray(['decline' => 0.8, 'low_ctr' => 0.7])),
                new \DateTimeImmutable(),
            );
        }

        $this->entityManager()->flush();

        return $video;
    }

    protected function createAnalysis(Video $video, int $readyProposals = 5): Analysis
    {
        $angles = [];
        for ($i = 0; $i < 5; ++$i) {
            $angles[] = new ThumbnailAngle(
                $i,
                'TEXTE ' . $i,
                'Une scène',
                'surpris',
                ReferenceAngle::Front,
                'A 16:9 thumbnail number ' . $i,
            );
        }

        $analysis = new Analysis($video, 'Un résumé.', 'Une promesse.', $angles, 'gemini-test', true, new \DateTimeImmutable());
        $video->markAnalyzed(new \DateTimeImmutable());
        $this->entityManager()->persist($analysis);
        $this->entityManager()->flush();

        $store = self::getContainer()->get(ThumbnailStore::class);
        foreach ($analysis->getAngles() as $angle) {
            $proposal = new ThumbnailProposal($analysis, $angle->index, $angle->overlayText, $angle->referenceAngle, $angle->imagePrompt, new \DateTimeImmutable());
            if ($angle->index < $readyProposals) {
                $png = self::pngBinary();
                $proposal->attachImage($store->write($png, $video->getYoutubeId(), 'angle'), \strlen($png), 'gemini-test', new \DateTimeImmutable());
            }
            $this->entityManager()->persist($proposal);
        }
        $this->entityManager()->flush();

        return $analysis;
    }

    protected function createRelaunch(Video $video, ?Verdict $verdict = null): Relaunch
    {
        $archive = self::getContainer()->get(ArchiveStore::class);
        $path = $archive->write(self::pngBinary(), $video->getYoutubeId(), 'avant', 'jpg');

        $relaunch = new Relaunch(
            $video,
            null,
            $path,
            'image/jpeg',
            'https://i.ytimg.com/vi/x/maxresdefault.jpg',
            null,
            new PerformanceSnapshot(28, 100.0, 1000.0, 4.0, 180.0, 40.0),
            new \DateTimeImmutable('-40 days'),
        );

        if (null !== $verdict) {
            $relaunch->recordMilestone(14, new PerformanceSnapshot(14, 160.0, 1400.0, 5.0, 190.0, 41.0), $verdict);
            $relaunch->recordMilestone(28, new PerformanceSnapshot(28, 155.0, 1350.0, 4.9, 188.0, 40.5), $verdict);
        }

        $video->recordRelaunchState(\App\Catalog\Entity\RelaunchState::Finished, $relaunch->getAppliedAt());
        $this->entityManager()->persist($relaunch);
        $this->entityManager()->flush();

        return $relaunch;
    }

    protected function csrfToken(string $id): string
    {
        return (string) self::getContainer()->get('security.csrf.token_manager')->getToken($id);
    }

    protected static function pngBinary(): string
    {
        $image = imagecreatetruecolor(1280, 720);
        \assert(false !== $image);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
