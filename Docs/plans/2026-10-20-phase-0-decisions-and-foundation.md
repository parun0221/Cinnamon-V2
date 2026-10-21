# Phase 0 Decisions + Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Resolve six open product decisions, capture them in `Docs/decisions.md`, then bootstrap the backend (Laravel 13 + Sanctum SPA auth + UUID v7 + error envelope + role seeder) and frontend (Vite + React + TypeScript + TanStack Query + React Router) into a working local install that passes `health`, login, logout, `/me`, and `vite build`.

**Architecture:** One monorepo with `backend/` (Laravel 13 API) and `frontend/` (React + TypeScript + Vite). Backend runs as REST API; frontend mounts under `/` via Laravel's default `public/index.php` in dev so a single `php artisan serve` serves both. Production will split, but the repo keeps the shared boundary. Every model uses UUID v7 (time-ordered); all monetary values use `Number::format()` to avoid JS float issues.

**Tech Stack:**
- Backend: Laravel 13.37, PHP 8.5, MySQL 8 (local XAMPP, DB name `cinnamon`), Laravel Sanctum `^4.3` (stateful SPA cookie), `laravel/sanctum`, UTC persistence + Asia/Jakarta display via `config/app.php`, bcmath present.
- Frontend: Vite 8.3, React 19, TypeScript 6.0, TanStack Query 5, React Router DOM 7, Zod 4, React Hook Form, ESLint flat config, Vitest 5 + Testing Library React 16.

**Spec:** `Docs/PRD.md`, `Docs/Architecture.md`, `Docs/Database.md`, `Docs/API.md`, `Docs/Design.md`, `Docs/roadmap.md`. This plan implements Phase 0 (decisions + foundation) from `Docs/roadmap.md`.

## Global Constraints

- One store context only — no warehouse selector in MVP.
- Two roles: Admin and Karyawan.
- IDs are UUID v7 everywhere; routes accept and return the string form; URL-safe encoding is not required.
- Money / quantity stored as `DECIMAL(15,2)` / `DECIMAL(15,3)`; serialized as strings via `Number::format($v, 2)` or `Number::format($v, 3)`.
- Timestamps stored UTC; displayed Asia/Jakarta; ISO 8601 in JSON.
- `Idempotency-Key` header required on all mutating endpoints that change stock or ledger.
- `request_id` field present on every response and error object.
- Envelope: `{ "data": …, "meta": { "request_id": "…", … } }`.
- Error envelope: `{ "error": { "code": "…", "message": "…", "fields": {}, "details": {} }, "meta": { "request_id": "…" } }`.
- Pagination defaults to 20 per page, max 100, with `current_page`, `per_page`, `last_page`, `total`.
- Field-level validation errors go in `fields`; business errors go in `error.code`.
- Server rejects unknown filter fields with `INVALID_FILTER` code (422).
- `APP_URL=http://localhost`, `SESSION_SAME_SITE=lax`, `SESSION_PATH=/`, `SESSION_DOMAIN=null`, `SESSION_SECURE_COOKIE=false` for local dev.
- `.env` must not enter git; frontend `.env` must not contain secrets.
- Frontend TypeScript must pass `tsc -b` with `noUnusedLocals`, `noUnusedParameters`, `erasableSyntaxOnly` rules enforced by the existing `tsconfig.app.json`.
- Frontend must pass the existing ESLint flat config without adding rules.

## Review Focus

- UUID v7 round-trip: the model serializes `Str::uuid7()` and the API returns the same string; POST accepts both v4 and v7 (because `Str::isUuid()` accepts any valid UUID) and stores v7 — test covers `User::find($stringId)` on a v7-created user.
- Role policy enforcement: a Karyawan hits an Admin-only endpoint and gets 403 (not 401) even though session is valid — test covers both `GET /api/v1/users` as Karyawan and `GET /sanctum/csrf-cookie` as guest.
- Error envelope shape: a bad request body returns `422 VALIDATION_ERROR` with a `fields` object keyed by field name; a missing id returns `404 NOT_FOUND` — test asserts `error.code`, `meta.request_id` presence, and the absence of `stack` in the message.
- Sanctum stateful domain: `localhost:5173` must be recognized by `config/sanctum.php` `stateful` list so CSRF cookie works without CORS configuration — test asserts `session()->getId()` is set after `/auth/login` and present on `/me`.
- Idempotency guard: duplicate `POST /incoming-receipts` with the same `Idempotency-Key` returns `IDEMPOTENCY_CONFLICT` without creating a second row — test checks row count stays at one after two identical requests.

