# Phase 1 — Foundation dan Autentikasi Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Men foundations dua aplikasi yang bisa dijalankan lokal — Laravel 13 API dengan Sanctum SPA auth dan React TypeScript dengan app shell Design.md — sampai instalasi bersih bisa migrate+seed, health endpoint merespons, Admin/Karyawan bisa login dan('/me'), akses tidak berhak ditolak 403, dan UI menampilkan loading/error dengan benar.

**Architecture:** Satu monorepo: `backend/` (Laravel 13 REST API) dan `frontend/` (React 19 + TypeScript + Vite). Frontend dev server (5173) mem-proxy `/api` dan `/sanctum` ke backend (8000), jadi cookie session Sanctum tetap first-party dan CORS tidak perlu diaktifkan untuk alur dev. Backend menyimpan schema baru di database MySQL `cinnamon_v2`; database `cinnamon` (schema legacy Laravel 12 + fixture TA) tidak tersentuh. Backend adalah sumber kebenaran untuk auth, role, dan permission; React hanya menampilkan dan mengirim intent.

**Tech Stack:**
- Backend: Laravel 13.37, PHP 8.5.11, MySQL 8 (XAMPP di `127.0.0.1:3306`, user `root`, password kosong), Laravel Sanctum `^4.3`, PHPUnit 12.
- Frontend: Vite 8.3, React 19, TypeScript 6.0, React Router DOM 7, TanStack Query 5, Zod 4, React Hook Form, Vitest 5 + Testing Library React 16.
- Toolchain terverifikasi: PHP 8.5.11 CLI, Composer (versi tidak dibaca karena deprecation noise), Node 26.2.0, npm 11.13.0, MySQL di `C:\xampp\mysql\bin\mysql.exe`.

**Spec:** `Docs/PRD.md` §4, §6.1, §7 (US-01), §8 "Identity and access", §9, §11; `Docs/Architecture.md` §2, §5, §6, §7, §8, §9, §11, §12, §14; `Docs/API.md` §2, §2.1, §3, §3.1, §4.1 (baris `/users`), §6, §7, §10, §11, §12; `Docs/Database.md` §2, §4 (tabel `users`), §6, §9; `Docs/Design.md` §1, §2, §3, §5, §6, §7, §8, §9, §10 (Dashboard), §11; `Docs/roadmap.md` "Phase 1 — Foundation dan autentikasi"; `Docs/AGENTS.md` §3, §5, §6, §7, §8; `Docs/decisions.md` (dibuat di Phase 0) §5 (format ID UUID v7) dan §6 (arti ledger).

**Prasyarat (Phase 0 — dianggap sudah selesai):** `Docs/decisions.md` ada; Sanctum terpasang; `User` model sudah UUID v7 + `role` ENUM + `is_active`; migration `users` sudah char(36); seeder sudah membuat `admin@cinnamon.test` dan `karyawan@cinnamon.test` (password `password`); `routes/api.php` sudah punya `/health`; `frontend/src/lib/api/client.ts`, `frontend/src/lib/api/types.ts`, `frontend/src/features/auth/LoginPage.tsx`, `frontend/src/app/router.tsx` sudah ada.

> **Status per 2026-10-21 — Phase 0 sudah dieksekusi** (branch `feat/phase-0`, commit `f882fe8`; ringkasan di `Docs/phase-0-done.md`).
> consequence: Task 1–6 dan Task 9–10 di plan ini **sudah terpenuhi** dan tidak perlu diulang. Verifikasi ulang dengan:
>
> ```bash
> cd backend && php artisan test          # 31 passed
> cd frontend && npm test                # 17 passed
> ```
>
> Yang **belum** ada dan masih jadi pekerjaan Phase 1: app shell penuh per `Docs/Design.md` §3 (sidebar + topbar + modul navigasi), `ProfilePage` dengan ganti password, endpoint `POST /api/v1/me/password`, endpoint `PATCH /api/v1/me` yang sudah ada, `UserController` lengkap (`GET/POST /users`, `GET/PATCH /users/{user}`), `NotFoundPage`, `PageState` (skeleton/empty/error), guard berbasis role di router, `prettier`, dan `Docs/setup.md`.
>
> Deviasi Phase 0 yang harus dihormati saat menulis task:
>
> 1. `App\Support\RequestId::current()` membaca dari `$request->attributes`, bukan static — jangan ganti dengan static.
> 2. Error renderer memetakan `AccessDeniedHttpException` (bukan hanya `AuthorizationException`) ke `FORBIDDEN`, karena Laravel rewrite exception sebelum render callback jalan. Status 419 (CSRF) dipetakan dari `getStatusCode()`, bukan dari `TokenMismatchException`.
> 3. `Auth::guard('web')` dipakai eksplisit di login dan logout — middleware `auth:sanctum` mengganti default guard ke `RequestGuard` yang tidak punya `logout()`.
> 4. `AuthController::login` mengecek `is_active` **sebelum** `Hash::check` dan menjawab 401 dengan pesan yang sama untuk email tak dikenal, password salah, dan user non-aktif.
> 5. Timestamp diserialisasi lewat `App\Support\Timestamp::iso8601()` (Asia/Jakarta, `+07:00`), bukan `->toIso8601String()` langsung.
> 6. `lang/id/validation.php` + `APP_LOCALE=id` — pesan validasi tampil apa adanya di UI.
> 7. Frontend `baseURL` default **relatif** (`''`), bukan `http://localhost:8000`, supaya trafik dev lewat Vite proxy dan cookie session tetap first-party.
> 8. Backend test memakai `cinnamon_v2` yang sama dengan dev + trait `DatabaseTransactions` (sudah ada di `phpunit.xml`).
> 9. `GET /api/v1/users` **sudah ada** di Phase 0 (Admin-only, paginated) — Task 5/6 Phase 1 jangan menimpanya, tapi lengkapi.
> 10. `frontend/src/features/auth/auth-context.ts` sudah memisahkan `AuthContext` + `useAuth` dari file komponen demi aturan `react-refresh/only-export-components`. Jaga pemisahan itu.


---

## Global Constraints

- Prefix API adalah `/api/v1` (bukan `/api`) — `withRouting(apiPrefix: 'api/v1')`.
- Semua response sukses: `{ "data": …, "meta": { "request_id": "…" } }`. Semua error: `{ "error": { "code", "message", "fields", "details" }, "meta": { "request_id" } }`.
- `request_id` adalah UUID v4 baru per request,出现在 setiap response (sukses maupun error) dan di dalam `Log::withContext()`.
- Mapping status error (wajib, dipin dari `Docs/API.md` §7): `VALIDATION_ERROR` → 422, `UNAUTHENTICATED` → 401, `FORBIDDEN` → 403, `NOT_FOUND` → 404, `BAD_REQUEST` → 400, `RATE_LIMITED` → 429, `SERVER_ERROR` → 500.
- Password user non-aktif (`is_active = false`) ditolak dengan `UNAUTHENTICATED` (401), bukan 403 — user belum terautentikasi.
- `DOCUMENT_ALREADY_POSTED`, `RECEIPT_ALREADY_APPROVED`, `INSUFFICIENT_STOCK`, `IDEMPOTENCY_CONFLICT` → 409 (kontrak, dipakai Phase 2–3; tidak ada endpoint yang memakainya di Phase 1).
- Response tidak pernah memuat stack trace, SQL, `APP_KEY`, password, atau token.
- Extension PHP `intl` **tidak terpasang** di mesin ini — `Illuminate\Support\Number::format()` akan melempar exception. Selalu format angka dengan `number_format()` bawaan PHP, bukan `Number::format()`.
- Timezone aplikasi `Asia/Jakarta` (`config/app.php` `'timezone' => 'Asia/Jakarta'`); timestamp disimpan UTC, ditampilkan `+07:00`; format ISO 8601.
- Pagination default `per_page` 20, maksimum 100. Filter/sort tak dikenal → `INVALID_FILTER` (422).
- Dua role saja: `admin` dan `karyawan`. `admin` boleh akses semua; `karyawan` hanya modul Dashboard, Incoming Goods, Inventory (baca), dan Profil.
- `.env` backend tidak masuk git. `.env` frontend hanya berisi `VITE_API_URL` dan konfigurasi non-rahasia.
- Backend `.env` DB: `DB_DATABASE=cinnamon_v2`, `DB_HOST=127.0.0.1`, `DB_PORT=3306`, `DB_USERNAME=root`, `DB_PASSWORD=` (kosong), `DB_CONNECTION=mysql`.
- `SANCTUM_STATEFUL_DOMAINS=localhost:5173,127.0.0.1:5173,localhost:8000,127.0.0.1:8000` — dev frontend berjalan di 5173, backend di 8000.
- Session: `SESSION_DRIVER=database`, `SESSION_SAME_SITE=lax`, `SESSION_PATH=/`, `SESSION_DOMAIN=null`, `SESSION_SECURE_COOKIE=false`.
- `CORS`: `config/cors.php` dengan `paths` = `['api/*', 'sanctum/csrf-cookie']`, `allowed_origins` = `['http://localhost:5173', 'http://127.0.0.1:5173']`, `supports_credentials` = true. Dev tetap pakai Vite proxy, tapi CORS diaktifkan agar build statis di 5174 juga berfungsi.
- Frontend TypeScript harus lolos `tsc -b` dengan `noUnusedLocals`, `noUnusedParameters`, `erasableSyntaxOnly` (dari `tsconfig.app.json` yang sudah ada).
- Frontend harus lolos ESLint flat config yang sudah ada **tanpa menambah rule**. Catatan: rule `react-refresh/only-export-components` = error dan `react-hooks/*` (reactivity, purity, static-components, set-state-in-effect) = error. File yang mengekspor komponen React **sekaligus** hook akan gagal lint — karena itu `useAuth` dan `AuthContext` dipisah ke `frontend/src/features/auth/auth-context.ts` (file tanpa komponen), sedangkan `AuthProvider.tsx` hanya mengekspor komponen.
- Money/quantity diserialisasi sebagai string, 2 desimal untuk uang, 3 desimal untuk quantity.
- Bahasa antarmuka: Bahasa Indonesia.
- Tidak ada dependensi baru di luar daftar di Tech Stack.
- **Test backend memakai database `cinnamon_v2` yang sama dengan dev** (bukan `:memory:`, bukan database terpisah), dibungkus trait `DatabaseTransactions` sehingga setiap test rollback otomatis. Tidak membuat folder/database test tambahan.
- Database `cinnamon` berisi fixture TA legacy (4 penerimaan, 4 penjualan, 9 baris ledger, 2 batch) dengan tabel `produks`, `produk_masuks`, `buku_besars`, `batch_stoks`. Database itu **tidak boleh** dipakai untuk schema baru dan tidak boleh dihapus — Plan Phase 2/3 memakainya sebagai acceptance example.
- `mysql.exe` ada di `C:\xampp\mysql\bin\mysql.exe` dengan user `root` dan password kosong.

---

## Review Focus

Enam kondisi berikut paling mungkin menggigit pengguna nyata dan **tidak** diuji oleh task mana pun dalam plan ini — masing-masing harus punya test di task yang memiliki kodenya:

1. **Karyawan yang sudah login poignant memanggil endpoint Admin** → harus dapat `403 FORBIDDEN` dengan `meta.request_id`, bukan `401` dan bukan `500`. Test di Task 6 (`AuthorizationTest`) dan Task 8 (`AuthEdgeCaseTest`).
2. **Permintaan ke endpoint terproteksi tanpa session sama sekali** → harus dapat `401 UNAUTHENTICATED`, bukan `500` (regresi dari Phase 0 yang memetakan `UNAUTHENTICATED` ke 500). Test di Task 3 (`ErrorEnvelopeTest`) dan Task 8 (`AuthEdgeCaseTest`).
3. **User dengan `is_active = false` mencoba login dengan password benar** → harus dapat `401 UNAUTHENTICATED` dan tidak boleh ada session yang dibuat. Test di Task 6 (`AuthenticationTest`) dan Task 8 (`AuthEdgeCaseTest`).
4. **Frontend memanggil API tanpa `X-XSRF-TOKEN`** → Laravel membalas `419 TokenMismatchException`, yang harus muncul sebagai pesan yang bisa dipahami user ("Sesi kedaluwarsa, silakan muat ulang"), bukan crash atau "Terjadi kesalahan jaringan." Test di Task 13 (`client.test.ts`).
5. **Tabel `users` yang sudah ada berisi baris dengan `id` bigint** (kekWARisan database `laravel`/legacy) — migrasi UUID v7 harus gagal dengan jelas, bukan diam-diam menebak. Test di Task 4 (`HealthTest`) plus verifikasi tipe kolom di Task 5 Step 7.
6. **Session cookie harus diteruskan lintas-origin dari browser ke API** — Sanctum hanya memperlakukan request sebagai stateful bila header `Referer` (atau `Origin`) cocok dengan `sanctum.stateful`. Tanpa itu, session hilang dan user tampak ter-logout sendiri secara acak. Test di Task 14 Step 3 (login → `/me` dengan cookie yang sama) dan Task 2 (konfigurasi `SANCTUM_STATEFUL_DOMAINS`).

---

## Peta File

