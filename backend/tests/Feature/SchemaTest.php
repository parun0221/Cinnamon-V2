<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    public function test_users_primary_key_is_a_uuid_column(): void
    {
        $this->assertContains(
            Schema::getColumnType('users', 'id'),
            ['char', 'varchar', 'string'],
            'users.id harus UUID (char(36)), bukan BIGINT.',
        );
    }

    public function test_the_session_table_exists(): void
    {
        $this->assertTrue(
            Schema::hasTable('sessions'),
            'Tabel `sessions` harus ada — SESSION_DRIVER=database membutuhkannya.',
        );
    }

    public function test_the_users_table_carries_the_role_and_active_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('users', ['role', 'is_active', 'last_login_at']));
    }

    public function test_the_personal_access_tokens_table_uses_a_uuid_tokenable(): void
    {
        $this->assertTrue(Schema::hasTable('personal_access_tokens'));
        $this->assertContains(
            Schema::getColumnType('personal_access_tokens', 'tokenable_id'),
            ['char', 'varchar', 'string'],
        );
    }
}