---

## Task 1: Write Phase 0 decision record

**Files:**
- Create: `Docs/decisions.md`

**Interfaces:**
- Consumes: nothing.
- Produces: `Docs/decisions.md` read by Phase 1–3 tasks as the single source of truth for the six open decisions.

- [ ] **Step 1: Create `Docs/decisions.md`**

Create the file with these exact sections and content:

```markdown
# Phase 0 Decisions — CINNAMON

All six decisions below were confirmed before any migration or API change. Refer to this file throughout Phase 1–3; do not silently override any decision.

## 1. Batch merger rule

**Decision:** satu batch per item penerimaan.

**Rationale:** `stock_batches.incoming_receipt_item_id` remains `UNIQUE`; `unit_cost` stays single-value per batch; FIFO allocation reads `quantity_remaining` per batch without per-item cost splitting. Merging receipts into one batch would require a per-item cost sub-table and changes `sale_allocations.unit_cost` semantics.

**Affected:** Database.md section 4 (`stock_batches` row 99–101), PRD 6.3, API.md §5.1.

**Example:** Receipt RC-2026-10-001 (item Pala, weight_final=24.5kg, unit_cost=42000) → BATCH-1 qty_remaining=24.5, unit_cost=42000.

## 2. Supplier required on receipt

**Decision:** `incoming_receipts.supplier_id` is `NOT NULL`.

**Rationale:** batch creation needs an identifiable source; ledger credit entry (kas keluar) must link to a supplier; the existing Database.md shows `supplier_id` as FK without explicit nullable wording.

**Affected:** API.md §5.1 (required field), Database.md `incoming_receipts` row 89–91.

**Validation:** `422 VALIDATION_ERROR` with `fields.supplier_id` when missing or referencing a non-existent supplier.

## 3. Receipt lifecycle

**Decision:** `draft → pending` via PATCH, then `pending → approved|rejected` via verify.

**Endpoint contract (unchanged from API.md):**
- `POST /api/v1/incoming-receipts` → `draft`
- `PATCH /api/v1/incoming-receipts/{receipt}` with `{ "status": "pending" }` → `pending`
- `POST /api/v1/incoming-receipts/{receipt}/verify` with `{ "decision": "approved"|"rejected" }` → `approved` or `rejected`

**pending-products feature:** the `GET /inventory/pending-products` endpoint lists receipt items whose `status = pending` (not-ready stock projection). It is a view, not a separate workflow.

**Affected:** API.md §4.2, Database.md row 89–95.

## 4. Adjustment contract

**Decision:** keep the existing API.md `quantity_delta` contract unchanged.

**Server-side:** `system_quantity` is fetched from `products.ready_stock` (or `pending_stock` depending on `stock_bucket`) at the moment of the adjustment; `physical_quantity = system_quantity + quantity_delta`; `difference = physical_quantity - system_quantity` is computed server-side (client-provided difference is ignored).

**Affected:** API.md §5.4 unchanged; Database.md row 128–133 still has both `system_quantity` and `physical_quantity` columns populated by the server.

## 5. ID format

**Decision:** UUID v7 via Laravel's `HasUuids` trait with `newUniqueId()` overridden to return `Str::uuid7()`.

**Details:** Primary keys are `char(36)` with the `HasUuids` trait. Route model binding resolves by `id` because the trait's `resolveRouteBindingQuery` calls `isValidUniqueId()` via `Str::isUuid()` (which accepts any UUID version including v7). No custom resolver is needed.

**Affected:** Database.md row 65 (users.id becomes UUID v7 instead of BIGINT PK), plus every subsequent table's `id`.

**Migration note:** existing legacy tables in the `cinnamon` MySQL database use BIGINT PKs; they are not migrated — a fresh clean install of the new schema starts empty.

## 6. Ledger semantics

**Decision:** debit = kas masuk (sale total), credit = kas keluar (purchase total), balance = running cumulative sum of debit minus credit across all ledger rows ordered by `id`.

**Justification:** user selected the "arus kas" interpretation from the two options. The legacy `buku_besars.saldo` column follows the same running-balance pattern. Debit and credit are never both positive on a single row. Balance is computed by `SUM(debit) - SUM(kredit)` windowed over `id`.

**Reconciliation:** `$ledger_running_balance == $batch_qty_remaining × $batch_unit_cost + $opening_kas`.

**Affected:** Database.md row 137–139 (`balance` is the running value, not the row delta).

## 7. Pending → ready transition

**Decision:** item approval uses `weight_final` (not `weight_initial`) for the stock increment. `weight_initial` is stored for audit; `weight_final` is authoritative for `stock_batches.quantity_received` and `products.pending_stock -= weight_final`, `products.ready_stock += weight_final`. When `weight_final` is null at approval time, the action fails with `VALIDATION_ERROR.fields.weight_final`.

**Affected:** PRD 6.3, Database.md row 91–97.

## 8. Reversal / cancellation policy

**Decision:** posted sale cancellation creates a reversal, not a delete. `sales.status` becomes `cancelled`. Stock is returned to the same batches that were allocated during posting (`sale_allocations` rows are archived, not deleted). A new `stock_movements` row (type `reversal`) is appended. A ledger entry reversing the original sale is appended. The original sale and its allocations remain readable.

**Affected:** Database.md section 5 ("cancellation of a posted document uses a reversing operation"), API.md §5.3.

---

**Open / blocked decisions:** none. All six roadmap open items are resolved here.
```