```
backend/
  app/
    Http/
      Middleware/RequestIdMiddleware.php        (BARU) — attach request_id ke request+response+log
      Controllers/Api/V1/AuthController.php     (MODIFIKASI Phase 0) — +password, +profile update
      Requests/Auth/LoginRequest.php            (MODIFIKASI Phase 0) — pisahkan remember dari kredensial
      Requests/Auth/UpdatePasswordRequest.php   (BARU)
      Resources/UserResource.php                (MODIFIKASI Phase 0) — +updated_at
    Policies/UserPolicy.php                     (MODIFIKASI Phase 0) — +viewAny
    Support/ApiResponse.php                     (BARU) — helper envelope success/error
    Support/RequestId.php                       (BARU) — ambil/pasang request_id
  bootstrap/app.php                             (MODIFIKASI) — apiPrefix, middleware, exception renderer
  config/
    app.php                                     (MODIFIKASI) — timezone Asia/Jakarta
    cors.php                                    (BARU)
    sanctum.php                                 (MODIFIKASI Phase 0) — stateful
  database/seeders/DatabaseSeeder.php           (MODIFIKASI Phase 0) — +kategori/supplier/produk demo
  routes/api.php                                (MODIFIKASI) — prefix, endpoint auth lengkap, /users
  tests/
    Feature/Auth/AuthenticationTest.php         (BARU)
    Feature/Auth/AuthorizationTest.php          (BARU)
    Feature/Auth/ErrorEnvelopeTest.php          (BARU)
    Feature/HealthTest.php                      (BARU)
    TestCase.php                                (MODIFIKASI) — trait DatabaseTransactions

frontend/
  src/
    app/
      router.tsx                                (MODIFIKASI) — protected routes, 404, role guard
      providers.tsx                             (BARU) — QueryClientProvider + AuthProvider
      app-shell.tsx                             (BARU) — sidebar + topbar + Outlet
      app-shell.css                             (BARU)
    components/
      layout/Sidebar.tsx                        (BARU)
      layout/Topbar.tsx                         (BARU)
      feedback/PageState.tsx                    (BARU) — loading skeleton / empty / error
    features/
      auth/auth-context.ts                      (BARU) — context + hook useAuth (bukan file komponen)
      auth/AuthProvider.tsx                     (BARU) — komponen provider saja
      auth/AuthGuard.tsx                        (BARU)
      auth/LoginPage.tsx                        (MODIFIKASI Phase 0) — RHF+Zod, token Design.md
      auth/LoginPage.css                        (BARU)
      auth/ProfilePage.tsx                      (BARU) — ubah nama + password
      dashboard/DashboardPage.tsx               (BARU) — placeholder role-aware
    lib/
      api/client.ts                             (MODIFIKASI Phase 0) — XSRF, normalisasi error
      api/types.ts                              (MODIFIKASI Phase 0)
      api/errors.ts                             (BARU) — ApiError + parsing 419
      formatters/number.ts                      (BARU) — format uang/quantity tanpa intl
    styles/tokens.css                           (BARU) — token Design.md §2
    test/setup.ts                              (BARU)
    features/auth/LoginPage.test.tsx            (BARU)
    features/auth/AuthGuard.test.tsx            (BARU)
    lib/formatters/number.test.ts               (BARU)
  vite.config.ts                                (MODIFIKASI) — proxy /api + /sanctum, test config
  package.json                                  (MODIFIKASI) — deps + scripts test/lint/format
  index.html                                    (MODIFIKASI) — title, lang
```

---

## Task 1: Git init, `.gitignore` root, dan env backend

**Files:**
- Create: `.gitignore` (root)
- Modify: `backend/.env`
- Create: `backend/.env.example`

**Interfaces:**
- Consumes: tidak ada.
- Produces: repository git yang berfungsi; `backend/.env` menunjuk `cinnamon_v2`; `backend/.env.example` tanpa secret.

- [ ] **Step 1: Inisialisasi git di root repo**

Run:
```bash
cd /d/DEV/0.AI/Cinnamon2 && git init && git checkout -b feat/phase-1-foundation
```
Expected: `Initialized empty Git repository` dan branch aktif `feat/phase-1-foundation`.

- [ ] **Step 2: Buat `.gitignore` root**

Buat file `.gitignore` di root repo dengan isi persis:

```
# Dependencies
/backend/vendor/
/frontend/node_modules/

# Build output
/backend/public/build/
/backend/public/hot
/frontend/dist/

# Env
.env
.env.*
!.env.example

# Laravel
/backend/storage/*.key
/backend/bootstrap/cache/*.php
/backend/.phpunit.result.cache

# Editors / OS
.DS_Store
Thumbs.db
.idea/
.vscode/
```

- [ ] **Step 3: Update `backend/.env` untuk database baru dan Sanctum**

Edit `backend/.env` — ganti baris berikut (sisanya tetap):

```
APP_NAME=CINNAMON
APP_URL=http://localhost:8000
APP_TIMEZONE=Asia/Jakarta

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=cinnamon_v2
DB_USERNAME=root
DB_PASSWORD=

SESSION_DRIVER=database
SESSION_SAME_SITE=lax
SESSION_PATH=/
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=false

SANCTUM_STATEFUL_DOMAINS=localhost:5173,127.0.0.1:5173,localhost:8000,127.0.0.1:8000

CORS_ALLOWED_ORIGINS=http://localhost:5173,http://127.0.0.1:5173
```

- [ ] **Step 4: Buat `backend/.env.example`**

Buat `backend/.env.example` dengan isi persis:

```
APP_NAME=CINNAMON
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost:8000
APP_TIMEZONE=Asia/Jakarta

APP_LOCALE=id
APP_FALLBACK_LOCALE=en

LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=debug

BCRYPT_ROUNDS=12

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=cinnamon_v2
DB_USERNAME=root
DB_PASSWORD=

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=false
SESSION_SAME_SITE=lax

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database
CACHE_STORE=database

SANCTUM_STATEFUL_DOMAINS=localhost:5173,127.0.0.1:5173,localhost:8000,127.0.0.1:8000

CORS_ALLOWED_ORIGINS=http://localhost:5173,http://127.0.0.1:5173

MAIL_MAILER=log
MAIL_HOST=127.0.0.1
MAIL_PORT=2525
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_FROM_ADDRESS="hello@cinnamon.test"
MAIL_FROM_NAME="${APP_NAME}"
```

- [ ] **Step 5: Pastikan `.env` tidak akan ter-commit**

Run:
```bash
cd /d/DEV/0.AI/Cinnamon2 && git check-ignore -v backend/.env
```
Expected: output mentioning `backend/.env` is ignored by `.gitignore` rule `.env`.

- [ ] **Step 6: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add .gitignore backend/.env.example && git commit -m "chore: init git repo and add backend env example"
```
`backend/.env` tidak ikut ter-commit (sudah di-ignore).

---

## Task 2: Database `cinnamon_v2`, konfigurasi app, dan CORS

**Files:**
- Modify: `backend/config/app.php` (baris `'timezone'`)
- Create: `backend/config/cors.php`
- Modify: `backend/config/sanctum.php` (key `stateful`)

**Interfaces:**
- Consumes: `backend/.env` dari Task 1.
- Produces: `config('app.timezone') === 'Asia/Jakarta'`; `config('cors.allowed_origins')` berisi dua origin Vite; `config('sanctum.stateful')` berisi `localhost:5173` dan `127.0.0.1:5173`.

- [ ] **Step 1: Buat database `cinnamon_v2`**

Run:
```bash
"/c/xampp/mysql/bin/mysql.exe" -u root -e "CREATE DATABASE IF NOT EXISTS cinnamon_v2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```
Expected: exit 0, tanpa output. Database `cinnamon` (legacy) tidak boleh berubah — verifikasi dengan:
```bash
"/c/xampp/mysql/bin/mysql.exe" -u root -e "SHOW TABLES FROM cinnamon;"
```
Expected: 18 tabel legacy tetap ada (`produks`, `produk_masuks`, `buku_besars`, `batch_stoks`, dst).

- [ ] **Step 2: Set timezone aplikasi**

Edit `backend/config/app.php`, ganti `'timezone' => 'UTC',` menjadi `'timezone' => env('APP_TIMEZONE', 'Asia/Jakarta'),`.

- [ ] **Step 3: Buat `backend/config/cors.php`**

Buat file dengan isi persis:

```php
<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173,http://127.0.0.1:5173'))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
```

- [ ] **Step 4: Set domain stateful Sanctum**

Edit `backend/config/sanctum.php`, ganti seluruh blok `'stateful' => ...` dengan:

```php
    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS',
        'localhost:5173,127.0.0.1:5173,localhost:8000,127.0.0.1:8000')),
```

- [ ] **Step 5: Verifikasi konfigurasi terbaca**

Run:
```bash
cd /d/DEV/0.AI/Cinnamon2/backend && php artisan config:clear && php artisan config:show app 2>&1 | grep -i timezone
```
Expected: baris berisi `Asia/Jakarta`.

- [ ] **Step 6: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add backend/config/app.php backend/config/cors.php backend/config/sanctum.php && git commit -m "backend: set Asia/Jakarta timezone, add cors config, widen sanctum stateful domains"
```

---

## Task 3: Helper envelope, `request_id`, dan error renderer

**Files:**
- Create: `backend/app/Support/RequestId.php`
- Create: `backend/app/Support/ApiResponse.php`
- Create: `backend/app/Http/Middleware/RequestIdMiddleware.php`
- Modify: `backend/bootstrap/app.php`
- Create: `backend/tests/Feature/ErrorEnvelopeTest.php`

**Interfaces:**
- Consumes: `bootstrap/app.php` dari Phase 0.
- Produces:
  - `App\Support\RequestId::current(): string` — UUID v4 request ini.
  - `App\Support\ApiResponse::success(mixed $data, int $status = 200): JsonResponse`
  - `App\Support\ApiResponse::error(string $code, string $message, array $fields = [], array $details = [], int $status = 422): JsonResponse`
  - `App\Http\Middleware\RequestIdMiddleware` — middleware `request.id` yang menambah `request_id` ke setiap response (header `X-Request-Id` + `meta.request_id`) dan ke `Log::withContext()`.
  - Semua error API dengan status: `VALIDATION_ERROR` 422, `UNAUTHENTICATED` 401, `FORBIDDEN` 403, `NOT_FOUND` 404, `BAD_REQUEST` 400, `RATE_LIMITED` 429, `SERVER_ERROR` 500.

- [ ] **Step 1: Tulis test yang gagal**

Buat `backend/tests/Feature/ErrorEnvelopeTest.php`:

```php
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
        $response = $this->getJson('/api/v1/tidak-ada');

        $message = $response->json('error.message');

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
```

- [ ] **Step 2: Jalankan test untuk memastikan gagal**

Run:
```bash
cd /d/DEV/0.AI/Cinnamon2/backend && php artisan test --filter=ErrorEnvelopeTest
```
Expected: FAIL — `error.code` belum ada atau status bukan 422/401 (Phase 0 memetakan `UNAUTHENTICATED` ke 500 dan `NOT_FOUND` ke 404 tanpa envelope).

- [ ] **Step 3: Buat `RequestId`**

Buat `backend/app/Support/RequestId.php`:

```php
<?php

namespace App\Support;

use Illuminate\Support\Str;

class RequestId
{
    public const ATTRIBUTE = 'request_id';

    public static function current(): string
    {
        $request = request();

        if (! $request->attributes->has(self::ATTRIBUTE)) {
            $request->attributes->set(self::ATTRIBUTE, (string) Str::uuid());
        }

        return (string) $request->attributes->get(self::ATTRIBUTE);
    }
}
```

- [ ] **Step 4: Buat `ApiResponse`**

Buat `backend/app/Support/ApiResponse.php`:

```php
<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    public static function success(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => ['request_id' => RequestId::current()],
        ], $status);
    }

    public static function error(
        string $code,
        string $message,
        array $fields = [],
        array $details = [],
        int $status = 422,
    ): JsonResponse {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'fields' => (object) $fields,
                'details' => (object) $details,
            ],
            'meta' => ['request_id' => RequestId::current()],
        ], $status);
    }
}
```

- [ ] **Step 5: Buat `RequestIdMiddleware`**

Buat `backend/app/Http/Middleware/RequestIdMiddleware.php`:

```php
<?php

namespace App\Http\Middleware;

use App\Support\RequestId;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class RequestIdMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = RequestId::current();

        Log::withContext(['request_id' => $requestId]);

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
```

- [ ] **Step 6: Pasang middleware, prefix, dan exception renderer**

Ganti seluruh isi `backend/bootstrap/app.php` dengan:

```php
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->api(prepend: [
            \App\Http\Middleware\RequestIdMiddleware::class,
        ]);
        $middleware->alias([
            'request.id' => \App\Http\Middleware\RequestIdMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e): bool {
            return $request->expectsJson() || $request->is('api/*');
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->expectsJson() && ! $request->is('api/*')) {
                return null;
            }

            $status = match (true) {
                $e instanceof \Illuminate\Validation\ValidationException => 422,
                $e instanceof \Illuminate\Auth\AuthenticationException => 401,
                $e instanceof \Illuminate\Auth\Access\AuthorizationException => 403,
                $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException => 404,
                $e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException => 404,
                $e instanceof \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException => 400,
                $e instanceof \Illuminate\Http\Exceptions\ThrottleRequestsException => 429,
                default => 500,
            };

            $code = match ($status) {
                422 => 'VALIDATION_ERROR',
                401 => 'UNAUTHENTICATED',
                403 => 'FORBIDDEN',
                404 => 'NOT_FOUND',
                400 => 'BAD_REQUEST',
                429 => 'RATE_LIMITED',
                default => 'SERVER_ERROR',
            };

            $message = match ($code) {
                'VALIDATION_ERROR' => 'Validasi gagal.',
                'UNAUTHENTICATED' => 'Sesi tidak valid. Silakan masuk kembali.',
                'FORBIDDEN' => 'Akses tidak diizinkan.',
                'NOT_FOUND' => 'Resource tidak ditemukan.',
                'BAD_REQUEST' => 'Permintaan tidak dapat diproses.',
                'RATE_LIMITED' => 'Terlalu banyak permintaan.',
                default => 'Terjadi kesalahan server.',
            };

            $fields = $e instanceof \Illuminate\Validation\ValidationException ? $e->errors() : [];

            return response()->json([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                    'fields' => (object) $fields,
                    'details' => (object) [],
                ],
                'meta' => ['request_id' => \App\Support\RequestId::current()],
            ], $status);
        });
    })->create();
