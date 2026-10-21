<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Per-request correlation id, attached by RequestIdMiddleware and read back by
 * controllers so every response carries the same `meta.request_id`.
 */
class RequestId
{
    public const ATTRIBUTE = 'request_id';

    private static ?string $current = null;

    public static function generate(): string
    {
        return (string) Str::uuid();
    }

    public static function set(string $requestId): void
    {
        self::$current = $requestId;
    }

    public static function current(): string
    {
        return self::$current ??= self::generate();
    }
}