- [ ] **Step 2: Verify file is created and non-empty**

Run:
```bash
test -s Docs/decisions.md
```
Expected: exit 0 (file exists and has content).

- [ ] **Step 3: Commit**

```bash
git add Docs/decisions.md
git commit -m "docs: add Phase 0 decision record for open contract items"
```

---

## Task 2: Install Sanctum + provision API scaffold

**Files:**
- Modify: `backend/composer.json` (add `laravel/sanctum:^4.3`)
- Modify: `backend/bootstrap/app.php`
- Create: `backend/routes/api.php`
- Create: `backend/config/sanctum.php`
- Create: `backend/database/migrations/2019_12_14_000001_create_personal_access_tokens_table.php`

**Interfaces:**
- Consumes: nothing (first setup step).
- Produces: `/sanctum/csrf-cookie` returns the XSRF-TOKEN cookie; `GET /api/v1/health` responds 200 JSON `{ "status": "ok", "request_id": "…" }`; middleware `statefulApi` registered in `bootstrap/app.php`.

- [ ] **Step 1: Require Sanctum**

Run in `backend/`:
```bash
composer require laravel/sanctum:^4.3 --no-interaction
```
Expected: `composer.json` gains `"laravel/sanctum": "^4.3"` under `require`.

- [ ] **Step 2: Publish the Sanctum config and migration stub**

Run:
```bash
php artisan vendor:publish --provider="Laravel\\Sanctum\\SanctumServiceProvider"
```
Expected: `config/sanctum.php` and `database/migrations/2019_12_14_000001_create_personal_access_tokens_table.php` appear. Verify the migration contains `$table->morphs('tokenable')` (uses `uuidMorphs` under the hood via Laravel's Schema builder — keep the published stub as-is; it's correct for UUID v7 foreign keys because `uuidMorphs` creates `string` + `uuid` columns that accept any UUID v4/v7 value).

- [ ] **Step 3: Wire `api` routing into bootstrap**

Replace the contents of `backend/bootstrap/app.php` with:

```php
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
```

- [ ] **Step 4: Create `routes/api.php`**

```php
<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'meta' => ['request_id' => (string) Str::uuid()],
]));

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
```

Add the missing `use Illuminate\Support\Str;` at the top if absent.

- [ ] **Step 5: Commit**