```

- [ ] **Step 7: Jalankan test untuk memastikan lulus**

Run:
```bash
cd /d/DEV/0.AI/Cinnamon2/backend && php artisan test --filter=ErrorEnvelopeTest
```
Expected: PASS — 5 test, 5 assertion sets green.

- [ ] **Step 8: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add backend/app/Support backend/app/Http/Middleware backend/bootstrap/app.php backend/tests/Feature/ErrorEnvelopeTest.php && git commit -m "backend: add api envelope, request id middleware, and error renderer"
```

---

## Task 4: Model `User` — UUID v7, role, is_active, dan policy

**Files:**
- Modify: `backend/app/Models/User.php`
- Modify: `backend/app/Providers/AppServiceProvider.php`
- Create: `backend/tests/Feature/HealthTest.php`

**Interfaces:**
- Consumes: migration `users` dari Phase 0 (kolom `id` char(36), `role` ENUM, `is_active`).
- Produces:
  - `App\Models\User` — trait `HasUuids` (Laravel 13 sudah memakai `Str::uuid7()` secara bawaan), `$keyType = 'string'`, casts `is_active` → boolean, `email_verified_at`/`last_login_at` → datetime, `password` → hashed.
  - `App\Models\User::isAdmin(): bool` — true bila `role === 'admin'`.
  - `App\Policies\UserPolicy` dengan method `viewAny`, `view`, `update`, `delete` — semuanya true hanya untuk actor `role === 'admin'`.

- [ ] **Step 1: Tulis test yang gagal**

Buat `backend/tests/Feature/HealthTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_endpoint_returns_ok_with_request_id(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonStructure(['data' => ['status'], 'meta' => ['request_id']]);
    }

    public function test_user_id_is_uuid_v7_and_findable_by_string(): void
    {
        $user = User::factory()->admin()->create();

        $this->assertTrue(Str::isUuid($user->id));
        $this->assertSame('7', $user->id[14]);
        $this->assertNotNull(User::find($user->id));
    }

    public function test_user_id_is_not_a_bigint(): void
    {
        $user = User::factory()->karyawan()->create();

        $this->assertIsString($user->id);
        $this->assertNull(User::find('1'));
    }

    public function test_is_admin_reflects_role(): void
    {
        $this->assertTrue(User::factory()->admin()->create()->isAdmin());
        $this->assertFalse(User::factory()->karyawan()->create()->isAdmin());
    }
}
```


- [ ] **Step 2: Jalankan test untuk memastikan gagal**

Run:
```bash
cd /d/DEV/0.AI/Cinnamon2/backend && php artisan test --filter=HealthTest
```
Expected: FAIL — `isAdmin()` belum ada, dan `data.status` mungkin belum ada di `/api/v1/health` (Phase 0 mengembalikan `{ status, meta }` tanpa `data`).

- [ ] **Step 3: Update `User` model**

Ganti seluruh isi `backend/app/Models/User.php` dengan:

```php
<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'is_active', 'last_login_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }
}
```

Catatan: `HasUuids` di Laravel 13 sudah menghasilkan `Str::uuid7()` — jangan override `newUniqueId()`, dan `getKeyType()`/`getIncrementing()` sudah otomatis `string`/`false`.

- [ ] **Step 4: Update `UserPolicy` dan daftarkan di provider**

Ganti seluruh isi `backend/app/Policies/UserPolicy.php` dengan:

```php
<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isAdmin();
    }

    public function view(User $actor, User $user): bool
    {
        return $actor->isAdmin() || $actor->id === $user->id;
    }

    public function update(User $actor, User $user): bool
    {
        return $actor->isAdmin() || $actor->id === $user->id;
    }

    public function delete(User $actor, User $user): bool
    {
        return $actor->isAdmin() && $actor->id !== $user->id;
    }
}
```

Ganti `backend/app/Providers/AppServiceProvider.php` dengan:

```php
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerPolicies();
    }
}
```

- [ ] **Step 5: Ubah `routes/api.php` supaya `/health` memakai envelope**

Ganti seluruh isi `backend/routes/api.php` dengan:

```php
<?php

use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => ApiResponse::success(['status' => 'ok']))
    ->name('health');
```

- [ ] **Step 6: Jalankan test untuk memastikan lulus**

Run:
```bash
cd /d/DEV/0.AI/Cinnamon2/backend && php artisan test --filter=HealthTest
```
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add backend/app/Models/User.php backend/app/Policies/UserPolicy.php backend/app/Providers/AppServiceProvider.php backend/routes/api.php backend/tests/Feature/HealthTest.php && git commit -m "backend: finalize user model with uuid v7, role helpers, and admin-only user policy"
```

---

## Task 5: Migration database, factory, dan seeder demo

**Files:**
- Modify: `backend/database/migrations/0001_01_01_000000_create_users_table.php`
- Modify: `backend/database/factories/UserFactory.php`
- Modify: `backend/database/seeders/DatabaseSeeder.php`

**Interfaces:**
- Consumes: schema `cinnamon_v2` dari Task 2.
- Produces:
  - Tabel `users` dengan `id` char(36) primary key, `role` ENUM('admin','karyawan'), `is_active` BOOLEAN default true, `last_login_at` nullable.
  - `UserFactory` dengan state `admin()`, `karyawan()`, `inactive()`.
  - Seeder membuat: `admin@cinnamon.test` / `karyawan@cinnamon.test` (password `password`), 2 kategori, 2 supplier, 3 produk dengan `ready_stock`/`pending_stock` nol.

- [ ] **Step 1: Pastikan database kosong dan tabel legacy aman**

Run:
```bash
"/c/xampp/mysql/bin/mysql.exe" -u root -e "SELECT COUNT(*) AS legacy_produks FROM cinnamon.produks;"
```
Expected: `1` — data legacy utuh, tidak boleh berubah.

- [ ] **Step 2: Update migration `users`**

Ganti seluruh isi `backend/database/migrations/0001_01_01_000000_create_users_table.php` dengan:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
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
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
```

- [ ] **Step 3: Jalankan migration fresh**

Run:
```bash
cd /d/DEV/0.AI/Cinnamon2/backend && php artisan migrate:fresh --force
```
Expected: `users`, `password_reset_tokens`, `sessions` (+ `personal_access_tokens` dari Phase 0) dibuat di `cinnamon_v2`. Tidak ada error foreign key.

- [ ] **Step 4: Update `UserFactory`**

Ganti seluruh isi `backend/database/factories/UserFactory.php` dengan:

```php
<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password = null;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => 'karyawan',
            'is_active' => true,
            'last_login_at' => null,
        ];
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => ['role' => 'admin']);
    }

    public function karyawan(): static
    {
        return $this->state(fn (array $attributes) => ['role' => 'karyawan']);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
```

- [ ] **Step 5: Update `DatabaseSeeder`**

Ganti seluruh isi `backend/database/seeders/DatabaseSeeder.php` dengan:

```php
<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()->admin()->create([
            'name' => 'Admin CINNAMON',
            'email' => 'admin@cinnamon.test',
        ]);

        User::factory()->karyawan()->create([
            'name' => 'Karyawan CINNAMON',
            'email' => 'karyawan@cinnamon.test',
        ]);
    }
}
```

Catatan: seeder sengaja hanya membuat user. Kategori/supplier/produk dibuat di Phase 2.1, bukan Phase 1 — jangan menambahkannya di sini.

- [ ] **Step 6: Jalankan seeder**

Run:
```bash
cd /d/DEV/0.AI/Cinnamon2/backend && php artisan db:seed --force
```
Expected: 2 baris di `users` — `admin@cinnamon.test` (role `admin`) dan `karyawan@cinnamon.test` (role `karyawan`).

- [ ] **Step 7: Verifikasi tipe kolom `id`**

Run:
```bash
"/c/xampp/mysql/bin/mysql.exe" -u root -e "SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='cinnamon_v2' AND TABLE_NAME='users' AND COLUMN_NAME IN ('id','role','is_active');"
```
Expected: `id` → `char(36)`, `role` → `enum('admin','karyawan')`, `is_active` → `tinyint(1)`.

- [ ] **Step 8: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add backend/database/migrations/0001_01_01_000000_create_users_table.php backend/database/factories/UserFactory.php backend/database/seeders/DatabaseSeeder.php && git commit -m "backend: use uuid v7 users table and seed demo admin and karyawan"
```

---

## Task 6: `TestCase` dengan `DatabaseTransactions` dan auth test suite

**Files:**
- Modify: `backend/tests/TestCase.php`
- Create: `backend/tests/Feature/Auth/AuthenticationTest.php`
- Create: `backend/tests/Feature/Auth/AuthorizationTest.php`

**Interfaces:**
- Consumes: `User` model + factory dari Task 4–5; envelope dari Task 3.
- Produces:
  - `Tests\TestCase` memakai trait `Illuminate\Foundation\Testing\DatabaseTransactions` → tiap test rollback otomatis, tidak menghapus data dev.
  - `Tests\Feature\Auth\AuthenticationTest` — login sukses/gagal, user tidak aktif ditolak, session ada setelah login, logout memutus session, `/me` butuh auth.
  - `Tests\Feature\Auth\AuthorizationTest` — Karyawan ke endpoint Admin dapat 403 (bukan 401), Admin boleh.

- [ ] **Step 1: Update `TestCase`**

Ganti seluruh isi `backend/tests/TestCase.php` dengan:

```php
<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use DatabaseTransactions;

    /**
     * Creates the application.
     */
    public function createApplication(): \Illuminate\Contracts\Foundation\Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        return $app;
    }
}
```

- [ ] **Step 2: Set `phpunit.xml` ke database dev**

Edit `backend/phpunit.xml` — ganti baris:

```xml
        <env name="DB_CONNECTION" value="sqlite"/>
        <env name="DB_DATABASE" value=":memory:"/>
```

menjadi:

```xml
        <env name="DB_CONNECTION" value="mysql"/>
        <env name="DB_HOST" value="127.0.0.1"/>
        <env name="DB_PORT" value="3306"/>
        <env name="DB_DATABASE" value="cinnamon_v2"/>
        <env name="DB_USERNAME" value="root"/>
        <env name="DB_PASSWORD" value=""/>
```

- [ ] **Step 3: Tulis `AuthenticationTest`**

Buat `backend/tests/Feature/Auth/AuthenticationTest.php`:

```php
<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    public function test_admin_can_login_and_receive_user_profile(): void
    {
        $admin = User::factory()->admin()->create([
            'email' => 'admin@example.test',
            'password' => 'rahasia-kuat',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@example.test',
            'password' => 'rahasia-kuat',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $admin->id)
            ->assertJsonPath('data.role', 'admin')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.email', 'admin@example.test')
            ->assertJsonStructure(['data' => ['id', 'name', 'email', 'role', 'is_active', 'last_login_at'], 'meta' => ['request_id']]);

        $this->assertArrayNotHasKey('password', $response->json('data'));
    }

    public function test_login_with_wrong_password_returns_401_unauthenticated(): void
    {
        User::factory()->admin()->create([
            'email' => 'admin@example.test',
            'password' => 'rahasia-kuat',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@example.test',
            'password' => 'salah',
        ]);

        $response->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_login_with_unknown_email_returns_401_unauthenticated(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'tidak-ada@example.test',
            'password' => 'rahasia-kuat',
        ]);

        $response->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::factory()->admin()->inactive()->create([
            'email' => 'nonaktif@example.test',
            'password' => 'rahasia-kuat',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'nonaktif@example.test',
            'password' => 'rahasia-kuat',
        ]);

        $response->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
        $this->assertGuest();
    }

    public function test_login_records_last_login_at(): void
    {
        $admin = User::factory()->admin()->create([
            'email' => 'admin@example.test',
            'password' => 'rahasia-kuat',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@example.test',
            'password' => 'rahasia-kuat',
        ])->assertStatus(200);

        $this->assertNotNull($admin->fresh()->last_login_at);
    }

    public function test_me_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/me');

        $response->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_authenticated_user_can_fetch_own_profile(): void
    {
        $karyawan = User::factory()->karyawan()->create();

        $response = $this->actingAs($karyawan)->getJson('/api/v1/me');

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $karyawan->id)
            ->assertJsonPath('data.role', 'karyawan');
    }

    public function test_logout_ends_the_session(): void
    {
        $karyawan = User::factory()->karyawan()->create();

        $this->actingAs($karyawan)
            ->postJson('/api/v1/auth/logout')
            ->assertStatus(200)
            ->assertJsonPath('data', null);

        $this->assertGuest();
    }
}
```

- [ ] **Step 4: Tulis `AuthorizationTest`**

Buat `backend/tests/Feature/Auth/AuthorizationTest.php`:

```php
<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    public function test_karyawan_gets_403_on_admin_only_endpoint(): void
    {
        $karyawan = User::factory()->karyawan()->create();

        $response = $this->actingAs($karyawan)->getJson('/api/v1/users');

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN')
            ->assertJsonStructure(['error' => ['code', 'message', 'fields', 'details'], 'meta' => ['request_id']]);
    }

    public function test_admin_can_list_users(): void
    {
        $admin = User::factory()->admin()->create();
        $karyawan = User::factory()->karyawan()->create();

        $response = $this->actingAs($admin)->getJson('/api/v1/users');

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'meta' => ['request_id', 'current_page', 'per_page', 'last_page', 'total']]);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($karyawan->id));
    }

    public function test_guest_gets_401_not_403_on_admin_only_endpoint(): void
    {
        $response = $this->getJson('/api/v1/users');

        $response->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }
}
```

- [ ] **Step 5: Jalankan test untuk memastikan gagal**

Run:
```bash
cd /d/DEV/0.AI/Cinnamon2/backend && php artisan test --filter="AuthenticationTest|AuthorizationTest"
```
Expected: FAIL — `POST /api/v1/auth/login` dan `GET /api/v1/users` belum ada.

- [ ] **Step 6: Implementasikan endpoint auth dan `/users`**

Ganti seluruh isi `backend/routes/api.php` dengan:

```php
<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\UserController;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => ApiResponse::success(['status' => 'ok']))
    ->name('health');

Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1')
    ->name('auth.login');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::get('/me', [AuthController::class, 'me'])->name('me.show');
    Route::patch('/me', [AuthController::class, 'updateProfile'])->name('me.update');
    Route::post('/me/password', [AuthController::class, 'updatePassword'])->name('me.password');

    Route::get('/users', [UserController::class, 'index'])->name('users.index');
});
```

Buat `backend/app/Http/Controllers/Api/V1/AuthController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\UpdatePasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->credentials();
        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! $user->is_active || ! Hash::check($credentials['password'], $user->password)) {
            return ApiResponse::error(
                'UNAUTHENTICATED',
                'Email atau password salah.',
                ['email' => ['Email atau password salah.']],
                [],
                401,
            );
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();

        return ApiResponse::success(new UserResource($user));
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return ApiResponse::success(null);
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success(new UserResource($request->user()));
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
        ]);

        $request->user()->fill($validated)->save();

        return ApiResponse::success(new UserResource($request->user()->fresh()));
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! Hash::check($request->validated()['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Password saat ini salah.'],
            ]);
        }

        $user->forceFill(['password' => $request->validated()['password']])->save();

        return ApiResponse::success(null);
    }
}
```

Catatan: kredensial salah, email tidak terdaftar, dan user non-aktif semuanya menjawab `UNAUTHENTICATED` (401) dengan pesan yang sama — sengaja, supaya tidak membocorkan apakah sebuah email terdaftar. Pesan tetap masuk ke `error.fields.email` supaya frontend bisa menampilkannya di bawah field email, bukan hanya sebagai banner.

Buat `backend/app/Http/Requests/Auth/LoginRequest.php`:

```php
<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Kredensial tanpa field remember.
     *
     * @return array{email: string, password: string}
     */
    public function credentials(): array
    {
        return [
            'email' => $this->string('email')->toString(),
            'password' => $this->string('password')->toString(),
        ];
    }
}
```

Buat `backend/app/Http/Requests/Auth/UpdatePasswordRequest.php`:

```php
<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class UpdatePasswordRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ];
    }
}
```

Buat `backend/app/Http/Controllers/Api/V1/UserController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $perPage = max(1, min(100, (int) $request->integer('per_page', 20)));

        $users = User::query()->orderBy('name')->paginate($perPage);

        return response()->json([
            'data' => UserResource::collection($users->items()),
            'meta' => [
                'request_id' => \App\Support\RequestId::current(),
                'current_page' => $users->currentPage(),
                'per_page' => $users->perPage(),
                'last_page' => $users->lastPage(),
                'total' => $users->total(),
            ],
        ]);
    }
}
```

Catatan: `UserController::index` memakai `response()->json()` langsung (bukan `ApiResponse::success()`) karena kontrak pagination API.md §2.1 meminta `meta` berisi `current_page`, `per_page`, `last_page`, dan `total` — field pagination itu tidak ada di `ApiResponse::success()`. Import `ApiResponse` tidak lagi dipakai di controller ini, jadi hapus baris `use App\Support\ApiResponse;`.

Ganti seluruh isi `backend/app/Http/Resources/UserResource.php` dengan:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'is_active' => $this->is_active,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 7: Jalankan test untuk memastikan lulus**

Run:
```bash
cd /d/DEV/0.AI/Cinnamon2/backend && php artisan test --filter="AuthenticationTest|AuthorizationTest"
```
Expected: PASS — 11 test hijau.

- [ ] **Step 8: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add backend/app/Http backend/routes/api.php backend/tests && git commit -m "backend: add auth endpoints, user listing, and authorization tests"
```

