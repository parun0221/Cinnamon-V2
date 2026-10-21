<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AuthEdgeCaseTest extends TestCase
{
    /**
     * Laravel's CSRF middleware is bypassed while running unit tests, so the
     * 419 path is exercised through the registered render closure directly.
     */
    public function test_csrf_token_mismatch_renders_419_envelope(): void
    {
        $request = Request::create('/api/v1/auth/login', 'POST');

        $response = $this->app->make(ExceptionHandler::class)
            ->render($request, new HttpException(419, 'CSRF token mismatch.'));

        $this->assertSame(419, $response->getStatusCode());
        $this->assertSame('UNAUTHENTICATED', $response->getData(true)['error']['code']);
        $this->assertNotEmpty($response->getData(true)['meta']['request_id']);
    }

    /**
     * A request whose Origin is not a stateful domain must be rejected with a
     * 403, not a 500. Laravel rewrites OriginMismatchException to
     * HttpException(403) before render callbacks run.
     */
    public function test_cross_origin_request_is_forbidden_not_a_server_error(): void
    {
        User::factory()->admin()->create([
            'email' => 'admin@test.local',
            'password' => Hash::make('password'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@test.local',
            'password' => 'password',
        ], [
            'origin' => 'http://evil.example.com',
            'referer' => 'http://evil.example.com/',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN')
            ->assertJsonStructure(['error' => ['code', 'message', 'fields', 'details'], 'meta' => ['request_id']]);

        $this->assertGuest();
    }

    public function test_inactive_user_cannot_login_and_no_session_row_is_created(): void
    {
        $user = User::factory()->inactive()->create([
            'email' => 'inactive@test.local',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'inactive@test.local',
            'password' => 'password',
        ], $this->frontendHeaders);

        $response->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertGuest();
    }

    public function test_response_carries_x_request_id_header_matching_meta(): void
    {
        $response = $this->getJson('/api/v1/health');

        $this->assertSame($response->json('meta.request_id'), $response->headers->get('X-Request-Id'));
    }

    public function test_error_response_never_leaks_internals(): void
    {
        $body = $this->getJson('/api/v1/tidak-ada')->getContent();

        $this->assertStringNotContainsString('vendor/laravel', $body);
        $this->assertStringNotContainsString('APP_KEY', $body);
        $this->assertStringNotContainsString('mysql://', $body);
        $this->assertStringNotContainsString('SQLSTATE', $body);
    }

    public function test_uuid_v7_primary_key_resolves_by_route_binding(): void
    {
        $user = User::factory()->create();

        $resolved = (new User)->resolveRouteBinding($user->id);

        $this->assertNotNull($resolved);
        $this->assertSame($user->id, $resolved->id);
    }
}