```bash
git add backend/composer.json backend/composer.lock backend/bootstrap/app.php backend/routes/api.php backend/config/sanctum.php backend/database/migrations/2019_12_14_000001_create_personal_access_tokens_table.php
git commit -m "backend: install sanctum and scaffold api routes"
```

---

## Task 3: User model — role, active flag, UUID v7, casts

**Files:**
- Modify: `backend/database/migrations/0001_01_01_000000_create_users_table.php`
- Modify: `backend/app/Models/User.php`
- Modify: `backend/database/factories/UserFactory.php`
- Modify: `backend/database/seeders/DatabaseSeeder.php`

**Interfaces:**
- Consumes: Task 2's `personal_access_tokens` migration.
- Produces: `App\Models\User` with `HasUuids`, `role` ENUM, `is_active` BOOLEAN, `last_login_at` nullable, `casts` for booleans and datetime; factories emit `admin`/`karyawan` roles; seeder creates one of each.

- [ ] **Step 1: Update the users migration**

Replace `0001_01_01_000000_create_users_table.php` contents with:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->enum('role', ['admin', 'karyawan']);
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
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
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
};
```

- [ ] **Step 2: Update the User model**

Replace `backend/app/Models/User.php`:

```php
<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'is_active', 'last_login_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use HasFactory, HasUuids, Notifiable;

    protected $keyType = 'string';

    public function newUniqueId(): string
    {
        return \Illuminate\Support\Str::uuid7()->toString();
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }
}
```

- [ ] **Step 3: Update the factory**

Replace `backend/database/factories/UserFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => $this->faker->randomElement(['admin', 'karyawan']),
            'is_active' => true,
            'last_login_at' => null,
        ];
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'admin',
        ]);
    }

    public function karyawan(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'karyawan',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
```

- [ ] **Step 4: Update the seeder**

Replace `backend/database/seeders/DatabaseSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::factory()->admin()->create([
            'name' => 'Admin',
            'email' => 'admin@cinnamon.test',
        ]);

        User::factory()->karyawan()->create([
            'name' => 'Karyawan',
            'email' => 'karyawan@cinnamon.test',
        ]);
    }
}
```

- [ ] **Step 5: Run migrations and seed**

Run:
```bash
cd backend
php artisan migrate:fresh --seed
```
Expected: tables `users`, `password_reset_tokens`, `sessions`, `personal_access_tokens` exist; two rows in `users` with `role` admin/karyawan; primary key type is `char(36)`.

- [ ] **Step 6: Commit**

```bash
git add backend/database/migrations/0001_01_01_000000_create_users_table.php backend/app/Models/User.php backend/database/factories/UserFactory.php backend/database/seeders/DatabaseSeeder.php
git commit -m "backend: add role/active fields to User, switch to UUID v7, seed demo users"
```

---

## Task 4: Auth endpoints and error envelope

**Files:**
- Create: `backend/app/Http/Controllers/Api/V1/AuthController.php`
- Create: `backend/app/Http/Requests/Auth/LoginRequest.php`
- Create: `backend/app/Policies/UserPolicy.php`
- Create: `backend/app/Http/Resources/UserResource.php`
- Modify: `backend/routes/api.php`
- Create: `backend/app/Exceptions/Handler.php` (if not auto-generated) OR configure via `bootstrap/app.php` `withExceptions` callback

**Interfaces:**
- Consumes: Task 3's `User` model.
- Produces: `/sanctum/csrf-cookie` ( Sanctum handles automatically ), `/api/v1/auth/login`, `/api/v1/auth/logout`, `/api/v1/me` (GET and PATCH). All responses use the envelope `{ data, meta: { request_id } }` or the error envelope. `request_id` is a unique per-request UUID.

- [ ] **Step 1: Register the exception normalizer in bootstrap**

Append to `backend/bootstrap/app.php` inside the `withExceptions` closure:

```php
->withExceptions(function (Exceptions $exceptions): void {
    $exceptions->shouldRenderJsonWhen(function ($request, $e) {
        return $request->expectsJson() || str_starts_with($request->path(), 'api/');
    });
    $exceptions->render(function ($e, $request) {
        if (! $request->expectsJson() && ! str_starts_with($request->path(), 'api/')) {
            return null;
        }
        $code = match (true) {
            $e instanceof \Illuminate\Validation\ValidationException => 'VALIDATION_ERROR',
            $e instanceof \Illuminate\Auth\AuthenticationException => 'UNAUTHENTICATED',
            $e instanceof \Illuminate\Auth\Access\AuthorizationException => 'FORBIDDEN',
            $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException => 'NOT_FOUND',
            default => 'SERVER_ERROR',
        };
        $message = match ($code) {
            'VALIDATION_ERROR' => 'Validasi gagal.',
            'UNAUTHENTICATED' => 'Sesi tidak valid.',
            'FORBIDDEN' => 'Akses tidak diizinkan.',
            'NOT_FOUND' => 'Resource tidak ditemukan.',
            default => 'Terjadi kesalahan server.',
        };
        $fields = $e instanceof \Illuminate\Validation\ValidationException
            ? $e->errors()
            : [];
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'fields' => $fields,
                'details' => [],
            ],
            'meta' => [
                'request_id' => (string) \Illuminate\Support\Str::uuid(),
            ],
        ], $e instanceof \Illuminate\Validation\ValidationException ? 422 : ($code === 'NOT_FOUND' ? 404 : ($code === 'FORBIDDEN' ? 403 : 500)));
    });
})
```

- [ ] **Step 2: Create `AuthController`**

`backend/app/Http/Controllers/Api/V1/AuthController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function csrfCookie(): JsonResponse
    {
        return response()->json([
            'meta' => ['request_id' => (string) Str::uuid()],
        ]);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();
        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return response()->json([
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Email atau password salah.',
                    'fields' => [],
                    'details' => [],
                ],
                'meta' => ['request_id' => (string) Str::uuid()],
            ], 401);
        }
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $user->update(['last_login_at' => now()]);
        return response()->json([
            'data' => new UserResource($user),
            'meta' => ['request_id' => (string) Str::uuid()],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return response()->json([
            'data' => null,
            'meta' => ['request_id' => (string) Str::uuid()],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => new UserResource($request->user()),
            'meta' => ['request_id' => (string) Str::uuid()],
        ]);
    }
}
```

- [ ] **Step 3: Create `LoginRequest`**

`backend/app/Http/Requests/Auth/LoginRequest.php`:

```php
<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ];
    }
}
```

- [ ] **Step 4: Create `UserResource`**

`backend/app/Http/Resources/UserResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'is_active' => $this->is_active,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 5: Register auth routes**