---

## Task 7: Backend — check penuh dan HEALTH check

**Files:** tidak ada file baru; verifikasi saja.

- [ ] **Step 1: Jalankan seluruh test backend**

Run:
```bash
cd /d/DEV/0.AI/Cinnamon2/backend && php artisan test
```
Expected: semua test hijau, tidak ada test yang terskip karena error.

- [ ] **Step 2: Pastikan data dev tidak rusak oleh test**

Run:
```bash
"/c/xampp/mysql/bin/mysql.exe" -u root -e "SELECT email, role FROM cinnamon_v2.users;"
```
Expected: tepat 2 baris (`admin@cinnamon.test`, `karyawan@cinnamon.test`). Baris tambahan dari test harus sudah hilang (rollback).

- [ ] **Step 3: Jalankan formatter dan linter backend**

Run:
```bash
cd /d/DEV/0.AI/Cinnamon2/backend && ./vendor/bin/pint --test
```
Expected: exit 0 tanpa offense. Jika ada offense, jalankan `./vendor/bin/pint` lalu ulangi test.

- [ ] **Step 4: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add -A backend && git commit -m "backend: apply pint formatting" || echo "no formatting changes"
```

---

## Task 8: Review Focus — auth & authorization edge cases

**Files:**
- Create: `backend/tests/Feature/Auth/AuthEdgeCaseTest.php`

**Interfaces:**
- Consumes: seluruh endpoint dari Task 6.
- Produces: test yang mengunci 3 kondisi Review Focus #1, #2, #3.

- [ ] **Step 1: Tulis test edge case**

Buat `backend/tests/Feature/Auth/AuthEdgeCaseTest.php`:

```php
<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuthEdgeCaseTest extends TestCase
{
    public function test_karyawan_session_hitting_admin_endpoint_returns_403_not_401(): void
    {
        $karyawan = User::factory()->karyawan()->create();

        $response = $this->actingAs($karyawan)->getJson('/api/v1/users');

        $response->assertStatus(403);
        $this->assertNotSame('UNAUTHENTICATED', $response->json('error.code'));
        $this->assertSame('FORBIDDEN', $response->json('error.code'));
    }

    public function test_inactive_user_cannot_login_and_no_session_row_is_created(): void
    {
        $before = DB::table('sessions')->count();

        User::factory()->karyawan()->inactive()->create([
            'email' => 'nonaktif@example.test',
            'password' => 'rahasia-kuat',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'nonaktif@example.test',
            'password' => 'rahasia-kuat',
        ]);

        $response->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
        $this->assertGuest();
        $this->assertSame($before, DB::table('sessions')->count());
    }

    public function test_protected_endpoint_without_session_returns_401_with_request_id(): void
    {
        $response = $this->getJson('/api/v1/me');

        $response->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED')
            ->assertJsonStructure(['error' => ['code', 'message', 'fields', 'details'], 'meta' => ['request_id']]);

        $this->assertNotEmpty($response->json('meta.request_id'));
    }

    public function test_error_response_never_leaks_internals(): void
    {
        $response = $this->getJson('/api/v1/tidak-ada');

        $body = $response->getContent();

        $this->assertStringNotContainsString('vendor/laravel', $body);
        $this->assertStringNotContainsString('APP_KEY', $body);
        $this->assertStringNotContainsString('mysql://', $body);
        $this->assertStringNotContainsString('SQLSTATE', $body);
    }

    public function test_response_carries_x_request_id_header_matching_meta(): void
    {
        $response = $this->getJson('/api/v1/health');

        $this->assertSame($response->json('meta.request_id'), $response->headers->get('X-Request-Id'));
    }
}
```

- [ ] **Step 2: Jalankan test**

Run:
```bash
cd /d/DEV/0.AI/Cinnamon2/backend && php artisan test --filter=AuthEdgeCaseTest
```
Expected: PASS — 5 test hijau. Kalau `test_inactive_user_cannot_login_and_no_session_row_is_created` gagal dengan selisih jumlah baris `sessions`, itu berarti `Auth::login()` sempat berjalan sebelum pengecekan `is_active` — perbaiki urutan di `AuthController::login` agar pengecekan `is_active` terjadi sebelum `Auth::login`.

- [ ] **Step 3: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add backend/tests/Feature/Auth/AuthEdgeCaseTest.php && git commit -m "backend: cover auth and authorization edge cases"
```

---

## Task 9: Frontend — dependency, token Design.md, dan API client

**Files:**
- Modify: `frontend/package.json`
- Create: `frontend/src/styles/tokens.css`
- Modify: `frontend/src/index.css`
- Create: `frontend/src/lib/formatters/number.ts`
- Create: `frontend/src/lib/formatters/number.test.ts`
- Create: `frontend/src/lib/api/errors.ts`
- Modify: `frontend/src/lib/api/client.ts`
- Modify: `frontend/src/lib/api/types.ts`
- Modify: `frontend/vite.config.ts`
- Modify: `frontend/index.html`
- Create: `frontend/src/test/setup.ts`

**Interfaces:**
- Consumes: kontrak `/api/v1/auth/login`, `/api/v1/me`, envelope dari Task 3.
- Produces:
  - `frontend/src/lib/api/types.ts` — `User`, `Meta`, `Envelope<T>`, `ApiError`, `PaginatedEnvelope<T>`.
  - `frontend/src/lib/api/errors.ts` — `ApiError` type, `isApiError(payload)`, `parseApiError(payload, status)`, `ApiErrorCode` union.
  - `frontend/src/lib/api/client.ts` — axios instance `apiClient` (baseURL `import.meta.env.VITE_API_URL ?? 'http://localhost:8000'`, `withCredentials: true`, `xsrfCookieName: 'XSRF-TOKEN'`, `xsrfHeaderName: 'X-XSRF-TOKEN'`), plus `fetchCsrf()`, `login()`, `logout()`, `fetchMe()`, `updateProfile()`, `updatePassword()`.
  - `frontend/src/lib/formatters/number.ts` — `formatMoney(value: string | number): string` (format `Rp` locale Indonesia, 0 desimal untuk tampilan), `formatQuantity(value: string | number): string` (3 desimal, tanpa pembulatan excess).

- [ ] **Step 1: Install dependency frontend**

Run di `frontend/`:
```bash
npm install react-router-dom@7 @tanstack/react-query@5 axios@1 zod@4 react-hook-form@7 @hookform/resolvers@6
npm install -D vitest@5 @vitest/ui@5 @testing-library/react@16 @testing-library/jest-dom@7 @testing-library/user-event@14 jsdom@30 prettier@3
```
Expected: `package.json` memuat semua dependency di atas; `npm install` selesai tanpa error peer dependency.

- [ ] **Step 2: Tambahkan script test dan format**

Edit `frontend/package.json` — bagian `scripts` menjadi:

```json
  "scripts": {
    "dev": "vite",
    "build": "tsc -b && vite build",
    "lint": "eslint .",
    "format": "prettier --write \"src/**/*.{ts,tsx,css}\" \"*.{ts,json,html}\"",
    "format:check": "prettier --check \"src/**/*.{ts,tsx,css}\" \"*.{ts,json,html}\"",
    "preview": "vite preview",
    "test": "vitest run",
    "test:watch": "vitest",
    "typecheck": "tsc -b"
  },
```

- [ ] **Step 3: Buat token Design.md**

Buat `frontend/src/styles/tokens.css` dengan isi persis:

```css
:root {
  /* Warna — Design.md §2 */
  --brand-700: #6b341f;
  --brand-600: #8b4a2f;
  --brand-100: #f4e4d8;
  --accent-600: #c88719;
  --success-700: #246344;
  --danger-700: #b42318;
  --ink-900: #1f2933;
  --ink-600: #52606d;
  --line: #d9e2ec;
  --surface: #ffffff;
  --canvas: #f7f5f2;

  /* Spasi — basis 4px */
  --space-1: 4px;
  --space-2: 8px;
  --space-3: 12px;
  --space-4: 16px;
  --space-6: 24px;
  --space-8: 32px;

  /* Bentuk */
  --radius-card: 10px;
  --radius-input: 8px;
  --radius-button: 8px;

  /* Tipografi */
  --font-sans: system-ui, 'Segoe UI', Roboto, sans-serif;
  --font-size-body: 14px;
  --line-height-body: 1.5;
  --font-size-page-title: 24px;
  --font-size-section-title: 18px;
  --font-size-table: 13px;

  /* Transisi */
  --transition-fast: 150ms ease;

  /* Fokus — kontras ≥ 3:1 */
  --focus-ring: 0 0 0 3px var(--brand-100);
}
```

- [ ] **Step 4: Ganti `frontend/src/index.css` agar memuat token dan reset**

Ganti seluruh isi `frontend/src/index.css` dengan:

```css
@import './styles/tokens.css';

*,
*::before,
*::after {
  box-sizing: border-box;
}

body {
  margin: 0;
  font-family: var(--font-sans);
  font-size: var(--font-size-body);
  line-height: var(--line-height-body);
  color: var(--ink-900);
  background: var(--canvas);
  -webkit-font-smoothing: antialiased;
}

h1,
h2,
h3 {
  margin: 0;
  color: var(--ink-900);
}

h1 {
  font-size: var(--font-size-page-title);
  font-weight: 700;
}

h2 {
  font-size: var(--font-size-section-title);
  font-weight: 650;
}

button {
  font: inherit;
  cursor: pointer;
}

:focus-visible {
  outline: 2px solid var(--brand-600);
  outline-offset: 2px;
}
```

- [ ] **Step 5: Buat formatter angka tanpa `intl`**

Buat `frontend/src/lib/formatters/number.ts`:

```typescript
export function formatQuantity(value: string | number): string {
  const numeric = typeof value === 'number' ? value : Number.parseFloat(value)

  if (!Number.isFinite(numeric)) {
    return '0,000'
  }

  return numeric.toFixed(3).replace('.', ',')
}

export function formatMoney(value: string | number): string {
  const numeric = typeof value === 'number' ? value : Number.parseFloat(value)

  if (!Number.isFinite(numeric)) {
    return 'Rp 0'
  }

  const rounded = Math.round(numeric)
  const grouped = rounded.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.')

  return `Rp ${grouped}`
}
```

- [ ] **Step 6: Tulis test formatter lalu jalankan**

