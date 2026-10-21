<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class LoginInputTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('127.0.0.1|');
    }

    /**
     * The rate limiter builds its key from the submitted email, and it runs
     * before the FormRequest validates the body. A non-string email must not
     * raise "Array to string conversion".
     */
    public function test_array_email_is_rejected_as_a_validation_error(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => ['a@b.test'],
            'password' => 'password',
        ], $this->frontendHeaders);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['code', 'message', 'fields', 'details'], 'meta' => ['request_id']]);
    }

    public function test_object_email_is_rejected_as_a_validation_error(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => ['x' => ['y' => 'z']],
            'password' => 'password',
        ], $this->frontendHeaders);

        $response->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_a_non_string_email_still_counts_against_the_rate_limit(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/v1/auth/login', [
                'email' => ['a@b.test'],
                'password' => 'password',
            ], $this->frontendHeaders)->assertStatus(422);
        }

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => ['a@b.test'],
            'password' => 'password',
        ], $this->frontendHeaders);

        $response->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    /**
     * The origin check must not reveal whether the credentials are correct.
     */
    public function test_the_origin_check_does_not_reveal_whether_credentials_are_correct(): void
    {
        User::factory()->admin()->create([
            'email' => 'admin@test.local',
            'password' => Hash::make('password'),
        ]);

        $hostile = [
            'origin' => 'http://evil.example.com',
            'referer' => 'http://evil.example.com/',
        ];

        $correctPassword = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@test.local',
            'password' => 'password',
        ], $hostile);

        $wrongPassword = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@test.local',
            'password' => 'wrong',
        ], $hostile);

        $unknownEmail = $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@test.local',
            'password' => 'password',
        ], $hostile);

        $correctPassword->assertStatus(403);
        $wrongPassword->assertStatus(403);
        $unknownEmail->assertStatus(403);

        $this->assertSame(
            $correctPassword->json('error.message'),
            $wrongPassword->json('error.message'),
        );
        $this->assertSame(
            $correctPassword->json('error.message'),
            $unknownEmail->json('error.message'),
        );
    }
}
