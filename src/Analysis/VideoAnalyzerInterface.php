<?php

declare(strict_types=1);

namespace App\Analysis;

use App\Analysis\Model\AnalysisPlan;
use App\Analysis\Model\VideoBrief;

/**
 * Reads a video and proposes thumbnail angles.
 *
 * Behind an interface so the application is not welded to one provider, which is
 * the mitigation the product specification asks for.
 */
interface VideoAnalyzerInterface
{
    public function analyze(VideoBrief $brief): AnalysisPlan;
}
