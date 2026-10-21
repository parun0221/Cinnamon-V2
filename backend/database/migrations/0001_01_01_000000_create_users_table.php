<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->guardAgainstLegacyUsersTable();

        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->enum('role', ['admin', 'karyawan'])->default('karyawan');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->uuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->text('payload')->nullable();
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }

    /**
     * A `users` table from an older schema (BIGINT primary key) must not be
     * silently adopted. When the migration is skipped — because the `migrations`
     * table already lists it — the schema would keep a bigint `users.id` while
     * the model expects a UUID v7, and `sessions` would never be created.
     * Fail loudly instead.
     */
    private function guardAgainstLegacyUsersTable(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $column = Schema::getColumnType('users', 'id');

        if ($column !== 'char' && $column !== 'varchar' && $column !== 'string') {
            throw new RuntimeException(
                'Tabel `users` sudah ada dengan kolom `id` bertipe '.$column.'. '
                .'Skema CINNAMON memakai UUID v7 (char(36)). '
                .'Hapus tabel lama atau gunakan database kosong untuk instalasi baru.'
            );
        }
    }
};