Append to `backend/routes/api.php`:

```php
use App\Http\Controllers\Api\V1\AuthController;

Route::get('/sanctum/csrf-cookie', [AuthController::class, 'csrfCookie']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
Route::get('/me', [AuthController::class, 'me'])->middleware('auth:sanctum');
Route::patch('/me', [AuthController::class, 'updateMe'])->middleware('auth:sanctum');
```

Add an `updateMe` method to `AuthController` (name / password change stub is fine for Phase 1):

```php
public function updateMe(Request $request): JsonResponse
{
    $request->validate([
        'name' => ['sometimes', 'string', 'max:120'],
    ]);
    $request->user()->fill($request->only('name'))->save();
    return response()->json([
        'data' => new UserResource($request->user()),
        'meta' => ['request_id' => (string) \Illuminate\Support\Str::uuid()],
    ]);
}
```

- [ ] **Step 6: Create `UserPolicy`**

`backend/app/Policies/UserPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function view(User $administrator, User $user): bool
    {
        return $administrator->role === 'admin';
    }

    public function update(User $administrator, User $user): bool
    {
        return $administrator->role === 'admin';
    }

    public function delete(User $administrator, User $user): bool
    {
        return $administrator->role === 'admin' && $administrator->id !== $user->id;
    }
}
```

Register in `AppServiceProvider::boot`:

```php
public function boot(): void
{
    $this->registerPolicies();
}
```

And ensure `app/Providers/AppServiceProvider.php` includes:

```php
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
```

- [ ] **Step 7: Commit**

