<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class LoginRateLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('127.0.0.1|admin@test.local');
    }

    public function test_repeated_failed_logins_are_rate_limited(): void
    {
        User::factory()->admin()->create([
            'email' => 'admin@test.local',
            'password' => Hash::make('password'),
        ]);

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'admin@test.local',
                'password' => 'wrong',
            ], $this->frontendHeaders)->assertStatus(401);
        }

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@test.local',
            'password' => 'wrong',
        ], $this->frontendHeaders);

        $response->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMITED')
            ->assertJsonStructure(['error' => ['code', 'message', 'fields', 'details'], 'meta' => ['request_id']]);
    }

    public function test_a_correct_login_is_still_allowed_within_the_limit(): void
    {
        User::factory()->admin()->create([
            'email' => 'admin@test.local',
            'password' => Hash::make('password'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@test.local',
            'password' => 'wrong',
        ], $this->frontendHeaders)->assertStatus(401);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@test.local',
            'password' => 'password',
        ], $this->frontendHeaders)->assertStatus(200);
    }
}