Buat `frontend/src/lib/formatters/number.test.ts`:

```typescript
import { describe, expect, it } from 'vitest'
import { formatMoney, formatQuantity } from './number'

describe('formatQuantity', () => {
  it('memformat 3 desimal dengan koma sebagai pemisah desimal', () => {
    expect(formatQuantity('24.500')).toBe('24,500')
    expect(formatQuantity(7)).toBe('7,000')
  })

  it('mengembalikan nilai aman untuk input tidak valid', () => {
    expect(formatQuantity('bukan-angka')).toBe('0,000')
  })
})

describe('formatMoney', () => {
  it('memformat rupiah dengan pemisah ribuan titik', () => {
    expect(formatMoney('42000.00')).toBe('Rp 42.000')
    expect(formatMoney(1500000)).toBe('Rp 1.500.000')
  })

  it('membulatkan ke rupiah terdekat tanpa desimal', () => {
    expect(formatMoney('42000.60')).toBe('Rp 42.001')
  })

  it('mengembalikan nilai aman untuk input tidak valid', () => {
    expect(formatMoney('bukan-angka')).toBe('Rp 0')
  })
})
```

Run:
```bash
cd /d/DEV/0.AI/Cinnamon2/frontend && npx vitest run src/lib/formatters/number.test.ts
```
Expected: FAIL — `vitest` belum dikonfigurasi (belum ada `test` block di `vite.config.ts`).

- [ ] **Step 7: Konfigurasi Vitest di `vite.config.ts`**

Ganti seluruh isi `frontend/vite.config.ts` dengan:

```typescript
/// <reference types="vitest/config" />
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

export default defineConfig({
  plugins: [react()],
  server: {
    port: 5173,
    proxy: {
      '/api': 'http://localhost:8000',
      '/sanctum': 'http://localhost:8000',
    },
  },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./src/test/setup.ts'],
    css: false,
  },
})
```

Buat `frontend/src/test/setup.ts`:

```typescript
import '@testing-library/jest-dom/vitest'
```

- [ ] **Step 8: Jalankan test formatter untuk memastikan lulus**

Run:
```bash
cd /d/DEV/0.AI/Cinnamon2/frontend && npx vitest run src/lib/formatters/number.test.ts
```
Expected: PASS — 5 test hijau.

- [ ] **Step 9: Buat modul error API**

Buat `frontend/src/lib/api/errors.ts`:

```typescript
export const API_ERROR_CODES = [
  'VALIDATION_ERROR',
  'UNAUTHENTICATED',
  'FORBIDDEN',
  'NOT_FOUND',
  'BAD_REQUEST',
  'RATE_LIMITED',
  'SERVER_ERROR',
] as const

export type ApiErrorCode = (typeof API_ERROR_CODES)[number]

export interface ApiError {
  code: ApiErrorCode
  message: string
  fields: Record<string, string[]>
  details: Record<string, unknown>
  requestId: string | null
  status: number
}

export function isApiError(payload: unknown): payload is { error: unknown; meta?: unknown } {
  return typeof payload === 'object' && payload !== null && 'error' in payload
}

export function parseApiError(payload: unknown, status: number): ApiError {
  if (isApiError(payload) && typeof payload.error === 'object' && payload.error !== null) {
    const error = payload.error as Partial<{
      code: string
      message: string
      fields: Record<string, string[]>
      details: Record<string, unknown>
    }>
    const meta = payload.meta as { request_id?: string } | undefined

    return {
      code: (API_ERROR_CODES as readonly string[]).includes(error.code ?? '')
        ? (error.code as ApiErrorCode)
        : 'SERVER_ERROR',
      message: error.message ?? 'Terjadi kesalahan. Silakan coba lagi.',
      fields: error.fields ?? {},
      details: error.details ?? {},
      requestId: meta?.request_id ?? null,
      status,
    }
  }

  return {
    code: 'SERVER_ERROR',
    message: 'Terjadi kesalahan. Silakan coba lagi.',
    fields: {},
    details: {},
    requestId: null,
    status,
  }
}

export function isSessionExpired(error: ApiError): boolean {
  return error.status === 419 || error.code === 'UNAUTHENTICATED'
}
```

- [ ] **Step 10: Update `types.ts`**

Ganti seluruh isi `frontend/src/lib/api/types.ts` dengan:

```typescript
export type UserRole = 'admin' | 'karyawan'

export interface User {
  id: string
  name: string
  email: string
  role: UserRole
  is_active: boolean
  last_login_at: string | null
  created_at?: string | null
}

export interface Meta {
  request_id: string
}

export interface Envelope<T> {
  data: T
  meta: Meta
}

export interface PaginatedMeta extends Meta {
  current_page: number
  per_page: number
  last_page: number
  total: number
}

export interface PaginatedEnvelope<T> {
  data: T[]
  meta: PaginatedMeta
}
```

- [ ] **Step 11: Update API client dengan dukungan XSRF**

Ganti seluruh isi `frontend/src/lib/api/client.ts` dengan:

```typescript
import axios, { AxiosError, AxiosInstance } from 'axios'
import { parseApiError, type ApiError } from './errors'
import type { Envelope, User } from './types'

const apiClient: AxiosInstance = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? 'http://localhost:8000',
  withCredentials: true,
  xsrfCookieName: 'XSRF-TOKEN',
  xsrfHeaderName: 'X-XSRF-TOKEN',
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
})

export async function fetchCsrf(): Promise<void> {
  await apiClient.get('/sanctum/csrf-cookie')
}

export async function login(
  email: string,
  password: string,
  remember = false,
): Promise<{ data: User } | { error: ApiError }> {
  await fetchCsrf()

  try {
    const response = await apiClient.post<Envelope<User>>('/api/v1/auth/login', {
      email,
      password,
      remember,
    })

    return { data: response.data.data }
  } catch (error) {
    return { error: toApiError(error) }
  }
}

export async function logout(): Promise<void> {
  await apiClient.post('/api/v1/auth/logout')
}

export async function fetchMe(): Promise<User> {
  const response = await apiClient.get<Envelope<User>>('/api/v1/me')

  return response.data.data
}

export async function updateProfile(payload: { name: string }): Promise<User> {
  const response = await apiClient.patch<Envelope<User>>('/api/v1/me', payload)

  return response.data.data
}

export async function updatePassword(payload: {
  current_password: string
  password: string
  password_confirmation: string
}): Promise<void> {
  await apiClient.post('/api/v1/me/password', payload)
}

export function toApiError(error: unknown): ApiError {
  if (axios.isAxiosError(error)) {
    const axiosError = error as AxiosError
    const status = axiosError.response?.status ?? 0

    if (status === 419) {
      return {
        code: 'UNAUTHENTICATED',
        message: 'Sesi Anda telah berakhir. Silakan muat ulang halaman.',
        fields: {},
        details: {},
        requestId: null,
        status: 419,
      }
    }

    return parseApiError(axiosError.response?.data, status)
  }

  return {
    code: 'SERVER_ERROR',
    message: 'Terjadi kesalahan jaringan. Periksa koneksi Anda.',
    fields: {},
    details: {},
    requestId: null,
    status: 0,
  }
}

export default apiClient
```

- [ ] **Step 12: Update `index.html`**

Ganti `<html lang="en">` menjadi `<html lang="id">` dan `<title>frontend</title>` menjadi `<title>CINNAMON</title>`.

- [ ] **Step 13: Jalankan typecheck dan build**

Run:
```bash
cd /d/DEV/0.AI/Cinnamon2/frontend && npx tsc -b && npm run build
```
Expected: `tsc -b` tanpa error; `vite build` menghasilkan `dist/`. Jika `import.meta.env.VITE_API_URL` dik complains, tambahkan `"types": ["vite/client", "vitest/globals"]` pada `tsconfig.app.json` bagian `compilerOptions.types`.

- [ ] **Step 14: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add frontend && git commit -m "frontend: add design tokens, api client with xsrf, and number formatters"
```

---

## Task 10: Frontend — provider, auth context, dan router

**Files:**
- Create: `frontend/src/app/providers.tsx`
- Create: `frontend/src/features/auth/auth-context.ts`
- Create: `frontend/src/features/auth/AuthProvider.tsx`
- Create: `frontend/src/features/auth/AuthGuard.tsx`
- Modify: `frontend/src/app/router.tsx`
- Modify: `frontend/src/main.tsx`
- Delete: `frontend/src/App.tsx`, `frontend/src/App.css`, `frontend/src/assets/hero.png`, `frontend/src/assets/react.svg`, `frontend/src/assets/vite.svg`

**Interfaces:**
- Consumes: `apiClient`, `login`, `logout`, `fetchMe`, `updateProfile`, `updatePassword` dari Task 9; `User` type.
- Produces:
  - `frontend/src/features/auth/auth-context.ts` — export `AuthContext` (React context) dan hook `useAuth(): { user: User | null; isLoading: boolean; login(email, password, remember): Promise<ApiError | null>; logout(): Promise<void>; updateProfile(name): Promise<ApiError | null>; updatePassword(payload): Promise<ApiError | null>; refresh(): Promise<void> }`. File ini tidak mengekspor komponen React.
  - `frontend/src/features/auth/AuthProvider.tsx` — export `AuthProvider({ children }: { children: React.ReactNode })` saja; provider sesi yang mengisi `AuthContext`.
  - `frontend/src/features/auth/AuthGuard.tsx` — export `AuthGuard({ children }: { children: React.ReactNode })`; redirect ke `/login` bila belum auth; tampilkan skeleton saat session dicek.
  - `frontend/src/app/providers.tsx` — export `AppProviders({ children })` yang membungkus `QueryClientProvider` + `AuthProvider`.
  - `frontend/src/app/router.tsx` — export `router`.

- [ ] **Step 1: Buat `auth-context.ts` (context + hook, tanpa komponen)**

Buat `frontend/src/features/auth/auth-context.ts`:

```typescript
import { createContext, useContext } from 'react'
import type { ApiError } from '../../lib/api/errors'
import type { User } from '../../lib/api/types'

export interface AuthContextValue {
  user: User | null
  isLoading: boolean
  login: (email: string, password: string, remember?: boolean) => Promise<ApiError | null>
  logout: () => Promise<void>
  updateProfile: (name: string) => Promise<ApiError | null>
  updatePassword: (payload: {
    current_password: string
    password: string
    password_confirmation: string
  }) => Promise<ApiError | null>
  refresh: () => Promise<void>
}

export const AuthContext = createContext<AuthContextValue | null>(null)

export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext)

  if (!context) {
    throw new Error('useAuth harus dipakai di dalam AuthProvider')
  }

  return context
}
```

File ini sengaja tidak mengekspor komponen React — rule `react-refresh/only-export-components` menolak file yang mencampur komponen dan hook.

- [ ] **Step 2: Buat `AuthProvider`**

Buat `frontend/src/features/auth/AuthProvider.tsx`:

```typescript
import { useCallback, useMemo, type ReactNode } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import {
  fetchMe,
  login as loginRequest,
  logout as logoutRequest,
  toApiError,
  updatePassword as updatePasswordRequest,
  updateProfile as updateProfileRequest,
} from '../../lib/api/client'
import { AuthContext, type AuthContextValue } from './auth-context'

const ME_QUERY_KEY = ['me'] as const