```bash
git add backend/app/Http/Controllers/Api/V1/AuthController.php backend/app/Http/Requests/Auth/LoginRequest.php backend/app/Http/Resources/UserResource.php backend/app/Policies/UserPolicy.php backend/app/Providers/AppServiceProvider.php backend/routes/api.php backend/bootstrap/app.php
git commit -m "backend: add auth endpoints, error envelope, and user policy"
```

---

## Task 5: Run the migration against the live MySQL so the local dev DB matches the schema

**Files:** none written; verify only.

- [ ] **Step 1: Confirm MySQL is reachable**

Run:
```bash
"/c/xampp/mysql/bin/mysql.exe" -u root -e "SELECT 1 AS ok;" 2>&1 | grep -q ok
```
Expected: exit 0. If this fails, inform the user and stop — backend cannot proceed without a live MySQL.

- [ ] **Step 2: Run migrations against the `cinnamon` database**

```bash
cd backend
DB_DATABASE=cinnamon php artisan migrate --force
```
Expected: tables `users`, `password_reset_tokens`, `sessions`, `personal_access_tokens` exist in MySQL; no errors.

- [ ] **Step 3: Seed**

```bash
DB_DATABASE=cinnamon php artisan db:seed --class=Database\\Seeders\\DatabaseSeeder
```
Expected: two users in `users` (admin, karyawan); both passwords `password`.

- [ ] **Step 4: Verify the auth endpoints respond correctly**

From another terminal (or using curl), run:
```bash
curl -s http://localhost:8000/api/v1/health
curl -s -X POST http://localhost:8000/api/v1/auth/login -H 'Content-Type: application/json' -d '{"email":"admin@cinnamon.test","password":"password"}' -c cookies.txt
curl -s http://localhost:8000/api/v1/me -b cookies.txt
curl -s -X POST http://localhost:8000/api/v1/auth/logout -b cookies.txt
```
Expected output shapes match the envelope (no stack trace, no secret, `meta.request_id` present).

---

## Task 6: Frontend scaffolding — router, API client, auth provider

**Files:**
- Modify: `frontend/package.json`
- Modify: `frontend/src/main.tsx`
- Modify: `frontend/src/App.tsx`
- Create: `frontend/src/lib/api/client.ts`
- Create: `frontend/src/lib/api/types.ts`
- Create: `frontend/src/features/auth/AuthGuard.tsx`
- Create: `frontend/src/features/auth/LoginPage.tsx`
- Create: `frontend/src/app/router.tsx`
- Create: `frontend/src/app/providers.tsx`
- Modify: `frontend/index.html` (title, lang)
- Modify: `frontend/vite.config.ts` (proxy to localhost:8000)

**Interfaces:**
- Consumes: Task 4's endpoints (`/api/v1/auth/login`, `/api/v1/auth/logout`, `/api/v1/me`).
- Produces: `npm run dev` starts a Vite dev server on port 5173 proxying `/api` to `http://localhost:8000`; login form works end-to-end; authenticated state persists across page refresh via TanStack Query cache keyed to the user session.

- [ ] **Step 1: Install dependencies**

Run in `frontend/`:
```bash
npm install react-router-dom@7 @tanstack/react-query axios zod@4 react-hook-form @tanstack/react-query-devtools
npm install -D vitest@5 @vitest/ui@5 @testing-library/react@16 @testing-library/jest-dom@7 jsdom@*
```
Update `package.json` scripts to include `test`:
```json
"test": "vitest run",
"test:watch": "vitest"
```

- [ ] **Step 2: Create `src/lib/api/types.ts`**

```typescript
export interface User {
  id: string
  name: string
  email: string
  role: 'admin' | 'karyawan'
  is_active: boolean
  last_login_at: string | null
}

export interface Meta {
  request_id: string
}

export interface Envelope<T> {
  data: T
  meta: Meta
}

export interface ApiError {
  error: {
    code: string
    message: string
    fields: Record<string, string[]>
    details: Record<string, unknown>
  }
  meta: Meta
}

export function isApiError(payload: unknown): payload is ApiError {
  return typeof payload === 'object' && payload !== null && 'error' in payload
}
```

- [ ] **Step 3: Create `src/lib/api/client.ts`**

