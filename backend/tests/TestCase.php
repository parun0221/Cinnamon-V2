<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use DatabaseTransactions;

    /**
     * Header that makes Laravel treat the request as coming from the SPA,
     * so the session cookie is honoured (Sanctum stateful request).
     */
    protected array $frontendHeaders = [
        'referer' => 'http://localhost:5173/',
        'origin' => 'http://localhost:5173',
    ];
}
