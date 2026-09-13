<?php

declare(strict_types=1);

namespace App\Catalog\Controller;

use App\Catalog\Entity\RelaunchState;
use App\Catalog\Repository\DailyStatRepository;
use App\Catalog\Repository\VideoFilter;
use App\Catalog\Repository\VideoRepository;
use App\Relaunch\Repository\RelaunchRepository;
use App\Scoring\ScoringConfiguration;
use App\YouTube\Repository\ChannelRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The ranking of the catalogue, which is the home page of the application.
 */
final class CatalogController extends AbstractController
{
    private const int PAGE_SIZE = 25;

    public function __construct(
        private readonly VideoRepository $videos,
        private readonly DailyStatRepository $dailyStats,
        private readonly RelaunchRepository $relaunches,
        private readonly ChannelRepository $channels,
        private readonly ScoringConfiguration $scoring,
    ) {
    }

    #[Route('/', name: 'app_catalog', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filter = self::filterFromRequest($request);
        $page = max(1, $request->query->getInt('page', 1));
        $total = $this->videos->countByFilter($filter);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($page, $pages);

        return $this->render('catalog/index.html.twig', [
            'videos' => $this->videos->findByFilter($filter, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE),
            'filter' => $filter,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'statistics' => $this->videos->statistics(),
            'relaunchStatistics' => $this->relaunches->statistics(),
            'channel' => $this->channels->findConnected(),
            'parameters' => $this->scoring->parameters(),
            'relaunchStates' => RelaunchState::cases(),
            'currentAgeRange' => trim((string) $request->query->get('age', '')),
            'hasImpressionData' => $this->dailyStats->hasImpressionData(),
        ]);
    }

    private static function filterFromRequest(Request $request): VideoFilter
    {
        $search = trim((string) $request->query->get('q', ''));
        [$minAge, $maxAge] = self::ageRange(trim((string) $request->query->get('age', '')));

        return new VideoFilter(
            '' === $search ? null : $search,
            max(0, min(100, $request->query->getInt('min_score', 0))),
            $minAge,
            $maxAge,
            RelaunchState::tryFrom((string) $request->query->get('statut', '')),
            $request->query->getBoolean('tout'),
            (string) $request->query->get('tri', VideoFilter::SORT_SCORE),
        );
    }

    /**
     * Reads the age filter, written as "60-" for "older than 60 days" and "-365"
     * for "younger than a year".
     *
     * @return array{0: int|null, 1: int|null}
     */
    private static function ageRange(string $value): array
    {
        if ('' === $value) {
            return [null, null];
        }

        if (str_ends_with($value, '-')) {
            return [max(0, (int) rtrim($value, '-')), null];
        }

        if (str_starts_with($value, '-')) {
            return [null, max(0, (int) ltrim($value, '-'))];
        }

        return [null, null];
    }
}
