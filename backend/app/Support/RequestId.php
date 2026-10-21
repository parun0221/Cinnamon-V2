<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Per-request correlation id, attached by RequestIdMiddleware and read back by
 * controllers so every response carries the same `meta.request_id`.
 *
 * The id lives on the request rather than in a static so it cannot leak from
 * one request into the next in a long-lived process.
 */
class RequestId
{
    public const ATTRIBUTE = 'request_id';

    public static function generate(): string
    {
        return (string) Str::uuid();
    }

    public static function current(): string
    {
        $request = request();

        if (! $request->attributes->has(self::ATTRIBUTE)) {
            $request->attributes->set(self::ATTRIBUTE, self::generate());
        }

        return (string) $request->attributes->get(self::ATTRIBUTE);
    }
}
