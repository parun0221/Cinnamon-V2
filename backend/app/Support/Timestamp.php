<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Timestamps are persisted in UTC (the application timezone) and presented to
 * clients in Asia/Jakarta, per `Docs/AGENTS.md` §6 and `Docs/API.md` §2.
 */
class Timestamp
{
    public const DISPLAY_TIMEZONE = 'Asia/Jakarta';

    public static function iso8601(?CarbonInterface $value): ?string
    {
        return $value?->copy()->setTimezone(self::DISPLAY_TIMEZONE)->toIso8601String();
    }
}