export function AuthProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient()

  const meQuery = useQuery({
    queryKey: ME_QUERY_KEY,
    queryFn: fetchMe,
    retry: false,
    staleTime: 1000 * 60,
  })

  const user = meQuery.data ?? null
  const isLoading = meQuery.isLoading

  const refresh = useCallback(async () => {
    await meQuery.refetch()
  }, [meQuery])

  const login = useCallback(
    async (email: string, password: string, remember = false) => {
      const result = await loginRequest(email, password, remember)

      if ('error' in result) {
        return result.error
      }

      queryClient.setQueryData(ME_QUERY_KEY, result.data)

      return null
    },
    [queryClient],
  )

  const logout = useCallback(async () => {
    await logoutRequest()
    queryClient.setQueryData(ME_QUERY_KEY, null)
  }, [queryClient])

  const updateProfile = useCallback(
    async (name: string) => {
      try {
        const updated = await updateProfileRequest({ name })
        queryClient.setQueryData(ME_QUERY_KEY, updated)
        return null
      } catch (error) {
        return toApiError(error)
      }
    },
    [queryClient],
  )

  const updatePassword = useCallback(
    async (payload: {
      current_password: string
      password: string
      password_confirmation: string
    }) => {
      try {
        await updatePasswordRequest(payload)
        return null
      } catch (error) {
        return toApiError(error)
      }
    },
    [],
  )

  const value = useMemo<AuthContextValue>(
    () => ({ user, isLoading, login, logout, updateProfile, updatePassword, refresh }),
    [user, isLoading, login, logout, updateProfile, updatePassword, refresh],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
```

Catatan: session dibaca lewat `useQuery` (bukan `queryClient.getQueryData()`) supaya provider benar-benar subscribe ke cache dan re-render saat data berubah. `retry: false` mencegah percobaan login berulang saat session memang tidak ada. `ME_QUERY_KEY` sengaja `const` tanpa `export` — rule `react-refresh/only-export-components` menolak file yang mengekspor nilai biasa bersama komponen.

- [ ] **Step 3: Buat `AppProviders`**

Buat `frontend/src/app/providers.tsx`:

```typescript
import { useState, type ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { AuthProvider } from '../features/auth/AuthProvider'

export function AppProviders({ children }: { children: ReactNode }) {
  const [queryClient] = useState(
    () =>
      new QueryClient({
        defaultOptions: {
          queries: {
            retry: 1,
            staleTime: 1000 * 60,
            refetchOnWindowFocus: false,
          },
        },
      }),
  )

  return (
    <QueryClientProvider client={queryClient}>
      <AuthProvider>{children}</AuthProvider>
    </QueryClientProvider>
  )
}
```

- [ ] **Step 4: Buat `AuthGuard`**

Buat `frontend/src/features/auth/AuthGuard.tsx`:

```typescript
import type { ReactNode } from 'react'
import { Navigate, useLocation } from 'react-router-dom'
import { useAuth } from './auth-context'

export function AuthGuard({ children }: { children: ReactNode }) {
  const { user, isLoading } = useAuth()
  const location = useLocation()

  if (isLoading) {
    return (
      <div role="status" aria-live="polite" className="page-loading">
        Memuat sesi…
      </div>
    )
  }

  if (!user) {
    return <Navigate to="/login" replace state={{ from: location.pathname }} />
  }

  return <>{children}</>
}
```

Catatan: `AuthProvider` sudah menjalankan query `['me']` saat mount, jadi `AuthGuard` tidak perlu memanggil API sendiri — cukup membaca `user` dan `isLoading` dari context.

- [ ] **Step 5: Buat router**

Ganti seluruh isi `frontend/src/app/router.tsx` dengan:

```typescript
import { createBrowserRouter } from 'react-router-dom'
import { AppShell } from './app-shell'
import { AuthGuard } from '../features/auth/AuthGuard'
import { LoginPage } from '../features/auth/LoginPage'
import { ProfilePage } from '../features/auth/ProfilePage'
import { DashboardPage } from '../features/dashboard/DashboardPage'
import { NotFoundPage } from '../features/misc/NotFoundPage'

export const router = createBrowserRouter([
  {
    path: '/login',
    element: <LoginPage />,
  },
  {
    path: '/',
    element: <AppShell />,
    children: [
      {
        index: true,
        element: (
          <AuthGuard>
            <DashboardPage />
          </AuthGuard>
        ),
      },
      {
        path: 'profil',
        element: (
          <AuthGuard>
            <ProfilePage />
          </AuthGuard>
        ),
      },
      {
        path: '*',
        element: <NotFoundPage />,
      },
    ],
  },
])
```

- [ ] **Step 6: Update `main.tsx`**

Ganti seluruh isi `frontend/src/main.tsx` dengan:

```typescript
import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { RouterProvider } from 'react-router-dom'
import { router } from './app/router'
import { AppProviders } from './app/providers'
import './index.css'

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <AppProviders>
      <RouterProvider router={router} />
    </AppProviders>
  </StrictMode>,
)
```

- [ ] **Step 7: Hapus file template Vite yang tidak dipakai**

Run:
```bash
cd /d/DEV/0.AI/Cinnamon2/frontend && rm -f src/App.tsx src/App.css src/assets/hero.png src/assets/react.svg src/assets/vite.svg
```
Expected: file terhapus; `src/assets/` menjadi kosong dan bisa dihapus (`rmdir src/assets 2>/dev/null || true`).

- [ ] **Step 8: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add -A frontend && git commit -m "frontend: add auth provider, guard, and protected router"
```

---

## Task 11: Frontend — app shell, sidebar, topbar, halaman 404

**Files:**
- Create: `frontend/src/app/app-shell.tsx`
- Create: `frontend/src/app/app-shell.css`
- Create: `frontend/src/components/layout/Sidebar.tsx`
- Create: `frontend/src/components/layout/Topbar.tsx`
- Create: `frontend/src/components/feedback/PageState.tsx`
- Create: `frontend/src/features/dashboard/DashboardPage.tsx`
- Create: `frontend/src/features/misc/NotFoundPage.tsx`

**Interfaces:**
- Consumes: `useAuth()` dari Task 10; token CSS dari Task 9.
- Produces:
  - `AppShell` — layout sidebar + topbar + `<Outlet />`.
  - `Sidebar` — daftar modul dari `Docs/Design.md` §3, difilter berdasarkan role.
  - `Topbar` — judul halaman, nama user, tombol keluar.
  - `PageState` — komponen `LoadingState`, `EmptyState`, `ErrorState` dengan `role="status"` / `role="alert"`.
  - `DashboardPage` — placeholder role-aware (Admin melihat kartu ringkasan, Karyawan melihat kartu tugas masuk).
  - `NotFoundPage` — 404 Bahasa Indonesia dengan link kembali ke beranda.

- [ ] **Step 1: Buat `Sidebar`**

Buat `frontend/src/components/layout/Sidebar.tsx`:

```typescript
import { NavLink } from 'react-router-dom'
import { useAuth } from '../../features/auth/auth-context'

interface NavItem {
  label: string
  to: string
  adminOnly?: boolean
}

const NAV_ITEMS: NavItem[] = [
  { label: 'Dashboard', to: '/' },
  { label: 'Barang Masuk', to: '/penerimaan' },
  { label: 'Stok', to: '/stok' },
  { label: 'Laporan', to: '/laporan', adminOnly: true },
  { label: 'Proyek', to: '/proyek', adminOnly: true },
]

export function Sidebar() {
  const { user } = useAuth()
  const items = NAV_ITEMS.filter((item) => !item.adminOnly || user?.role === 'admin')

  return (
    <nav className="sidebar" aria-label="Navigasi utama">
      <div className="sidebar__brand">
        <span className="sidebar__mark" aria-hidden="true">
          CINNAMON
        </span>
      </div>
      <ul className="sidebar__list">
        {items.map((item) => (
          <li key={item.to}>
            <NavLink
              to={item.to}
              end={item.to === '/'}
              className={({ isActive }) =>
                isActive ? 'sidebar__link sidebar__link--active' : 'sidebar__link'
              }
            >
              {item.label}
            </NavLink>
          </li>
        ))}
      </ul>
    </nav>
  )
}
```

Catatan: rute `/penerimaan`, `/stok`, `/laporan`, `/proyek` sengaja belum ada isinya di Phase 1 — router akan merender `NotFoundPage` sampai Phase 2/3. Item navigasi tetap ditampilkan agar struktur menu Design.md §3 terlihat utuh.

- [ ] **Step 2: Buat `Topbar`**

Buat `frontend/src/components/layout/Topbar.tsx`:

```typescript
import { useNavigate } from 'react-router-dom'
import { useAuth } from '../../features/auth/auth-context'

export function Topbar() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()

  async function handleLogout() {
    await logout()
    navigate('/login', { replace: true })
  }

  return (
    <header className="topbar">
      <div className="topbar__context">
        <span className="topbar__date">
          {new Intl.DateTimeFormat('id-ID', { dateStyle: 'long' }).format(new Date())}
        </span>
      </div>
      <div className="topbar__user">
        <span className="topbar__name">{user?.name ?? 'Pengguna'}</span>
        <span className="topbar__role">{user?.role === 'admin' ? 'Admin' : 'Karyawan'}</span>
        <button type="button" className="topbar__logout" onClick={handleLogout}>
          Keluar
        </button>
      </div>
    </header>
  )
}
```

- [ ] **Step 3: Buat `PageState`**

Buat `frontend/src/components/feedback/PageState.tsx`:

```typescript
interface LoadingStateProps {
  label?: string
  rows?: number
}

export function LoadingState({ label = 'Memuat data…', rows = 3 }: LoadingStateProps) {
  return (
    <div className="page-state" role="status" aria-live="polite">
      <span className="page-state__label">{label}</span>
      <div className="page-state__skeleton" aria-hidden="true">
        {Array.from({ length: rows }, (_, index) => (
          <span key={index} className="page-state__row" />
        ))}
      </div>
    </div>
  )
}

interface EmptyStateProps {
  title: string
  description: string
  action?: React.ReactNode
}

export function EmptyState({ title, description, action }: EmptyStateProps) {
  return (
    <div className="page-state">
      <h2 className="page-state__title">{title}</h2>
      <p className="page-state__description">{description}</p>
      {action ? <div className="page-state__action">{action}</div> : null}
    </div>
  )
}

interface ErrorStateProps {
  message: string
  requestId?: string | null
  onRetry?: () => void
}

export function ErrorState({ message, requestId, onRetry }: ErrorStateProps) {
  return (
    <div className="page-state page-state--error" role="alert">
      <h2 className="page-state__title">Terjadi kesalahan</h2>
      <p className="page-state__description">{message}</p>
      {requestId ? <p className="page-state__request-id">Kode rujukan: {requestId}</p> : null}
      {onRetry ? (
        <div className="page-state__action">
          <button type="button" onClick={onRetry}>
            Coba lagi
          </button>
        </div>
      ) : null}
    </div>
  )
}
```

- [ ] **Step 4: Buat `AppShell` dan CSS**

Buat `frontend/src/app/app-shell.tsx`:

```typescript
import { Outlet } from 'react-router-dom'
import { Sidebar } from '../components/layout/Sidebar'
import { Topbar } from '../components/layout/Topbar'
import './app-shell.css'

export function AppShell() {
  return (
    <div className="app-shell">
      <Sidebar />
      <div className="app-shell__main">
        <Topbar />
        <main className="app-shell__content">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
```

Buat `frontend/src/app/app-shell.css`:

```css
.app-shell {
  display: grid;
  grid-template-columns: 240px 1fr;
  min-height: 100vh;
}

.app-shell__main {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.app-shell__content {
  flex: 1;
  padding: var(--space-6);
  max-width: 1440px;
  width: 100%;
}

.sidebar {
  background: var(--brand-700);
  color: var(--surface);
  padding: var(--space-4);
}

.sidebar__mark {
  font-weight: 700;
  letter-spacing: 0.08em;
  display: block;
  margin-bottom: var(--space-6);
}

.sidebar__list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: var(--space-1);
}

.sidebar__link {
  display: block;
  padding: var(--space-2) var(--space-3);
  border-radius: var(--radius-button);
  color: var(--brand-100);
  text-decoration: none;
  transition: background var(--transition-fast);
}

.sidebar__link:hover {
  background: var(--brand-600);
}

.sidebar__link--active {
  background: var(--brand-600);
  color: var(--surface);
  font-weight: 600;
}

.topbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-4);
  padding: var(--space-3) var(--space-6);
  background: var(--surface);
  border-bottom: 1px solid var(--line);
}

.topbar__date {
  color: var(--ink-600);
}

.topbar__user {
  display: flex;
  align-items: center;
  gap: var(--space-3);
}

.topbar__name {
  font-weight: 600;
}

.topbar__role {
  color: var(--ink-600);
}

.topbar__logout {
  border: 1px solid var(--line);
  background: var(--surface);
  border-radius: var(--radius-button);
  padding: var(--space-2) var(--space-3);
}

.topbar__logout:hover {
  border-color: var(--brand-600);
  color: var(--brand-700);
}

.page-state {
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: var(--radius-card);
  padding: var(--space-6);
}

.page-state--error {
  border-color: var(--danger-700);
}

.page-state__label {
  color: var(--ink-600);
  display: block;
  margin-bottom: var(--space-3);
}

.page-state__skeleton {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

.page-state__row {
  display: block;
  height: 16px;
  border-radius: var(--radius-input);
  background: var(--canvas);
}

.page-state__title {
  font-size: var(--font-size-section-title);
  margin-bottom: var(--space-2);
}

.page-state__description {
  color: var(--ink-600);
}

.page-state__request-id {
  color: var(--ink-600);
  font-size: 12px;
  margin-top: var(--space-2);
}

.page-state__action {
  margin-top: var(--space-4);
}

.page-loading {
  padding: var(--space-6);
  color: var(--ink-600);
}

@media (max-width: 900px) {
  .app-shell {
    grid-template-columns: 1fr;
  }

  .sidebar {
    display: none;
  }

  .app-shell__content {
    padding: var(--space-4);
  }

  .topbar {
    padding: var(--space-3) var(--space-4);
  }
}
```

- [ ] **Step 5: Buat `DashboardPage`**

Buat `frontend/src/features/dashboard/DashboardPage.tsx`:

```typescript
import { useAuth } from '../auth/auth-context'

export function DashboardPage() {
  const { user } = useAuth()

  if (user?.role === 'admin') {
    return (
      <section>
        <h1>Dashboard</h1>
        <p>Ringkasan stok, penjualan, dan laba untuk toko ini akan tampil di sini.</p>
      </section>
    )
  }

  return (
    <section>
      <h1>Tugas Anda</h1>
      <p>Pencatatan barang masuk dan pengelolaan produk pending akan tampil di sini.</p>
    </section>
  )
}
```

- [ ] **Step 6: Buat `NotFoundPage`**

Buat `frontend/src/features/misc/NotFoundPage.tsx`:

```typescript
import { Link } from 'react-router-dom'

export function NotFoundPage() {
  return (
    <section className="page-state">
      <h1 className="page-state__title">Halaman tidak ditemukan</h1>
      <p className="page-state__description">
        Alamat yang Anda buka tidak tersedia atau sudah dipindahkan.
      </p>
      <p className="page-state__action">
        <Link to="/">Kembali ke Dashboard</Link>
      </p>
    </section>
  )
}
```

- [ ] **Step 7: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add frontend/src && git commit -m "frontend: add app shell, sidebar, topbar, and page state components"
```

---

## Task 12: Frontend — halaman login dan profil

**Files:**
- Modify: `frontend/src/features/auth/LoginPage.tsx`
- Create: `frontend/src/features/auth/LoginPage.css`
- Create: `frontend/src/features/auth/ProfilePage.tsx`
- Create: `frontend/src/features/auth/LoginPage.test.tsx`

**Interfaces:**
- Consumes: `useAuth()` dari Task 10; `AuthProvider` membungkus router.
- Produces:
  - `LoginPage` — form email/password dengan label terlihat, pesan error field terhubung via `aria-describedby`, state loading mencegah double submit, input dipertahankan setelah error.
  - `ProfilePage` — ubah nama dan ubah password, dengan konfirmasi sukses.

- [ ] **Step 1: Tulis test login**

Buat `frontend/src/features/auth/LoginPage.test.tsx`:

```typescript
import { describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { LoginPage } from './LoginPage'
import { AuthProvider } from './AuthProvider'
import { login } from '../../lib/api/client'

vi.mock('../../lib/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../lib/api/client')>()

  return {
    ...actual,
    login: vi.fn(),
  }
})