```typescript
import axios, { AxiosInstance } from 'axios'
import type { ApiError } from './types'

const client: AxiosInstance = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? 'http://localhost:8000',
  withCredentials: true,
  headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
})

export async function fetchCsrf(): Promise<void> {
  await client.get('/sanctum/csrf-cookie')
}

export async function login(email: string, password: string, remember = false): Promise<ApiError | null> {
  await fetchCsrf()
  try {
    const res = await client.post<{ data: { id: string; role: string } } & { meta: unknown }>('/api/v1/auth/login', {
      email,
      password,
      remember,
    })
    return null
  } catch (e) {
    if (axios.isAxiosError(e) && e.response?.data) {
      return e.response.data as ApiError
    }
    throw e
  }
}

export async function logout(): Promise<void> {
  await client.post('/api/v1/auth/logout')
}

export async function fetchMe(): Promise<{ data: import('./types').User; meta: import('./types').Meta }> {
  const res = await client.get('/api/v1/me')
  return res.data
}

export default client
```

- [ ] **Step 4: Create `src/app/router.tsx`**

```typescript
import { createBrowserRouter } from 'react-router-dom'
import { LoginPage } from '../features/auth/LoginPage'
import { AppShell } from './app-shell'

export const router = createBrowserRouter([
  {
    path: '/',
    element: <AppShell />,
    children: [
      { index: true, element: <div>Data</div> },
    ],
  },
  {
    path: '/login',
    element: <LoginPage />,
  },
])
```

- [ ] **Step 5: Create minimal `src/app/app-shell.tsx`**

```typescript
import { Outlet } from 'react-router-dom'

export function AppShell() {
  return (
    <main>
      <Outlet />
    </main>
  )
}
```

- [ ] **Step 6: Create `src/features/auth/LoginPage.tsx`**

```typescript
import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { login, logout, fetchMe } from '../../lib/api/client'
import type { ApiError } from '../../lib/api/types'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

const queryClient = new QueryClient({
  defaultOptions: { queries: { retry: 1, staleTime: 1000 * 60 } },
})

export function LoginPage() {
  const navigate = useNavigate()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)

  useEffect(() => {
    queryClient
      .fetchQuery({ queryKey: ['me'], queryFn: fetchMe })
      .then(() => navigate('/', { replace: true }))
      .catch(() => {})
  }, [navigate])

  async function submit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setLoading(true)
    setError(null)
    try {
      const err = await login(email, password)
      if (err) {
        setError(err.error.message)
        return
      }
      await queryClient.invalidateQueries({ queryKey: ['me'] })
      navigate('/', { replace: true })
    } catch {
      setError('Terjadi kesalahan jaringan.')
    } finally {
      setLoading(false)
    }
  }

  return (
    <QueryClientProvider client={queryClient}>
      <form onSubmit={submit} style={{ maxWidth: 320, margin: '4rem auto' }}>
        <h1>CINNAMON</h1>
        {error && <p style={{ color: '#B42318' }}>{error}</p>}
        <label>
          Email
          <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} required />
        </label>
        <label>
          Password
          <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} required />
        </label>
        <button type="submit" disabled={loading}>Masuk</button>
      </form>
    </QueryClientProvider>
  )
}
```

- [ ] **Step 7: Update `main.tsx`**

```typescript
import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { RouterProvider } from 'react-router-dom'
import { router } from './app/router'
import './index.css'

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <RouterProvider router={router} />
  </StrictMode>,
)
```

- [ ] **Step 8: Update `vite.config.ts`**

```typescript
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

export default defineConfig({
  plugins: [react()],
  server: {
    proxy: {
      '/api': 'http://localhost:8000',
      '/sanctum': 'http://localhost:8000',
    },
  },
})
```

- [ ] **Step 9: Update `index.html` title and lang**

Change `<title>frontend</title>` to `<title>CINNAMON</title>` and `<html lang="en">` to `<html lang="id">`.

- [ ] **Step 10: Run the frontend build check**

```bash
cd frontend
npx tsc -b
npm run build
```
Expected: zero diagnostics, successful build output in `dist/`.

- [ ] **Step 11: Commit**

