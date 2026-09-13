<?php

declare(strict_types=1);

namespace App\Scoring\Calculator;

use App\Catalog\Entity\Video;
use App\Catalog\Entity\VideoType;
use App\Catalog\Entity\Visibility;
use App\Scoring\Model\Eligibility;
use App\Scoring\Model\IneligibilityReason;
use App\Scoring\Model\ScoringContext;

/**
 * Decides whether a video belongs in the relaunch ranking.
 */
final class EligibilityChecker
{
    public function check(Video $video, ScoringContext $context, ?\DateTimeImmutable $lastRelaunchAt): Eligibility
    {
        if (VideoType::Standard !== $video->getType()) {
            return Eligibility::ineligible(IneligibilityReason::NotAStandardVideo);
        }

        if (Visibility::Private === $video->getVisibility()) {
            return Eligibility::ineligible(IneligibilityReason::NotVisible);
        }

        if ($video->ageInDays($context->referenceDate) < $context->parameters->minAgeDays) {
            return Eligibility::ineligible(IneligibilityReason::TooRecent);
        }

        if (null !== $lastRelaunchAt && $this->isUnderTracking($lastRelaunchAt, $context)) {
            return Eligibility::ineligible(IneligibilityReason::RelaunchInProgress);
        }

        return Eligibility::eligible();
    }

    private function isUnderTracking(\DateTimeImmutable $lastRelaunchAt, ScoringContext $context): bool
    {
        $days = (int) $lastRelaunchAt->setTime(0, 0)
            ->diff($context->referenceDate->setTime(0, 0))
            ->format('%r%a');

        return $days < $context->parameters->relaunchCooldownDays;
    }
}
