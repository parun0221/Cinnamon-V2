<?php

namespace Tests\Feature;

use Tests\TestCase;

class ErrorEnvelopeTest extends TestCase
{
    public function test_validation_error_uses_error_envelope_with_request_id(): void
    {
        $response = $this->postJson('/api/v1/auth/login', []);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['code', 'message', 'fields', 'details'], 'meta' => ['request_id']]);

        $this->assertArrayHasKey('email', $response->json('error.fields'));
        $this->assertArrayHasKey('password', $response->json('error.fields'));
        $this->assertNotEmpty($response->json('meta.request_id'));
    }

    public function test_unauthenticated_request_returns_401_not_500(): void
    {
        $response = $this->getJson('/api/v1/me');

        $response->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED')
            ->assertJsonStructure(['error' => ['code', 'message', 'fields', 'details'], 'meta' => ['request_id']]);
    }

    public function test_unknown_route_returns_404_envelope(): void
    {
        $response = $this->getJson('/api/v1/tidak-ada');

        $response->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_error_message_never_contains_stack_trace(): void
    {
        $message = $this->getJson('/api/v1/tidak-ada')->json('error.message');

        $this->assertStringNotContainsString('#0 ', $message);
        $this->assertStringNotContainsString('vendor/laravel', $message);
    }

    public function test_request_id_is_unique_per_request(): void
    {
        $first = $this->getJson('/api/v1/tidak-ada')->json('meta.request_id');
        $second = $this->getJson('/api/v1/tidak-ada')->json('meta.request_id');

        $this->assertNotSame($first, $second);
    }
}
