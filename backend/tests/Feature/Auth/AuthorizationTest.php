<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    public function test_karyawan_is_forbidden_from_admin_only_endpoint(): void
    {
        $karyawan = User::factory()->karyawan()->create();

        $response = $this->actingAs($karyawan)
            ->getJson('/api/v1/users', $this->frontendHeaders);

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN')
            ->assertJsonStructure(['error' => ['code', 'message', 'fields', 'details'], 'meta' => ['request_id']]);
    }

    public function test_admin_can_list_users(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->karyawan()->create(['name' => 'Budi']);

        $expectedTotal = User::query()->count();

        $response = $this->actingAs($admin)
            ->getJson('/api/v1/users', $this->frontendHeaders);

        $response->assertOk()
            ->assertJsonPath('meta.total', $expectedTotal)
            ->assertJsonStructure(['data', 'meta' => ['request_id', 'current_page', 'per_page', 'last_page', 'total']]);
    }

    public function test_guest_gets_unauthenticated_not_forbidden(): void
    {
        $this->getJson('/api/v1/users')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }
}