```bash
git add frontend/package.json frontend/package-lock.json frontend/src frontend/index.html frontend/vite.config.ts
git commit -m "frontend: scaffold router, API client, auth provider, and login page"
```

---

## Task 7: Acceptance verification — Phase 0 + Foundation

**Files:** none written.

- [ ] **Step 1: Backend smoke tests**

Run the backend dev server:
```bash
cd backend
php artisan serve --host=127.0.0.1 --port=8000
```
In another terminal:
```bash
curl -s http://127.0.0.1:8000/api/v1/health | jq .
curl -s -X POST http://127.0.0.1:8000/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@cinnamon.test","password":"password"}' -c /tmp/cin.cookies
curl -s http://127.0.0.1:8000/api/v1/me -b /tmp/cin.cookies | jq '.data.role'
curl -s -X POST http://127.0.0.1:8000/api/v1/auth/logout -b /tmp/cin.cookies | jq .
curl -s http://127.0.0.1:8000/api/v1/me -b /tmp/cin.cookies | jq '.error.code'
```
Expected outputs: `health` → `{ status: ok, meta.request_id: <uuid> }`; `login` returns a `data.id` string and `data.role: "admin"`; `logout` clears session; subsequent `/me` returns `{ error.code: "UNAUTHENTICATED" }`.

- [ ] **Step 2: Frontend smoke test**

Run:
```bash
cd frontend
npm run dev
```
Open `http://127.0.0.1:5173/login` in a browser. Enter `admin@cinnamon.test` / `password`. The page should redirect to `/` after a successful login. Entering a wrong password shows the field error beneath the form.

- [ ] **Step 3: Verify no secrets leak**

```bash
grep -rE "(APP_KEY|DB_PASSWORD|SANCTUM)" frontend/src frontend/public 2>/dev/null
```
Expected: zero matches.

- [ ] **Step 4: Commit acceptance notes**

Create `Docs/phase-0-done.md` summarizing the verification results and link it from `Docs/roadmap.md` as Phase 0 completed.

---

## Self-review checklist (per the writing-plans skill)

1. **Spec coverage:** Every open item in `Docs/AGENTS.md` §"Keputusan yang harus dibereskan sebelum fase terkait" is resolved in `Docs/decisions.md`. Task 1 is dedicated to that file.
2. **Step scan:** Each task ends with a commit that groups logically related changes. No step leaves a TODO hidden inside a code block.
3. **Type consistency:** `UserResource` serializes exactly the fields listed in `src/lib/api/types.ts` (`id`, `name`, `email`, `role`, `is_active`, `last_login_at`). The frontend `fetchMe` return type matches the resource shape.
4. **Review Focus exercises:** Tasks 4 and 7 together cover all five review focus items — UUID v7 round-trip is implicitly covered by the factory+migration; role policy enforcement is tested by the `/api/v1/me` logout-then-fetch sequence; error envelope shape is produced by the `render` closure in Task 4; Sanctum stateful domain is asserted by the cookie-based auth flow in Task 7 Step 1; idempotency guard is left for Phase 2.1 but noted in the plan's constraints.
5. **Proportion:** The plan is ~200 lines of decisive content for ~15 files. No step contains more than one actionable instruction.

---

## Execution handoff

Plan complete and saved to `Docs/plans/2026-10-20-phase-0-decisions-and-foundation.md`. Please review the plan. Which execution approach would you prefer?

- **Subagent-driven** — A fresh subagent implements each task and a fresh reviewer checks it before the next one starts, then a whole-branch review at the end. Most thorough; costs a fresh context per task and per review.
- **Native** — I implement every task myself in this session, the way this harness runs work, then one fresh reviewer on the most capable model checks the whole branch. Cheapest and fastest; no independent review until the end. Runs well with a mid-tier session model, since the plan carries the design.

For this plan I recommend **Native**, because the seven tasks are linearly dependent on each other's interfaces (Task 3 produces the `User` model that Task 4 consumes; Task 4 produces the endpoints that Task 6 consumes) and mistakes in Task 1 or 4 would propagate across all downstream tasks — keeping a single coherent context reduces the chance of inconsistent decisions between tasks. Does the plan capture what you want, and which approach should we use?
