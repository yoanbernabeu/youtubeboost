<?php

declare(strict_types=1);

namespace App\YouTube\Exception;

/**
 * The API asked us to slow down: the call can be retried later.
 */
final class RateLimitedException extends YouTubeException
{
}