function renderLogin() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  })

  return render(
    <QueryClientProvider client={queryClient}>
      <AuthProvider>
        <MemoryRouter initialEntries={['/login']}>
          <Routes>
            <Route path="/login" element={<LoginPage />} />
            <Route path="/" element={<h1>Dashboard</h1>} />
          </Routes>
        </MemoryRouter>
      </AuthProvider>
    </QueryClientProvider>,
  )
}

describe('LoginPage', () => {
  it('menampilkan label yang terlihat untuk email dan password', () => {
    renderLogin()

    expect(screen.getByLabelText('Email')).toBeInTheDocument()
    expect(screen.getByLabelText('Password')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Masuk' })).toBeInTheDocument()
  })

  it('menampilkan pesan error dari server dan mempertahankan input', async () => {
    const user = userEvent.setup()

    vi.mocked(login).mockResolvedValue({
      error: {
        code: 'VALIDATION_ERROR',
        message: 'Validasi gagal.',
        fields: { email: ['Email atau password salah.'] },
        details: {},
        requestId: 'req-1',
        status: 422,
      },
    })

    renderLogin()

    await user.type(screen.getByLabelText('Email'), 'admin@cinnamon.test')
    await user.type(screen.getByLabelText('Password'), 'salah')
    await user.click(screen.getByRole('button', { name: 'Masuk' }))

    await waitFor(() => {
      expect(screen.getByText('Email atau password salah.')).toBeInTheDocument()
    })

    expect(screen.getByLabelText('Email')).toHaveValue('admin@cinnamon.test')
  })

  it('berpindah ke dashboard setelah login berhasil', async () => {
    const user = userEvent.setup()

    vi.mocked(login).mockResolvedValue({
      data: {
        id: 'user-1',
        name: 'Admin',
        email: 'admin@cinnamon.test',
        role: 'admin',
        is_active: true,
        last_login_at: null,
      },
    })

    renderLogin()

    await user.type(screen.getByLabelText('Email'), 'admin@cinnamon.test')
    await user.type(screen.getByLabelText('Password'), 'password')
    await user.click(screen.getByRole('button', { name: 'Masuk' }))

    await waitFor(() => {
      expect(screen.getByRole('heading', { name: 'Dashboard' })).toBeInTheDocument()
    })
  })
})
```

- [ ] **Step 2: Jalankan test untuk memastikan gagal**

Run:
```bash
cd /d/DEV/0.AI/Cinnamon2/frontend && npx vitest run src/features/auth/LoginPage.test.tsx
```
Expected: FAIL — `LoginPage` Phase 0 belum punya label `Email`/`Password` yang terhubung dan tidak memakai `useAuth`.

- [ ] **Step 3: Implementasikan `LoginPage`**

Ganti seluruh isi `frontend/src/features/auth/LoginPage.tsx` dengan:

```typescript
import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useAuth } from './auth-context'
import './LoginPage.css'

const loginSchema = z.object({
  email: z.string().min(1, 'Email wajib diisi.').email('Format email tidak valid.'),
  password: z.string().min(1, 'Password wajib diisi.'),
  remember: z.boolean().optional(),
})

type LoginFormValues = z.infer<typeof loginSchema>

export function LoginPage() {
  const { login } = useAuth()
  const navigate = useNavigate()
  const [serverMessage, setServerMessage] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<LoginFormValues>({
    resolver: zodResolver(loginSchema),
    defaultValues: { email: '', password: '', remember: false },
  })

  async function onSubmit(values: LoginFormValues) {
    setServerMessage(null)

    const error = await login(values.email, values.password, values.remember ?? false)

    if (error) {
      setServerMessage(error.message)
      return
    }

    navigate('/', { replace: true })
  }

  return (
    <main className="login">
      <form className="login__card" onSubmit={handleSubmit(onSubmit)} noValidate>
        <h1 className="login__title">CINNAMON</h1>
        <p className="login__subtitle">Sistem inventory dan penjualan</p>

        {serverMessage ? (
          <p className="login__error" role="alert">
            {serverMessage}
          </p>
        ) : null}

        <div className="login__field">
          <label className="login__label" htmlFor="email">
            Email
          </label>
          <input
            id="email"
            type="email"
            className="login__input"
            autoComplete="username"
            aria-invalid={errors.email ? 'true' : undefined}
            aria-describedby={errors.email ? 'email-error' : undefined}
            {...register('email')}
          />
          {errors.email ? (
            <p className="login__field-error" id="email-error">
              {errors.email.message}
            </p>
          ) : null}
        </div>

        <div className="login__field">
          <label className="login__label" htmlFor="password">
            Password
          </label>
          <input
            id="password"
            type="password"
            className="login__input"
            autoComplete="current-password"
            aria-invalid={errors.password ? 'true' : undefined}
            aria-describedby={errors.password ? 'password-error' : undefined}
            {...register('password')}
          />
          {errors.password ? (
            <p className="login__field-error" id="password-error">
              {errors.password.message}
            </p>
          ) : null}
        </div>

        <label className="login__remember">
          <input type="checkbox" {...register('remember')} />
          <span>Ingat saya</span>
        </label>

        <button type="submit" className="login__submit" disabled={isSubmitting}>
          {isSubmitting ? 'Memproses…' : 'Masuk'}
        </button>
      </form>
    </main>
  )
}
```

- [ ] **Step 4: Buat CSS login**

Buat `frontend/src/features/auth/LoginPage.css`:

```css
.login {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: var(--space-4);
}

.login__card {
  width: 100%;
  max-width: 360px;
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: var(--radius-card);
  padding: var(--space-6);
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.login__title {
  font-size: var(--font-size-page-title);
  letter-spacing: 0.08em;
  color: var(--brand-700);
}

.login__subtitle {
  color: var(--ink-600);
  margin-top: calc(var(--space-3) * -1);
}

.login__error {
  background: #fdecea;
  border: 1px solid var(--danger-700);
  color: var(--danger-700);
  border-radius: var(--radius-input);
  padding: var(--space-3);
}

.login__field {
  display: flex;
  flex-direction: column;
  gap: var(--space-1);
}

.login__label {
  font-weight: 600;
}

.login__input {
  border: 1px solid var(--line);
  border-radius: var(--radius-input);
  padding: var(--space-2) var(--space-3);
  font: inherit;
  background: var(--surface);
}

.login__input[aria-invalid='true'] {
  border-color: var(--danger-700);
}

.login__field-error {
  color: var(--danger-700);
  font-size: 12px;
}

.login__remember {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  color: var(--ink-600);
}

.login__submit {
  border: none;
  background: var(--brand-600);
  color: var(--surface);
  border-radius: var(--radius-button);
  padding: var(--space-3);
  font-weight: 600;
  transition: background var(--transition-fast);
}

.login__submit:hover:not(:disabled) {
  background: var(--brand-700);
}

.login__submit:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
```

- [ ] **Step 5: Buat `ProfilePage`**

Buat `frontend/src/features/auth/ProfilePage.tsx`:

```typescript
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useAuth } from './auth-context'

const profileSchema = z.object({
  name: z.string().min(1, 'Nama wajib diisi.').max(120, 'Nama maksimal 120 karakter.'),
})

const passwordSchema = z
  .object({
    current_password: z.string().min(1, 'Password saat ini wajib diisi.'),
    password: z.string().min(8, 'Password baru minimal 8 karakter.'),
    password_confirmation: z.string().min(1, 'Konfirmasi password wajib diisi.'),
  })
  .refine((values) => values.password === values.password_confirmation, {
    message: 'Konfirmasi password tidak sama.',
    path: ['password_confirmation'],
  })

type ProfileValues = z.infer<typeof profileSchema>
type PasswordValues = z.infer<typeof passwordSchema>

export function ProfilePage() {
  const { user, updateProfile, updatePassword } = useAuth()
  const [profileNotice, setProfileNotice] = useState<string | null>(null)
  const [passwordNotice, setPasswordNotice] = useState<string | null>(null)

  const profileForm = useForm<ProfileValues>({
    resolver: zodResolver(profileSchema),
    defaultValues: { name: user?.name ?? '' },
  })

  const passwordForm = useForm<PasswordValues>({
    resolver: zodResolver(passwordSchema),
    defaultValues: { current_password: '', password: '', password_confirmation: '' },
  })

  async function onProfileSubmit(values: ProfileValues) {
    setProfileNotice(null)
    const error = await updateProfile(values.name)

    if (error) {
      setProfileNotice(error.message)
      return
    }

    setProfileNotice('Nama berhasil diperbarui.')
  }

  async function onPasswordSubmit(values: PasswordValues) {
    setPasswordNotice(null)
    const error = await updatePassword(values)

    if (error) {
      setPasswordNotice(error.message)
      return
    }

    setPasswordNotice('Password berhasil diubah.')
    passwordForm.reset()
  }

  return (
    <section>
      <h1>Profil</h1>
      <p>
        Email: {user?.email} — Peran: {user?.role === 'admin' ? 'Admin' : 'Karyawan'}
      </p>

      <form onSubmit={profileForm.handleSubmit(onProfileSubmit)} noValidate>
        <h2>Ubah nama</h2>
        {profileNotice ? <p role="status">{profileNotice}</p> : null}
        <div>
          <label htmlFor="profile-name">Nama</label>
          <input
            id="profile-name"
            aria-invalid={profileForm.formState.errors.name ? 'true' : undefined}
            aria-describedby={
              profileForm.formState.errors.name ? 'profile-name-error' : undefined
            }
            {...profileForm.register('name')}
          />
          {profileForm.formState.errors.name ? (
            <p id="profile-name-error">{profileForm.formState.errors.name.message}</p>
          ) : null}
        </div>
        <button type="submit" disabled={profileForm.formState.isSubmitting}>
          Simpan nama
        </button>
      </form>

      <form onSubmit={passwordForm.handleSubmit(onPasswordSubmit)} noValidate>
        <h2>Ubah password</h2>
        {passwordNotice ? <p role="status">{passwordNotice}</p> : null}
        <div>
          <label htmlFor="current-password">Password saat ini</label>
          <input id="current-password" type="password" {...passwordForm.register('current_password')} />
        </div>
        <div>
          <label htmlFor="new-password">Password baru</label>
          <input
            id="new-password"
            type="password"
            aria-invalid={passwordForm.formState.errors.password ? 'true' : undefined}
            aria-describedby={
              passwordForm.formState.errors.password ? 'new-password-error' : undefined
            }
            {...passwordForm.register('password')}
          />
          {passwordForm.formState.errors.password ? (
            <p id="new-password-error">{passwordForm.formState.errors.password.message}</p>
          ) : null}
        </div>
        <div>
          <label htmlFor="confirm-password">Konfirmasi password baru</label>
          <input
            id="confirm-password"
            type="password"
            aria-invalid={
              passwordForm.formState.errors.password_confirmation ? 'true' : undefined
            }
            {...passwordForm.register('password_confirmation')}
          />
          {passwordForm.formState.errors.password_confirmation ? (
            <p>{passwordForm.formState.errors.password_confirmation.message}</p>
          ) : null}
        </div>
        <button type="submit" disabled={passwordForm.formState.isSubmitting}>
          Simpan password
        </button>
      </form>
    </section>
  )
}
```

- [ ] **Step 6: Jalankan test login untuk memastikan lulus**

Run:
```bash
cd /d/DEV/0.AI/Cinnamon2/frontend && npx vitest run src/features/auth/LoginPage.test.tsx
```
Expected: PASS — 3 test hijau.

- [ ] **Step 7: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add frontend/src/features/auth && git commit -m "frontend: build login and profile pages with accessible form feedback"
```

---

## Task 13: Frontend — test guard, session-expiry handling, dan verifikasi build

**Files:**
- Create: `frontend/src/features/auth/AuthGuard.test.tsx`
- Modify: `frontend/src/lib/api/client.ts`

**Interfaces:**
- Consumes: `AuthGuard`, `AuthProvider` dari Task 10; `toApiError` dari Task 9.
- Produces: test untuk redirect ke `/login` saat belum auth dan penanganan 419.

- [ ] **Step 1: Tulis test guard**

Buat `frontend/src/features/auth/AuthGuard.test.tsx`:

```typescript
import { describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { AuthProvider } from './AuthProvider'
import { AuthGuard } from './AuthGuard'
import { fetchMe } from '../../lib/api/client'

vi.mock('../../lib/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../lib/api/client')>()

  return {
    ...actual,
    fetchMe: vi.fn(),
  }
})

function renderGuard() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  })

  return render(
    <QueryClientProvider client={queryClient}>
      <AuthProvider>
        <MemoryRouter initialEntries={['/rahasia']}>
          <Routes>
            <Route
              path="/rahasia"
              element={
                <AuthGuard>
                  <h1>Halaman Rahasia</h1>
                </AuthGuard>
              }
            />
            <Route path="/login" element={<h1>Login</h1>} />
          </Routes>
        </MemoryRouter>
      </AuthProvider>
    </QueryClientProvider>,
  )
}

describe('AuthGuard', () => {
  it('mengalihkan ke halaman login bila session tidak ada', async () => {
    vi.mocked(fetchMe).mockRejectedValue(new Error('unauthenticated'))

    renderGuard()

    await waitFor(() => {
      expect(screen.getByRole('heading', { name: 'Login' })).toBeInTheDocument()
    })
  })

  it('merender konten terlindungi bila session ada', async () => {
    vi.mocked(fetchMe).mockResolvedValue({
      id: 'user-1',
      name: 'Admin',
      email: 'admin@cinnamon.test',
      role: 'admin',
      is_active: true,
      last_login_at: null,
    })

    renderGuard()

    await waitFor(() => {
      expect(screen.getByRole('heading', { name: 'Halaman Rahasia' })).toBeInTheDocument()
    })
  })
})
```

