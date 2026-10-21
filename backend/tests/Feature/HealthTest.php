<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_endpoint_responds_with_ok_envelope(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertOk()
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonStructure(['data' => ['status'], 'meta' => ['request_id']]);

        $this->assertTrue(Str::isUuid($response->json('meta.request_id')));
    }

    public function test_users_table_uses_uuid_primary_key(): void
    {
        $user = User::factory()->create();

        $this->assertTrue(Str::isUuid($user->id));
        $this->assertSame('7', $user->id[14], 'UUID version nibble should be 7 (time-ordered).');
        $this->assertNotNull(User::find($user->id));
    }

    public function test_unknown_route_returns_not_found_envelope(): void
    {
        $this->getJson('/api/v1/does-not-exist')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_request_id_is_present_on_success_and_error_responses(): void
    {
        $ok = $this->getJson('/api/v1/health');
        $error = $this->getJson('/api/v1/does-not-exist');

        $this->assertNotEmpty($ok->json('meta.request_id'));
        $this->assertNotEmpty($error->json('meta.request_id'));
    }

    public function test_a_well_formed_client_request_id_is_echoed_back(): void
    {
        $id = '0192f0c0-0000-7000-8000-000000000042';

        $response = $this->getJson('/api/v1/health', ['X-Request-Id' => $id]);

        $this->assertSame($id, $response->json('meta.request_id'));
        $this->assertSame($id, $response->headers->get('X-Request-Id'));
    }

    public function test_a_malformed_client_request_id_is_replaced(): void
    {
        $response = $this->getJson('/api/v1/health', ['X-Request-Id' => '"bad" id']);

        $this->assertNotSame('"bad" id', $response->json('meta.request_id'));
        $this->assertTrue(Str::isUuid($response->json('meta.request_id')));
        $this->assertSame($response->json('meta.request_id'), $response->headers->get('X-Request-Id'));
    }

    public function test_error_response_never_leaks_stack_trace(): void
    {
        $response = $this->getJson('/api/v1/does-not-exist');

        $this->assertStringNotContainsStringIgnoringCase('stack', (string) $response->json('error.message'));
        $this->assertArrayNotHasKey('exception', $response->json());
    }
}
