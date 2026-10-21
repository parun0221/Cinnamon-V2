<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    public function test_admin_can_login_and_receive_user_envelope(): void
    {
        $user = User::factory()->admin()->create([
            'email' => 'admin@test.local',
            'password' => Hash::make('password'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@test.local',
            'password' => 'password',
        ], $this->frontendHeaders);

        $response->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.role', 'admin')
            ->assertJsonPath('data.email', 'admin@test.local')
            ->assertJsonStructure(['data' => ['id', 'name', 'email', 'role', 'is_active', 'last_login_at'], 'meta' => ['request_id']]);

        $this->assertNotEmpty($response->json('meta.request_id'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_updates_last_login_at(): void
    {
        $user = User::factory()->admin()->create();

        $this->assertNull($user->last_login_at);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ], $this->frontendHeaders)->assertOk();

        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_login_with_wrong_password_is_unauthenticated(): void
    {
        User::factory()->admin()->create([
            'email' => 'admin@test.local',
            'password' => Hash::make('password'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@test.local',
            'password' => 'wrong-password',
        ], $this->frontendHeaders);

        $response->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED')
            ->assertJsonStructure(['error' => ['code', 'message', 'fields', 'details'], 'meta' => ['request_id']]);

        $this->assertGuest();
    }

    public function test_login_with_unknown_email_is_unauthenticated(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@test.local',
            'password' => 'password',
        ], $this->frontendHeaders)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');

        $this->assertGuest();
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::factory()->inactive()->create([
            'email' => 'inactive@test.local',
            'password' => Hash::make('password'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'inactive@test.local',
            'password' => 'password',
        ], $this->frontendHeaders);

        $response->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');

        $this->assertGuest();
    }

    public function test_remember_me_sets_a_long_lived_remember_cookie(): void
    {
        $user = User::factory()->admin()->create();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'remember' => true,
        ], $this->frontendHeaders);

        $response->assertOk();

        $this->assertNotEmpty($response->getCookie(Auth::guard('web')->getRecallerName()));
    }

    public function test_login_without_remember_sets_no_remember_cookie(): void
    {
        $user = User::factory()->admin()->create();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ], $this->frontendHeaders);

        $response->assertOk();

        $this->assertNull($response->getCookie(Auth::guard('web')->getRecallerName()));
    }

    public function test_login_requires_email_and_password(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [], $this->frontendHeaders);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['code', 'message', 'fields' => ['email', 'password']]]);
    }

    public function test_validation_messages_are_in_indonesian(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [], $this->frontendHeaders);

        $this->assertSame('email wajib diisi.', $response->json('error.fields.email.0'));
        $this->assertSame('password wajib diisi.', $response->json('error.fields.password.0'));
    }

    public function test_me_returns_the_authenticated_user(): void
    {
        $user = User::factory()->karyawan()->create();

        $this->actingAs($user)
            ->getJson('/api/v1/me', $this->frontendHeaders)
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.role', 'karyawan');
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/v1/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_logout_ends_the_session(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/auth/logout', [], $this->frontendHeaders)
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->assertFalse(Auth::guard('web')->check(), 'Session user should be cleared after logout.');

        // A real HTTP request builds fresh guards; drop the cached ones so the
        // follow-up request behaves like a second request from the browser.
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/me', $this->frontendHeaders)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_update_me_changes_the_display_name(): void
    {
        $user = User::factory()->karyawan()->create(['name' => 'Awal']);

        $this->actingAs($user)
            ->patchJson('/api/v1/me', ['name' => 'Nama Baru'], $this->frontendHeaders)
            ->assertOk()
            ->assertJsonPath('data.name', 'Nama Baru');

        $this->assertSame('Nama Baru', $user->fresh()->name);
    }
}