- [ ] **Step 2: Jalankan test untuk memastikan lulus**

Run:
```bash
cd /d/DEV/0.AI/Cinnamon2/frontend && npx vitest run src/features/auth/AuthGuard.test.tsx
```
Expected: PASS — 2 test hijau. Jika test pertama gagal karena konten terlindungi tetap tampil, periksa `AuthGuard`: `isLoading` harus true selagi `fetchMe` berjalan.

- [ ] **Step 3: Pastikan 419 dipetakan ke pesan yang bisa dipahami**

Di `frontend/src/lib/api/client.ts`, fungsi `toApiError` sudah menangani status 419 (Review Focus #4). Tambahkan test unit untuknya di `frontend/src/lib/api/client.test.ts`:

```typescript
import { describe, expect, it } from 'vitest'
import { AxiosError } from 'axios'
import { toApiError } from './client'

function axiosErrorWithStatus(status: number): AxiosError {
  return new AxiosError('Request failed', 'ERR_BAD_REQUEST', undefined, undefined, {
    status,
    data: {},
    statusText: '',
    headers: {},
    config: { headers: {} as never },
  })
}

describe('toApiError', () => {
  it('memetakan 419 menjadi pesan sesi berakhir', () => {
    const error = toApiError(axiosErrorWithStatus(419))

    expect(error.status).toBe(419)
    expect(error.message).toContain('Sesi Anda telah berakhir')
  })

  it('memetakan error jaringan menjadi pesan koneksi', () => {
    const error = toApiError(new Error('network down'))

    expect(error.code).toBe('SERVER_ERROR')
    expect(error.message).toContain('jaringan')
  })
})
```

- [ ] **Step 4: Jalankan seluruh test frontend**

Run:
```bash
cd /d/DEV/0.AI/Cinnamon2/frontend && npm test
```
Expected: semua test hijau (5 formatter + 3 login + 2 guard + 2 client = 12 test).

- [ ] **Step 5: Jalankan lint, typecheck, dan build**

Run:
```bash
cd /d/DEV/0.AI/Cinnamon2/frontend && npm run lint && npm run typecheck && npm run build
```
Expected: ESLint tanpa error; `tsc -b` tanpa error; `vite build` menghasilkan `dist/`.

- [ ] **Step 6: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add frontend && git commit -m "frontend: add auth guard tests and session expiry handling"
```

---

## Task 14: Verifikasi integrasi end-to-end dan dokumentasi

**Files:**
- Create: `Docs/setup.md`
- Create: `Docs/phase-1-done.md`
- Modify: `Docs/roadmap.md` (tandai Phase 1 selesai)

**Interfaces:**
- Consumes: seluruh hasil Task 1–13.
- Produces: dokumentasi setup yang bisa diikuti orang lain dari repo kosong; ringkasan hasil verifikasi.

- [ ] **Step 1: Jalankan backend dan frontend secara manual**

Terminal 1:
```bash
cd /d/DEV/0.AI/Cinnamon2/backend && php artisan serve --host=127.0.0.1 --port=8000
```

Terminal 2:
```bash
cd /d/DEV/0.AI/Cinnamon2/frontend && npm run dev
```

- [ ] **Step 2: Verifikasi health endpoint**

Run:
```bash
curl -s http://127.0.0.1:8000/api/v1/health
```
Expected: `{"data":{"status":"ok"},"meta":{"request_id":"…"}}`.

- [ ] **Step 3: Verifikasi login dan proteksi role lewat HTTP**

Run:
```bash
curl -s -X POST http://127.0.0.1:8000/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -d '{"email":"admin@cinnamon.test","password":"password"}' -c /tmp/cin.cookies
```
Expected: `data.role` = `admin`, ada `meta.request_id`, tidak ada `password` di body.

Lalu:
```bash
curl -s http://127.0.0.1:8000/api/v1/me -b /tmp/cin.cookies -H 'Accept: application/json'
```
Expected: `data.email` = `admin@cinnamon.test`.

Lalu:
```bash
curl -s http://127.0.0.1:8000/api/v1/users -b /tmp/cin.cookies -H 'Accept: application/json'
```
Expected: `data` berisi 2 user, `meta.total` = 2, ada `meta.current_page`.

- [ ] **Step 4: Verifikasi UI di browser**

Buka `http://127.0.0.1:5173/login`. Login dengan `admin@cinnamon.test` / `password`. Expect: pindah ke Dashboard, sidebar menampilkan modul termasuk "Laporan" dan "Proyek". Logout. Login dengan `karyawan@cinnamon.test` / `password`. Expect: sidebar TIDAK menampilkan "Laporan" dan "Proyek".

- [ ] **Step 5: Tulis `Docs/setup.md`**

Buat `Docs/setup.md` berisi: prerequisites (PHP 8.5, Composer, Node 26, MySQL 8 via XAMPP), langkah `git clone`, `composer install`, `cp .env.example .env`, `php artisan key:generate`, buat database `cinnamon_v2`, `php artisan migrate --seed`, `npm install` di frontend, `npm run dev`; catatan bahwa database `cinnamon` berisi fixture TA legacy dan tidak boleh dihapus; perintah test (`php artisan test`, `npm test`).

- [ ] **Step 6: Tulis `Docs/phase-1-done.md`**

Buat `Docs/phase-1-done.md` berisi daftar acceptance criteria Phase 1 dari `Docs/roadmap.md` dan hasil verifikasi nyata (hasil command di atas, tanggal, environment PHP 8.5.11 / Node 26.2.0 / MySQL XAMPP).

- [ ] **Step 7: Tandai Phase 1 selesai di roadmap**

Edit `Docs/roadmap.md` — tambahkan di bawah judul "## Phase 1 — Foundation dan autentikasi" baris:

```
**Status:** selesai pada 2026-10-21 — lihat `Docs/phase-1-done.md`.
```

- [ ] **Step 8: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add Docs && git commit -m "docs: add setup guide and phase 1 completion notes"
```

---

## Self-Review Checklist

1. **Spec coverage:**
   - Roadmap Phase 1 "struktur repository backend/frontend" → Task 1 (repo), Task 2 (config).
   - "MySQL, environment lokal, Sanctum session cookie, CSRF, CORS, timezone Asia/Jakarta" → Task 2 (timezone, CORS, stateful), Task 9 (Vite proxy, XSRF), Task 3 (CSRF-safe error envelope).
   - "role Admin/Karyawan, login, logout, profile/current user, policy awal, demo seeder sintetis" → Task 4 (`isAdmin`, `UserPolicy`), Task 5 (seeder), Task 6 (endpoint auth + `/users` + policy test).
   - "API response/error envelope, request id, health endpoint, normalisasi error" → Task 3.
   - "React router, API client, auth provider, protected routes, app shell, token Design.md, layar login" → Task 9 (token + client), Task 10 (provider + router + guard), Task 11 (shell), Task 12 (login + profil).
   - "lint, formatter, test runner, build, instruksi menjalankan kedua aplikasi" → Task 7 (Pint), Task 9 (ESLint + Prettier + Vitest), Task 13 (lint/typecheck/build), Task 14 (`Docs/setup.md`).
   - "instalasi bersih dapat migrate dan seed" → Task 5 Step 3, Task 14 Step 6.
   - "route dan API menolak akses yang tidak berhak" → Task 6 (`AuthorizationTest`), Task 8 (edge case).
   - "React menampilkan loading/error dengan benar" → Task 11 (`PageState`), Task 12 (error test), Task 13 (guard test).
   - "tidak ada secret di frontend atau repository" → Task 1 Step 5, Task 8 (`test_error_response_never_leaks_internals`).

2. **Step scan:** Setiap langkah berisi satu aksi dengan hasil yang bisa dicek. Tidak ada "TBD" atau "tangani edge case" tanpa instruksi. Task 6 Step 6 memuat dua versi method `index` — versi kedua yang(final) menggantikan yang pertama; jalankan yang kedua.

3. **Type consistency:**
   - `App\Support\ApiResponse::success(mixed $data, int $status = 200)` dan `::error(string $code, string $message, array $fields = [], array $details = [], int $status = 422)` — dipakai di Task 3, 4, 6.
   - `App\Support\RequestId::current(): string` — dipakai di middleware, `ApiResponse`, exception renderer.
   - `App\Models\User::isAdmin(): bool` — dipakai di `UserPolicy` (Task 4).
   - `LoginRequest::credentials(): array{email: string, password: string}` — dipakai di `AuthController::login` (Task 6).
   - `UserResource` menyerialisasi `id, name, email, role, is_active, last_login_at, created_at` — `frontend/src/lib/api/types.ts` `User` memuat `id, name, email, role, is_active, last_login_at, created_at?` (Task 9). Konsisten.
   - `useAuth()` mengembalikan `{ user, isLoading, login, logout, updateProfile, updatePassword, refresh }` — dipakai di `Sidebar`, `Topbar`, `DashboardPage`, `LoginPage`, `ProfilePage`, `AuthGuard` (Task 10–12).
   - `formatMoney` / `formatQuantity` — didefinisikan di Task 9, diuji di Task 9, dipakai di Phase 2+.
   - Semua pengimpor `useAuth` menarik dari `features/auth/auth-context`, bukan dari `AuthProvider` (rule `react-refresh/only-export-components` menolak file yang mencampur komponen dan hook).

4. **Verifikasi empiris (bukan asumsi):**
   - `withRouting(apiPrefix: 'api/v1')` tersedia di `ApplicationBuilder` Laravel 13.37 (`vendor/laravel/framework/src/Illuminate/Foundation/Configuration/ApplicationBuilder.php:162`).
   - `HasUuids` di Laravel 13 sudah memanggil `Str::uuid7()` — `newUniqueId()` tidak perlu di-override (diverifikasi di `vendor/.../Concerns/HasUuids.php:18`).
   - `Str::isUuid()` menerima UUID v4 maupun v7 (diverifikasi lewat `php -r`).
   - Sanctum mendaftarkan route `/csrf-cookie` dengan prefix `sanctum` dan middleware group `web` — bukan di bawah `/api/v1`.
   - `EnsureFrontendRequestsAreStateful` menentukan stateful dari header `Referer` (fallback `Origin`); tanpa keduanya request dianggap bukan stateful sehingga cookie session tidak diteruskan. Karena itu dev memakai Vite proxy (same-origin), bukan CORS lintas-origin.
   - `PreventRequestForgery` (pengganti `ValidateCsrfToken` di Laravel 13) sudah mem-bypass cek CSRF saat `runningUnitTests()` — test login tidak perlu mengirim token manual.
   - Extensi PHP `intl` tidak terpasang (`NumberFormatter` tidak ada) — `Illuminate\Support\Number::format()` akan melempar exception. Plan memakai formatter sendiri.
   - Semua pola file frontend (auth-context, AuthProvider, AuthGuard, LoginPage, ProfilePage, Sidebar, Topbar, PageState, NotFoundPage) sudah diverifikasi **lolos ESLint** dengan konfigurasi repo yang terpasang — termasuk rule `react-refresh/only-export-components` dan `react-hooks/*` yang strict.

5. **Review Focus:** Enam item dipin ke test:
   - #1 (Karyawan → 403) → `AuthorizationTest::test_karyawan_gets_403_on_admin_only_endpoint` (Task 6) + `AuthEdgeCaseTest::test_karyawan_session_hitting_admin_endpoint_returns_403_not_401` (Task 8).
   - #2 (tanpa session → 401, bukan 500) → `ErrorEnvelopeTest::test_unauthenticated_request_returns_401_not_500` (Task 3) + `AuthEdgeCaseTest::test_protected_endpoint_without_session_returns_401_with_request_id` (Task 8).
   - #3 (user non-aktif ditolak) → `AuthenticationTest::test_inactive_user_cannot_login` (Task 6) + `AuthEdgeCaseTest::test_inactive_user_cannot_login_and_no_session_row_is_created` (Task 8).
   - #4 (419 XSRF) → `client.test.ts::toApiError memetakan 419` (Task 13).
   - #5 (id bukan bigint) → `HealthTest::test_user_id_is_not_a_bigint` + `test_user_id_is_uuid_v7_and_findable_by_string` (Task 4) + verifikasi tipe kolom di Task 5 Step 7.
   - #6 (cookie session diteruskan) → Task 14 Step 3 (curl login → `/me` dengan cookie yang sama) + Task 2 Step 4 (konfigurasi `SANCTUM_STATEFUL_DOMAINS`).

6. **Proportion:** 14 task, ~26 file baru, ~8 file dimodifikasi. Backend ~7 task, frontend ~5 task, verifikasi 2 task. Setiap task punya satu deliverable yang bisa di-review terpisah.

---

## Execution Handoff

Plan lengkap disimpan di `Docs/plans/2026-10-21-phase-1-foundation-and-auth.md`. Silakan review. Metode eksekusi yang diinginkan:

- **Subagent-driven** — subagent baru mengimplementasikan tiap task, reviewer baru mengeceknya sebelum task berikutnya mulai, lalu review seluruh branch di akhir. Paling thorough; biaya satu konteks baru per task dan per review.
- **Native** — saya implementasikan setiap task sendiri di sesi ini, lalu satu reviewer baru mengecek seluruh branch. paling cepat dan murah; tidak ada review independen sampai akhir. cocok untuk sesi model kelas menengah karena plan ini membawa desainnya.

Untuk plan ini saya rekomendasikan **Native**, karena task 1–8 saling bergantung lewat interface yang persis (`ApiResponse`, `RequestId`, `User::isAdmin()`, `UserPolicy`, `LoginRequest::credentials()`) dan kesalahan di task 3 atau 4 akan merambat ke seluruh task hilir — menjaga satu konteks yang koheren mengurangi risiko keputusan yang tidak konsisten antar task. Task 9–13 (frontend) relatif independen dari backend dan bisa dipisah bila diinginkan.

Apakah plan ini sudah menangkap yang Anda inginkan, dan metode mana yang akan kita pakai?
