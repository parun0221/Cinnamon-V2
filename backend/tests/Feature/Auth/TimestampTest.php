<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Tests\TestCase;

class TimestampTest extends TestCase
{
    public function test_timestamps_are_serialized_in_jakarta_timezone(): void
    {
        $user = User::factory()->create([
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/me', $this->frontendHeaders)
            ->assertOk()
            ->assertJsonPath('data.created_at', now()->setTimezone('Asia/Jakarta')->toIso8601String());
    }

    public function test_timestamps_carry_the_jakarta_offset(): void
    {
        $user = User::factory()->create();

        $createdAt = $this->actingAs($user)
            ->getJson('/api/v1/me', $this->frontendHeaders)
            ->json('data.created_at');

        $this->assertStringEndsWith('+07:00', $createdAt);
    }
}
