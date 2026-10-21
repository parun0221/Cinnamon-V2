<?php

namespace App\Providers;

use App\Auth\ActiveUserProvider;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // A deactivated account must lose the access it already holds, not
        // just the ability to log in again.
        Auth::provider('active', fn ($app, array $config) => new ActiveUserProvider(
            $app['hash'],
            $config['model'] ?? User::class,
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request) {
            // The email is attacker-controlled and reaches this limiter before
            // the FormRequest validates it. It must be coerced to a string
            // safely: a JSON array or object would raise "Array to string
            // conversion", which turns the login endpoint into a 500 and — worse
            // — throws before the attempt is counted, bypassing the limit.
            $email = $request->input('email');

            return Limit::perMinute(5)->by($request->ip().'|'.(is_string($email) ? $email : ''));
        });

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        $this->guardAgainstLegacySchema();
    }

    /**
     * The `users` table must use a UUID v7 primary key. A legacy schema (from
     * the older `laravel` or `cinnamon` databases) uses a BIGINT key, and the
     * migration that would create the UUID column is skipped when the
     * `migrations` table already lists it — leaving the app running against a
     * schema it cannot use. Fail at boot instead of failing later at runtime.
     */
    private function guardAgainstLegacySchema(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $column = Schema::getColumnType('users', 'id');

        if (! in_array($column, ['char', 'varchar', 'string'], true)) {
            throw new RuntimeException(
                'Tabel `users` memakai kolom `id` bertipe '.$column.'. '
                .'Skema CINNAMON memakai UUID v7 (char(36)). '
                .'Gunakan database `cinnamon_v2` yang kosong untuk instalasi baru; '
                .'database `cinnamon` berisi data lama dan tidak boleh dipakai.'
            );
        }
    }
}
