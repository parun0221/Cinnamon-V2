# Phase 1 — Foundation dan Autentikasi Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Melengkapi fondasi yang belum ada dari Phase 0 — endpoint ganti password, pengelolaan user oleh Admin, navigasi modul per role, halaman 404 dan state kosong/error, serta dokumentasi setup — dan membuktikannya dengan menjalankan aplikasinya.

**Architecture:** Satu monorepo: `backend/` (Laravel 13 REST API) dan `frontend/` (React 19 + TypeScript + Vite). Backend menyimpan schema di MySQL `cinnamon_v2`; database `cinnamon` (fixture TA legacy) tidak tersentuh. Backend adalah sumber kebenaran untuk auth, role, dan permission; React hanya menampilkan dan mengirim intent.

**Tech Stack:**
- Backend: Laravel 13.37, PHP 8.5.11, MySQL 8 (XAMPP di `127.0.0.1:3306`, user `root`, password kosong), Laravel Sanctum `^4.3`.
- Frontend: Vite 8.3, React 19, TypeScript 6.0, React Router DOM 7, TanStack Query 5, ESLint flat config.

**Spec:** `Docs/PRD.md` §4, §8, §9, §10, §11; `Docs/Architecture.md` §2, §5, §6, §7, §8, §9, §12, §14; `Docs/API.md` §2, §3, §4.1 (baris `/users`), §6, §7, §10; `Docs/Database.md` §2, §4 (tabel `users`), §9; `Docs/Design.md` §1, §2, §3, §4, §5, §6, §7, §8, §9, §10, §11; `Docs/roadmap.md` "Phase 1 — Foundation dan autentikasi"; `Docs/AGENTS.md` §3, §5, §6, §7, §8; `Docs/decisions.md` §9 (penempatan berkas) dan §10 (tanpa file test baru).

**Prasyarat (Phase 0 — sudah selesai):** `Docs/decisions.md` ada; Sanctum terpasang; `User` model UUID v7 + `role` ENUM + `is_active`; seeder membuat `admin@cinnamon.test` dan `karyawan@cinnamon.test` (password `password`); `routes/api.php` punya `/health`, `/auth/login`, `/auth/logout`, `/me` (GET, PATCH), `/users` (GET, Admin-only); `bootstrap/app.php` memasang prefix `/api/v1`, middleware `request.id`, dan exception renderer untuk semua status; `frontend/src/lib/api/client.ts`, `types.ts`, `errors.ts`, `features/auth/*`, `app/router.tsx`, `app/app-shell.tsx`, `styles/tokens.css` sudah ada. Ringkasan Phase 0 di `Docs/phase-0-done.md`.

---

## Global Constraints

- **Tidak ada file test baru.** Suite yang ada (47 test backend, 22 test frontend) tetap jalan sebagai jaring regresi sebelum commit, tapi tidak diperluas — lihat `Docs/decisions.md` §10. Setiap task diverifikasi dengan menjalankan aplikasinya, bukan dengan menulis test.
- Prefix API `/api/v1`. Prefix soal routing dan env ada di `Docs/AGENTS.md` §6 dan plan ini.
- Semua response sukses: `{ "data": …, "meta": { "request_id": "…" } }`. Semua error: `{ "error": { "code", "message", "fields", "details" }, "meta": { "request_id" } }`.
- Mapping status error (dipin dari `Docs/API.md` §7): `VALIDATION_ERROR` → 422, `UNAUTHENTICATED` → 401, `FORBIDDEN` → 403, `NOT_FOUND` → 404, `BAD_REQUEST` → 400, `RATE_LIMITED` → 429, `SERVER_ERROR` → 500.
- **Kode produksi backend ditulis langsung di tempatnya** — `backend/app/…`, `backend/routes/`, `backend/config/`, `backend/database/`, `backend/bootstrap/`. Kode test hanya di `backend/tests/`. Kode produksi tidak boleh ditulis di `backend/tests/`.
- `Auth::guard('web')` dipakai eksplisit di login dan logout — middleware `auth:sanctum` mengganti default guard ke `RequestGuard` yang tidak punya `logout()`.
- `AuthController::login` mengecek `hasSession()` **sebelum** kredensial, lalu `is_active` sebelum `Hash::check`; pesan 401 sama untuk email tak dikenal, password salah, dan user non-aktif.
- Timestamp diserialisasi lewat `App\Support\Timestamp::iso8601()` (Asia/Jakarta, `+07:00`), bukan `->toIso8601String()` langsung.
- `lang/id/validation.php` + `APP_LOCALE=id` — pesan validasi tampil apa adanya di UI.
- Frontend `baseURL` default **relatif** (`''`), bukan `http://localhost:8000`, supaya trafik dev lewat Vite proxy dan cookie session tetap first-party.
- Timezone aplikasi `Asia/Jakarta`. Money 2 desimal, quantity 3 desimal, keduanya diserialisasi sebagai string.
- Dua role saja: `admin` dan `karyawan`.
- Bahasa antarmuka: Bahasa Indonesia.
- `.env` backend tidak masuk git. `.env` frontend hanya berisi `VITE_API_URL` dan konfigurasi non-rahasia.
- Extension PHP `intl` **tidak terpasang** — jangan pakai `Illuminate\Support\Number::format()`, pakai `number_format()`.
- Frontend harus lolos `tsc -b` dan ESLint flat config yang sudah ada **tanpa menambah rule**. `react-refresh/only-export-components` = error, jadi file yang mengekspor komponen React sekaligus hook akan gagal — hook/context dipisah ke file non-komponen.
- Tidak ada dependensi baru di luar Tech Stack.
- Database `cinnamon` berisi fixture TA legacy. **Tidak boleh** dipakai untuk schema baru dan tidak boleh dihapus.
- `mysql.exe` ada di `C:\xampp\mysql\bin\mysql.exe`, user `root`, password kosong.

---

## Review Focus

Lima kondisi yang paling mungkin menggigit pengguna nyata. Masing-masing harus dibuktikan oleh langkah verifikasi di task yang memiliki kodenya.

1. **Karyawan yang sudah login memanggil endpoint Admin** → `403 FORBIDDEN` dengan `meta.request_id`, bukan `401` dan bukan `500`. Verifikasi di Task 2.
2. **Admin mengubah role/status user lain** → perubahan benar-benar berlaku pada login berikutnya user itu, dan user yang dinonaktifkan tidak bisa login. Verifikasi di Task 2.
3. **Pengguna salah password saat ganti password** → `422` dengan pesan Bahasa Indonesia, password lama tidak berubah. Verifikasi di Task 1.
4. **Navigasi menampilkan modul yang tidak boleh diakses role** → Karyawan tidak melihat entri Administration; dan halaman yang memang tidak ada memberi 404 yang bisa dipahami, bukan layar kosong. Verifikasi di Task 3.
5. **Ganti password lalu session berikutnya** → user tetap login dengan password baru, dan password lama ditolak. Verifikasi di Task 1.

---

## Peta File

```
backend/
  app/
    Http/
      Controllers/Api/V1/AuthController.php     (MODIFIKASI) — +updatePassword
      Controllers/Api/V1/UserController.php     (MODIFIKASI) — +store, +show, +update
      Requests/Auth/UpdatePasswordRequest.php   (BARU)
      Requests/User/StoreUserRequest.php        (BARU)
      Requests/User/UpdateUserRequest.php       (BARU)
    Policies/UserPolicy.php                     (MODIFIKASI) — +create
  routes/api.php                                (MODIFIKASI) — +/me/password, +/users/{user}
  database/factories/UserFactory.php            (MODIFIKASI) — state carrier

frontend/
  src/
    app/
      router.tsx                                (MODIFIKASI) — +route 404, +role guard
      app-shell.tsx                             (MODIFIKASI) — nav modul per role
      app-shell.css                             (MODIFIKASI) — mobile menu
    components/
      layout/Sidebar.tsx                        (BARU)
      layout/Topbar.tsx                         (BARU)
      feedback/PageState.tsx                    (BARU)
    features/
      auth/ProfilePage.tsx                      (MODIFIKASI) — +ganti password
      auth/ProfilePage.css                      (MODIFIKASI)
      auth/UserManagementPage.tsx               (BARU) — Admin saja
      misc/NotFoundPage.tsx                     (BARU)
    lib/
      api/client.ts                             (MODIFIKASI) — +updatePassword, +user CRUD
      api/types.ts                              (MODIFIKASI) — +PaginatedEnvelope
  styles/tokens.css                             (MODIFIKASI) — token sidebar/role baru

Docs/
  setup.md                                      (BARU)
  phase-1-done.md                               (BARU)
  roadmap.md                                    (MODIFIKASI) — tandai Phase 1 selesai
```

---

## Task 1: Ganti password (`POST /api/v1/me/password`)

**Files:**
- Create: `backend/app/Http/Requests/Auth/UpdatePasswordRequest.php`
- Modify: `backend/app/Http/Controllers/Api/V1/AuthController.php`
- Modify: `backend/routes/api.php`
- Modify: `frontend/src/lib/api/client.ts`
- Modify: `frontend/src/features/auth/ProfilePage.tsx`
- Modify: `frontend/src/features/auth/ProfilePage.css`

**Interfaces:**
- Consumes: `App\Models\User` dari Phase 0, envelope dari `bootstrap/app.php`.
- Produces:
  - `App\Http\Requests\Auth\UpdatePasswordRequest` — aturan `current_password` (wajib, `current_password`), `password` (wajib, `min:8`, `confirmed`), `password_confirmation` (wajib, `same:password`).
  - `AuthController::updatePassword(UpdatePasswordRequest $request): JsonResponse` — mengembalikan `ApiResponse::success(null)`.
  - `frontend/src/lib/api/client.ts` — `updatePassword(payload: { current_password: string; password: string; password_confirmation: string }): Promise<void>`.

- [ ] **Step 1: Tuliskan acceptance check**

Tuliskan lebih dulu apa yang harus dibuktikan, supaya implementasinya terukur:

| # | Skenario | Harap |
|---|---|---|
| A | `current_password` salah | 422 `VALIDATION_ERROR`, `fields.current_password` berisi pesan Bahasa Indonesia |
| B | `password` hanya 7 karakter | 422, `fields.password` |
| C | `password_confirmation` tidak sama | 422, `fields.password_confirmation` |
| D | semua benar | 200, `data` null |
| E | setelah D, login dengan password baru | 200 |
| F | setelah D, login dengan password lama | 401 `UNAUTHENTICATED` |
| G | request tanpa session | 401 `UNAUTHENTICATED` |

**Why:** tidak ada file test baru (Global Constraints), jadi daftar ini yang jadi acuan. Semuanya diperiksa live di Task 7.

- [ ] **Step 2: Buat `UpdatePasswordRequest`**

Buat `backend/app/Http/Requests/Auth/UpdatePasswordRequest.php`:

```php
<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class UpdatePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['required', 'string', 'same:password'],
        ];
    }
}
```

`current_password` memakai rule bawaan Laravel yang membandingkan dengan hash user yang sedang login — jadi user tidak bisa mengubah password orang lain.

- [ ] **Step 3: Tambahkan `updatePassword` ke `AuthController`**

Tambahkan method ini ke `backend/app/Http/Controllers/Api/V1/AuthController.php`:

```php
    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        // Cast 'hashed' pada model menghash password yang di-fill apa adanya.
        $user->forceFill([
            'password' => $request->validated()['password'],
        ])->save();

        return ApiResponse::success(null);
    }
```

Tambahkan `use App\Http\Requests\Auth\UpdatePasswordRequest;` ke bagian import.

- [ ] **Step 4: Daftarkan route**

Di `backend/routes/api.php`, tambahkan baris ini di dalam grup `auth:sanctum`:

```php
Route::post('/me/password', [AuthController::class, 'updatePassword'])->middleware('auth:sanctum');
```

- [ ] **Step 5: Tambahkan pemanggilan API di frontend**

Di `frontend/src/lib/api/client.ts`, tambahkan:

```ts
export async function updatePassword(payload: {
  current_password: string
  password: string
  password_confirmation: string
}): Promise<void> {
  await apiClient.post('/me/password', payload)
}
```

- [ ] **Step 6: Tambahkan form ganti password di `ProfilePage`**

Di `frontend/src/features/auth/ProfilePage.tsx`, tambahkan form kedua di bawah form nama yang sudah ada. Form memuat tiga input: `current_password`, `password`, `password_confirmation` — semuanya `type="password"`, `required`, dengan `<label>` yang terhubung lewat `htmlFor`/`id`. State `status` dan `error` yang ada dipakai ulang, dan pesan error ditampilkan dengan `role="alert"`.

Aturan tambahan: tombol submit nonaktif selama request berjalan (mencegah double submit), dan input **tidak** dikosongkan saat error jaringan atau validasi — nilai yang diketik user harus tetap ada supaya bisa diperbaiki.

- [ ] **Step 7: Jalankan pemeriksaan statis**

Run:
```bash
cd /d/DEV/0.AI/Cinnamon2/backend && ./vendor/bin/pint --test
cd /d/DEV/0.AI/Cinnamon2/frontend && npx tsc -b && npm run lint
```
Expected: Pint exit 0 tanpa offense; `tsc -b` tanpa diagnostic; ESLint tanpa error.

- [ ] **Step 8: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add backend/app backend/routes && git commit -m "backend: add the change-password endpoint"
cd /d/DEV/0.AI/Cinnamon2 && git add frontend/src && git commit -m "frontend: add the change-password form to the profile page"
```

**Lanjut ke Task 2 setelah verifikasi live Task 1 di Task 7 Step 2.**

---

## Task 2: Pengelolaan user oleh Admin (`POST /users`, `GET/PATCH /users/{user}`)

**Files:**
- Create: `backend/app/Http/Requests/User/StoreUserRequest.php`
- Create: `backend/app/Http/Requests/User/UpdateUserRequest.php`
- Modify: `backend/app/Http/Controllers/Api/V1/UserController.php`
- Modify: `backend/app/Policies/UserPolicy.php`
- Modify: `backend/routes/api.php`
- Modify: `frontend/src/lib/api/client.ts`
- Modify: `frontend/src/lib/api/types.ts`

**Interfaces:**
- Consumes: `User` model + factory, `UserPolicy` dari Phase 0.
- Produces:
  - `App\Policies\UserPolicy::create(User $actor): bool` — true hanya untuk `role === 'admin'`.
  - `UserController::store(StoreUserRequest $request): JsonResponse` — 201 dengan `UserResource`.
  - `UserController::show(User $user): JsonResponse` — 200 dengan `UserResource`.
  - `UserController::update(UpdateUserRequest $request, User $user): JsonResponse` — 200 dengan `UserResource`.
  - `frontend/src/lib/api/types.ts` — `PaginatedEnvelope<T>` (sudah ada di Phase 0), plus `UpdateUserPayload` `{ name?: string; email?: string; role?: UserRole; is_active?: boolean }`.

**Contrat API (`Docs/API.md` §4.1):** `/users` GET/POST = Admin; `/users/{user}` GET/PATCH = Admin.

- [ ] **Step 1: Tuliskan acceptance check**

| # | Skenario | Harap |
|---|---|---|
| A | Karyawan `GET /api/v1/users` | 403 `FORBIDDEN` + `meta.request_id` |
| B | Admin `POST /users` dengan email ganda | 422, `fields.email` |
| C | Admin `POST /users` dengan `role: "karyawan"` | 201, `data.role` = `karyawan` |
| D | Admin `POST /users` tanpa `password` | 422, `fields.password` |
| E | Admin `GET /users/{id}` milik orang lain | 200 dengan data user itu |
| F | Admin `PATCH /users/{id}` set `is_active: false` | 200, `data.is_active` false |
| G | user yang di-`F` coba login | 401 `UNAUTHENTICATED` |
| H | user yang di-`F` dengan session lama akses `GET /me` | 401 (session tidak lagi berlaku) |
| I | Karyawan `GET /users/{id}` | 403 `FORBIDDEN` |
| J | `GET /users/{id}` dengan UUID yang tidak ada | 404 `NOT_FOUND` |
| K | `PATCH /users/{id}` set `role: "admin"` | 200, `data.role` = `admin` |
| L | Admin tidak bisa `PATCH` dirinya sendiri jadi non-aktif | 422 dengan pesan yang menjelaskan (lihat catatan di bawah) |

**Catatan L:** admin yang menonaktifkan dirinya sendiri terkunci dari sistem. Endpoint menolak perubahan itu dengan `422 VALIDATION_ERROR` dan pesan Bahasa Indonesia. Ini keputusan defensif, bukan berasal dari `Docs/API.md` — catat sebagai asumsi di `Docs/decisions.md` bila perlu.

- [ ] **Step 2: Tambahkan `create` ke `UserPolicy`**

Di `backend/app/Policies/UserPolicy.php`, tambahkan method:

```php
    public function create(User $actor): bool
    {
        return $actor->role === 'admin';
    }
```

- [ ] **Step 3: Buat request untuk user**

Buat `backend/app/Http/Requests/User/StoreUserRequest.php`:

```php
<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::in(['admin', 'karyawan'])],
        ];
    }
}
```

Buat `backend/app/Http/Requests/User/UpdateUserRequest.php`:

```php
<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'email' => ['sometimes', 'required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->route('user'))],
            'role' => ['sometimes', 'required', Rule::in(['admin', 'karyawan'])],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
```

`is_active` sengaja tidak ada di `StoreUserRequest` — user baru selalu aktif, dibuat lewat form Admin.

- [ ] **Step 4: Tambahkan method ke `UserController`**

Tambahkan tiga method ke `backend/app/Http/Controllers/Api/V1/UserController.php`:

```php
    public function store(StoreUserRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Password ikut ter-fill apa adanya karena ada di $fillable, dan cast
        // 'hashed' pada model akan menghash-nya. Jangan Hash::make() di sini:
        // cast itu sudah cukup, dan hash manual menambah satu cara yang bisa
        // terlewat.
        $user = User::query()->create([
            ...$validated,
            'is_active' => true,
        ]);

        return ApiResponse::success(new UserResource($user), 201);
    }

    public function show(User $user): JsonResponse
    {
        $this->authorize('view', $user);

        return ApiResponse::success(new UserResource($user));
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $validated = $request->validated();

        // Menonaktifkan akun sendiri mengunci admin keluar dari sistem.
        if (isset($validated['is_active']) && $validated['is_active'] === false && $user->is($request->user())) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'Anda tidak dapat menonaktifkan akun Anda sendiri.',
                ['is_active' => ['Akun sendiri tidak dapat dinonaktifkan.']],
                [],
                422,
            );
        }

        $user->fill($validated)->save();

        return ApiResponse::success(new UserResource($user->refresh()));
    }
```

Tambahkan import yang dipakai: `App\Http\Requests\User\StoreUserRequest`, `App\Http\Requests\User\UpdateUserRequest`, `App\Support\ApiResponse`. `Illuminate\Support\Facades\Hash` **tidak** dipakai — cast `hashed` pada model yang mengurus hashing.

**Catatan `fill()`:** field `role` dan `is_active` harus_massignable di model. Kalau `User` belum memuatnya, tambahkan ke properti `$fillable`. Periksa `backend/app/Models/User.php` — Phase 0 sudah mengisinya, tapi pastikan saat implementasi.

- [ ] **Step 5: Daftarkan route**

Di `backend/routes/api.php`, ganti baris `Route::get('/users', ...)` menjadi:

```php
Route::get('/users', [UserController::class, 'index'])->middleware('auth:sanctum');
Route::post('/users', [UserController::class, 'store'])->middleware('auth:sanctum');
Route::get('/users/{user}', [UserController::class, 'show'])->middleware('auth:sanctum');
Route::patch('/users/{user}', [UserController::class, 'update'])->middleware('auth:sanctum');
```

- [ ] **Step 6: Tambahkan pemanggilan API di frontend**

Di `frontend/src/lib/api/client.ts`, tambahkan:

```ts
export async function fetchUsers(page = 1): Promise<PaginatedEnvelope<User>> {
  const { data } = await apiClient.get<PaginatedEnvelope<User>>('/users', { params: { page } })
  return data
}

export async function createUser(payload: {
  name: string
  email: string
  password: string
  role: UserRole
}): Promise<User> {
  const { data } = await apiClient.post<Envelope<User>>('/users', payload)
  return data.data
}

export async function updateUser(
  id: string,
  payload: { name?: string; email?: string; role?: UserRole; is_active?: boolean },
): Promise<User> {
  const { data } = await apiClient.patch<Envelope<User>>(`/users/${id}`, payload)
  return data.data
}
```

Pastikan `PaginatedEnvelope`, `Envelope`, dan `UserRole` sudah di-import di file itu. Kalau `Envelope` belum ada, tambahkan ke `types.ts`:

```ts
export type Envelope<T> = {
  data: T
  meta: Meta
}
```

- [ ] **Step 7: Jalankan pemeriksaan statis**

Run:
```bash
cd /d/DEV/0.AI/Cinnamon2/backend && ./vendor/bin/pint --test
cd /d/DEV/0.AI/Cinnamon2/frontend && npx tsc -b && npm run lint
```
Expected: ketiganya bersih.

- [ ] **Step 8: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add backend/app backend/routes && git commit -m "backend: add admin user management endpoints"
cd /d/DEV/0.AI/Cinnamon2 && git add frontend/src/lib/api && git commit -m "frontend: add the user management api client"
```

**Lanjut ke Task 3 setelah verifikasi live Task 2 di Task 7 Step 2.**

---

## Task 3: Navigasi modul per role, halaman 404, dan state layar

**Files:**
- Create: `frontend/src/components/layout/Sidebar.tsx`
- Create: `frontend/src/components/layout/Topbar.tsx`
- Create: `frontend/src/components/feedback/PageState.tsx`
- Create: `frontend/src/features/misc/NotFoundPage.tsx`
- Create: `frontend/src/features/auth/UserManagementPage.tsx`
- Modify: `frontend/src/app/app-shell.tsx`
- Modify: `frontend/src/app/app-shell.css`
- Modify: `frontend/src/app/router.tsx`
- Modify: `frontend/src/styles/tokens.css`

**Interfaces:**
- Consumes: `useAuth()` dari `frontend/src/features/auth/auth-context.ts` (sudah ada), `useAuth().user.role` menentukan menu.
- Produces:
  - `frontend/src/components/layout/Sidebar.tsx` — menerima prop `items: NavItem[]` dan `onNavigate?`. `NavItem` = `{ label: string; to: string; adminOnly?: boolean }`.
  - `frontend/src/components/layout/Topbar.tsx` — menerima `title`, `user`, `onLogout`.
  - `frontend/src/components/feedback/PageState.tsx` — `variant: 'loading' | 'empty' | 'error'`, dengan `message` dan `action?`.
  - `frontend/src/features/misc/NotFoundPage.tsx` — 404 Bahasa Indonesia dengan tautan kembali ke Dashboard.
  - `frontend/src/features/auth/UserManagementPage.tsx` — daftar user, ubah role, aktif/nonaktif.

- [ ] **Step 1: Tuliskan acceptance check**

| # | Skenario | Harap |
|---|---|---|
| A | Admin membuka `/` | Sidebar menampilkan semua 7 grup modul per `Docs/Design.md` §3 |
| B | Karyawan membuka `/` | Sidebar **tidak** menampilkan Administration; modul yang tidak diizinkan hilang |
| C | Karyawan mengetik `/admin/users` di address bar | Redirect ke `/` — atau halaman Forbidden, tapi **bukan** daftar user |
| D | Admin mengetik `/halaman-ngawur` | Halaman 404 Bahasa Indonesia dengan tombol kembali ke Dashboard |
| E | Karyawan mengetik `/halaman-ngawur` | Halaman 404 yang sama |
| F | Halaman memuat daftar yang kosong | `PageState` empty dengan langkah berikutnya, bukan spinner yang berputar |
| G | Halaman gagal memuat | `PageState` error dengan pesan yang bisa dipahami dan tombol coba lagi |
| H | Layar sempit (< 720px) | Sidebar jadi menu yang bisa dibuka/ditutup; konten tetap terbaca |
| I | Admin mengubah role user di UI | Perubahan terlihat setelah refresh, dan user itu tidak lagi bisa akses menu Admin setelah login ulang |

- [ ] **Step 2: Susun daftar modul**

Buat konstanta daftar modul di `frontend/src/app/app-shell.tsx` (atau file terpisah bila lebih bersih), mengikuti urutan `Docs/Design.md` §3:

```ts
type NavGroup = {
  label: string
  items: { label: string; to: string; adminOnly?: boolean }[]
}

const navGroups: NavGroup[] = [
  { label: 'Dashboard', items: [{ label: 'Dashboard', to: '/' }] },
  { label: 'Master Data', items: [
    { label: 'Kategori', to: '/master/categories' },
    { label: 'Produk', to: '/master/products' },
    { label: 'Supplier', to: '/master/suppliers' },
    { label: 'Customer', to: '/master/customers' },
  ] },
  { label: 'Penerimaan', items: [
    { label: 'Penerimaan Baru', to: '/receiving/new' },
    { label: 'Verifikasi Pending', to: '/receiving/pending' },
    { label: 'Riwayat', to: '/receiving/history' },
  ] },
  { label: 'Penjualan', items: [
    { label: 'Penjualan Baru', to: '/sales/new' },
    { label: 'Riwayat', to: '/sales/history' },
  ] },
  { label: 'Inventori', items: [
    { label: 'Ringkasan Stok', to: '/inventory/stock' },
    { label: 'Koreksi', to: '/inventory/adjustments' },
    { label: 'Movement', to: '/inventory/movements' },
  ] },
  { label: 'Laporan', items: [
    { label: 'Penerimaan', to: '/reports/incoming' },
    { label: 'Pengeluaran', to: '/reports/outgoing' },
    { label: 'Stok', to: '/reports/stock' },
    { label: 'Profit', to: '/reports/profit' },
    { label: 'Ledger', to: '/reports/ledger' },
  ] },
  { label: 'Administrasi', items: [
    { label: 'Pengguna', to: '/admin/users', adminOnly: true },
    { label: 'Profil', to: '/profile' },
  ] },
]
```

Modul yang belum punya halaman (semua kecuali Dashboard, Profil, Pengguna) **tetap tampil sebagai tautan** — ini placeholder navigasi untuk fase berikutnya. Tautan yang belum ada halamanya harus punya route yang mengembalikan `NotFoundPage`, bukan link mati. Memberi label "segera" pada item yang belum berfungsi lebih jujur daripada menyembunyikannya, karena roadmap menyatakan urutan modulnya.

- [ ] **Step 3: Buat `Sidebar`**

Buat `frontend/src/components/layout/Sidebar.tsx` — navigasi modul, dikelompokkan per grup, `adminOnly` difilter sebelum dirender. Sidebar menerima daftar yang sudah difilter dari pemanggil (AppShell), sehingga filtering role terjadi di satu tempat.

Setiap `NavLink` punya `aria-current` otomatis dari React Router, dan state fokus harus terlihat (pakai `var(--focus-ring)`).

- [ ] **Step 4: Buat `Topbar`**

Buat `frontend/src/components/layout/Topbar.tsx` — judul halaman, nama user, label peran ("Admin"/"Karyawan"), dan tombol "Keluar". Judul halaman berasal dari `useMatches()` atau dari prop; paling sederhana: prop `title`.

- [ ] **Step 5: Buat `PageState`**

Buat `frontend/src/components/feedback/PageState.tsx` dengan tiga variant:

- `loading` — skeleton (blok abu-abu dengan animasi) sesuai `Docs/Design.md` §11, dengan `aria-busy="true"` dan `aria-live="polite"`.
- `empty` — judul, kalimat yang menjelaskan, dan langkah berikutnya. Untuk halaman kosong, contoh langkah berikutnya: "Tambahkan produk pertama" atau "Catat penerimaan pertama".
- `error` — pesan Bahasa Indonesia yang bisa dipahami ("Gagal memuat data. Silakan coba lagi.") plus tombol "Coba lagi" yang menerima callback.

Semua state punya `role="status"` atau `role="alert"` sesuai sifatnya, dan teks selalu berupa label — tidak hanya warna (`Docs/AGENTS.md` §7).

- [ ] **Step 6: Buat `NotFoundPage`**

Buat `frontend/src/features/misc/NotFoundPage.tsx` — judul "Halaman tidak ditemukan", penjelasan singkat, dan tombol "Kembali ke Dashboard" yang menaut ke `/`.

- [ ] **Step 7: Susun ulang `app-shell.tsx`**

`app-shell.tsx` menjadi penyusun layout: membaca `useAuth()` untuk `user`, menyaring `navGroups` berdasarkan `user.role`, merender `Sidebar`, `Topbar`, dan `<Outlet />`.

Perubahan mobile: di bawah 720px sidebar menjadi menu yang tertutup secara default dengan tombol toggle di topbar, dan `aria-expanded` pada tombol. State sidebar berada di `app-shell.tsx`.

- [ ] **Step 8: Tambah route 404 dan route admin**

Di `frontend/src/app/router.tsx`, tambahkan route catch-all di **luar** grup `AuthGuard` supaya 404 bisa diakses tamu juga:

```tsx
  {
    path: '*',
    element: <NotFoundPage />,
  },
```

Tambahkan route admin di dalam `AppShell`:

```tsx
          { path: 'admin/users', element: <UserManagementPage /> },
```

- [ ] **Step 9: Guard berbasis role**

`UserManagementPage` hanya boleh dirender untuk Admin. Guard dipasang di route, bukan hanya dengan menyembunyikan menu — server tetap sumber kebenaran, tapi UI juga tidak boleh menampilkan halaman yang pasti ditolak (`Docs/AGENTS.md` §4, §7).

Cara paling sederhana yang tidak menambah dependensi: `UserManagementPage` memeriksa `user?.role !== 'admin'` di awal render dan mengembalikan halaman Forbidden bila tidak. Jika nanti butuh pola yang lebih umum, pindahkan ke komponen `RoleGuard` tersendiri.

- [ ] **Step 10: Buat `UserManagementPage`**

Buat `frontend/src/features/auth/UserManagementPage.tsx` — daftar user dari `GET /api/v1/users` memakai `fetchUsers`, dengan `useQuery` dari TanStack Query. Tiap baris menampilkan nama, email, peran, dan status aktif.

- Kolom peran: `select` dengan dua opsi (Admin, Karyawan). Mengubahnya memanggil `updateUser(id, { role })`.
- Kolom status: tombol "Aktifkan"/"Nonaktifkan" yang memanggil `updateUser(id, { is_active })`.
- both mutations memakai `onSuccess` untuk invalidasi query `['users']`.
- Loading memakai `<PageState variant="loading" />`, error memakai `<PageState variant="error" />` dengan tombol coba lagi, dan daftar kosong memakai `<PageState variant="empty" />`.
- Pesan sukses dan error tampil di halaman, bukan hanya di konsol.

- [ ] **Step 11: Jalankan pemeriksaan statis**

Run:
```bash
cd /d/DEV/0.AI/Cinnamon2/frontend && npx tsc -b && npm run lint && npm run build
```
Expected: `tsc -b` bersih, ESLint tanpa error, build sukses menghasilkan `dist/`.

- [ ] **Step 12: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add frontend/src && git commit -m "frontend: add role-based navigation, 404 page, and page states"
```

**Lanjut ke Task 4 setelah verifikasi live Task 3 di Task 7 Step 3.**

---

## Task 4: Prettier, `Docs/setup.md`, dan penutup fase

**Files:**
- Modify: `frontend/package.json` (tambah `prettier` + script `format`)
- Create: `Docs/setup.md`
- Create: `Docs/phase-1-done.md`
- Modify: `Docs/roadmap.md`

**Interfaces:**
- Consumes: seluruh hasil Task 1–3.
- Produces: `Docs/setup.md` sebagai panduan instalasi dari database kosong.

- [ ] **Step 1: Pasang Prettier**

Run:
```bash
cd /d/DEV/0.AI/Cinnamon2/frontend && npm install -D prettier
```

Tambahkan script ke `frontend/package.json`:

```json
"format": "prettier --write src"
```

Tambahkan `frontend/.prettierrc` dengan isi:

```json
{
  "semi": false,
  "singleQuote": true,
  "printWidth": 100
}
```

**Catatan:** konvensi repo sekarang tanpa semicolon dan single quote — file Prettier itu mengikuti konvensi yang sudah dipakai, supaya tidak ada diff massal.

Setelah memasang Prettier, jalankan `npm run format` lalu periksa `git diff` — kalau Prettier mengubah banyak file yang tidak disentuh task ini, **jangan** commit perubahan itu. enough: biarkan Prettier tersedia untuk file baru, dan jangan rapikan kode lama di task ini.

- [ ] **Step 2: Tulis `Docs/setup.md`**

Tulis `Docs/setup.md` yang memuat:

1. **Prasyarat** — PHP 8.5, Composer, Node.js, MySQL 8 (XAMPP), dan ekstensi PHP yang dibutuhkan (termasuk `pdo_mysql`).
2. **Database** — perintah membuat `cinnamon_v2`, dan **peringatan** bahwa database `cinnamon` berisi fixture TA legacy yang tidak boleh dihapus atau dipakai untuk schema baru.
3. **Backend** — `composer install`, `cp .env.example .env`, `php artisan key:generate`, `php artisan migrate --seed`, `php artisan serve`.
4. **Frontend** — `npm install`, `npm run dev`.
5. **Akun demo** — `admin@cinnamon.test` dan `karyawan@cinnamon.test`, password `password`, beserta apa yang masing-masing boleh lakukan.
6. **Environment** — daftar variabel yang perlu diisi, tanpa nilai rahasia.
7. **Pemeriksaan** — perintah lint, typecheck, build, dan suite test yang sudah ada.
8. **Masalah umum** — cookie Sanctum butuh origin yang sama (dev memakai Vite proxy, bukan `VITE_API_URL` absolut), dan `post_max_size` PHP yang terlalu kecil membuat body besar ditolak PHP sebelum Laravel sempat menjawab.

Jangan menaruh `APP_KEY`, password, atau token di file ini.

- [ ] **Step 3: Tulis `Docs/phase-1-done.md`**

Tulis ringkasan Phase 1: apa yang dibangun, hasil verifikasi live yang benar-benar diamati (isikan setelah Task 7), dan apa yang belum dikerjakan.

- [ ] **Step 4: Tandai Phase 1 selesai di roadmap**

Di `Docs/roadmap.md`, tambahkan penanda selesai pada Phase 1 yang menyertakan tautan ke `Docs/phase-1-done.md` dan `Docs/setup.md`.

- [ ] **Step 5: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add frontend/package.json frontend/.prettierrc frontend/package-lock.json && git commit -m "chore: add prettier"
cd /d/DEV/0.AI/Cinnamon2 && git add Docs && git commit -m "docs: add setup guide and phase 1 completion notes"
```

---

## Task 5: Verifikasi live backend (API)

**Files:** tidak ada file baru; verifikasi saja.

Semua pemeriksaan memakai server yang benar-benar jalan dan database dev yang sama. Tidak ada file test baru.

- [ ] **Step 1: Jalankan server dan siapkan cookie**

```bash
cd /d/DEV/0.AI/Cinnamon2/backend && php artisan serve --host=127.0.0.1 --port=8000
```

Di terminal lain:

```bash
cd /d/DEV/0.AI/Cinnamon2 && rm -f admin.cookies karyawan.cookies
curl -s -c admin.cookies http://127.0.0.1:8000/sanctum/csrf-cookie -o /dev/null
curl -s -c karyawan.cookies http://127.0.0.1:8000/sanctum/csrf-cookie -o /dev/null
```

`admin.cookies` dan `karyawan.cookies` adalah file sementara di root repo — jangan di-commit (tambahkan ke `.gitignore` kalau belum ada).

- [ ] **Step 2: Verifikasi skenario Task 1 (ganti password)**

Untuk setiap request yang butuh CSRF, ambil token dari cookie:

```bash
TOKEN=$(grep XSRF-TOKEN admin.cookies | awk '{print $7}' | python -c "import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))")
```

Lalu jalankan skenario A–G dari tabel acceptance check Task 1 dan catat kode status + body-nya.

Cara paling sederhana dan paling jujur: pakai satu skrip bash yang mengulang tiap skenario dan mencetak hasilnya, sehingga tabelnya bisa diisi dari output nyata. **Tabelnya diisi dari output nyata, bukan dari dugaan.**

Isi minimal yang harus terisi: kode status untuk setiap skenario, dan `error.code` untuk skenario yang gagal.

- [ ] **Step 3: Verifikasi skenario Task 2 (pengelolaan user)**

Ulangi dengan login sebagai Admin dan sebagai Karyawan. Periksa skenario A–L dari tabel acceptance check Task 2.

Khusus skenario G–H: buat user uji, nonaktifkan lewat API, lalu coba login dengan user itu (harus 401), dan cek bahwa user uji tidak ada di `SELECT email, role, is_active FROM cinnamon_v2.users;` setelah dibersihkan.

Pastikan data uji dibersihkan setelahnya — `cinnamon_v2.users` harus kembali ke dua baris seed.

- [ ] **Step 4: Verifikasi jaring regresi yang ada**

```bash
cd /d/DEV/0.AI/Cinnamon2/backend && php artisan test
```
Expected: 47 test lulus, 210 assertion — sama seperti Phase 0. Kalau ada yang gagal, itu regresi dari Task 1–2 dan harus diperbaiki.

- [ ] **Step 5: Verifikasi data dev tidak rusak**

```bash
"/c/xampp/mysql/bin/mysql.exe" -u root -e "SELECT email, role, is_active FROM cinnamon_v2.users;"
```
Expected: tepat 2 baris (`admin@cinnamon.test` admin, `karyawan@cinnamon.test` karyawan). User uji dari Task 2 harus sudah hilang.

---

## Task 6: Verifikasi live frontend (browser)

**Files:** tidak ada file baru; verifikasi saja.

- [ ] **Step 1: Jalankan Vite dev server**

```bash
cd /d/DEV/0.AI/Cinnamon2/frontend && npm run dev
```

- [ ] **Step 2: Cek alur di browser**

Buka `http://localhost:5173/login` dan jalankan:

| # | Langkah | Harap |
|---|---|---|
| A | Login sebagai Admin | Redirect ke `/`, sidebar menampilkan semua modul |
| B | Buka `/admin/users` | Daftar user terlihat, dua baris seed |
| C | Ubah peran salah satu user lalu simpan | Perubahan terlihat setelah refresh |
| D | Nonaktifkan user uji | Status berubah, tombol berubah jadi "Aktifkan" |
| E | Logout, login sebagai Karyawan | Sidebar **tidak** menampilkan Administration |
| F | Ketik `/admin/users` di address bar | Tidak menampilkan daftar user |
| G | Ketik `/halaman-ngawur` | 404 Bahasa Indonesia + tombol kembali |
| H | Buka `/profile`, isi form ganti password dengan password lama yang salah | Pesan error Bahasa Indonesia di field, input tidak kosong |
| I | Isi dengan password lama yang benar + password baru | Sukses, pesan terkonfirmasi |
| J | Logout, login lagi dengan password baru | Login berhasil |
| K | Login lagi dengan password lama | Ditolak, pesan salah |
| L | Perkecil jendela di bawah 720px | Sidebar menjadi menu yang bisa dibuka/ditutup |
| M | Buka profil, ganti nama jadi nama yang sangat panjang (> 120 karakter) | Ditolak server dengan pesan validasi |

**Catatan:** skenario I mengubah password akun seed. Setelah selesai, kembalikan password kedua akun ke `password` lewat API atau seeder, supaya akun demo tetap seperti dokumentasi.

- [ ] **Step 3: Cek error di console**

Buka console browser saat menjalankan alur di atas. Expect: tidak ada error yang tidak tertangani, dan tidak ada warning React.

- [ ] **Step 4: Jalankan pemeriksaan statis frontend**

```bash
cd /d/DEV/0.AI/Cinnamon2/frontend && npx tsc -b && npm run lint && npm run build
```
Expected: ketiganya bersih, build menghasilkan `dist/`.

- [ ] **Step 5: Jalankan suite frontend yang ada**

```bash
cd /d/DEV/0.AI/Cinnamon2/frontend && npm test
```
Expected: 22 test lulus.

---

## Task 7: Penutup — catat hasil verifikasi

**Files:**
- Modify: `Docs/phase-1-done.md`

**Interfaces:**
- Consumes: output nyata dari Task 5 dan Task 6.
- Produces: catatan Phase 1 yang memuat hasil verifikasi, bukan klaim.

- [ ] **Step 1: Isi `Docs/phase-1-done.md` dengan hasil nyata**

Isi file dengan:

- **Yang dibangun** — tabel per area (backend, frontend, docs) berisi hasilnya.
- **Verifikasi yang dijalankan** — tabel dengan kolom: pemeriksaan, hasil, cara memverifikasi. Isi **hanya dari output nyata** Task 5 dan Task 6. Kalau suatu skenario tidak dijalankan, tulis "tidak dijalankan" — jangan menebaknya.
- **Perubahan yang terlihat user** — daftar perubahan yang mengubah apa yang dilihat orang.
- **Belum dikerjakan** — sisa yang sengaja ditunda, dengan alasannya.
- **Cara menjalankan lokal** — rangkum, dan arahkan ke `Docs/setup.md`.

- [ ] **Step 2: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add Docs && git commit -m "docs: record the phase 1 verification results"
```

---

## Rulings During Execution

Catatan ini ditulis setelah plan dieksekusi. Plan adalah argumen dari spec;
catatan di bawah adalah tempat di mana spec dan plan berbeda, atau di mana
plan tidak menentukan, dan yang harus berubah.

1. **Task 1 — `client.ts` memakai prefix yang salah.** Potongan kode di plan
   memanggil `apiClient.post('/me/password', …)`, sedangkan setiap fungsi lain
   memakai `/api/v1/…`. Diganti menjadi `/api/v1/me/password`.
2. **Task 1 — `current_password` belum punya pesan Bahasa Indonesia.**
   `lang/id/validation.php` tidak punya baris untuk rule itu, sehingga skenario
   A akan menjawab dalam bahasa Inggris, melanggar harapan tabel acceptance.
   Ditambahkan baris rule dan nama atribut `current_password` /
   `password_confirmation`.
3. **Task 1 — form ganti password memakai state sendiri.** Plan meminta memakai
   state `status`/`error` yang sudah ada; state bersama akan menampilkan error
   password di atas form nama.
4. **Task 2 — Menonaktifkan akun harus mengakhiri akses yang sedang berjalan.**
   Skenario H mengharapkan 401, tetapi provider bawaan Laravel mencari user
   dari session berdasarkan id saja, tanpa filter `is_active`, sehingga akun
   yang dinonaktifkan tetap bisa memakai session lama. Ditambahkan
   `App\Auth\ActiveUserProvider`, yang memfilter `is_active` pada
   `retrieveById` dan `retrieveByToken`. Jalur `retrieveByToken` di luar tabel
   acceptance juga diperiksa live: cookie remember milik user yang dinonaktifkan
   menjawab 401.
5. **Task 3 — Yang disembunyikan dari Karyawan adalah entri, bukan grup.**
   Skenario B mengharapkan grup "Administrasi" hilang, tetapi `Docs/Design.md`
   §3 menempatkan Profil di dalam grup itu dan Karyawan butuh Profil untuk
   mencapai form ganti password. Modul "Pengguna" yang disembunyikan; grupnya
   tetap ada.
6. **Task 3 — Tombol menu mobile tidak pernah tampil.** Aturan `display: none`
   pada tombol tidak punya media query yang menampilkannya, sehingga sidebar
   tidak punya cara terbuka di layar sempit. Ditambahkan media query.
7. **Task 3 — Pemeriksaan console error dipersempit.** Chrome mencatat setiap
   respons non-2xx sebagai error konsol, termasuk 401/403/422 yang memang
   ditangani aplikasi. Yang diperiksa adalah error selain status yang
   ditangani, warning React, dan exception yang tidak tertangani.
8. **Task 4 — Prettier memakai `endOfLine: "auto"`.** Nilai bawaan `lf`
   menulis ulang seluruh file ber-`CRLF` dan menghasilkan diff ratusan baris
   pada file yang tidak disentuh task ini.

---

## Self-Review Checklist

1. **Spec coverage:**
   - Roadmap Phase 1 "struktur repository backend/frontend" → sudah ada dari Phase 0, tidak diulang.
   - "MySQL, environment lokal, Sanctum session cookie, CSRF, CORS, timezone Asia/Jakarta" → sudah ada dari Phase 0; CORS sudah ada di `backend/config/cors.php`.
   - "role Admin/Karyawan, login, logout, profile/current user, policy awal, demo seeder sintetis" → Task 2 (user management + policy `create`), Task 1 (profile), Task 3 (navigasi per role).
   - "API response/error envelope, request id, health endpoint, normalisasi error" → sudah ada dari Phase 0.
   - "React router, API client, auth provider, protected routes, app shell, token Design.md, layar login" → Task 3 (app shell penuh, navigasi, 404, page states).
   - "lint, formatter, test runner, build, instruksi menjalankan kedua aplikasi" → Task 4 (Prettier, `Docs/setup.md`); test runner sudah ada dari Phase 0.
   - "instalasi bersih dapat migrate dan seed" → Task 4 (`Docs/setup.md`) dan Task 5 Step 4–5.
   - "route dan API menolak akses yang tidak berhak" → Task 2 skenario A dan I, Task 3 skenario C dan F.
   - "React menampilkan loading/error dengan benar" → Task 3 (`PageState`).
   - "tidak ada secret di frontend atau repository" → Task 4 (dokumentasi tanpa secret) dan Task 6.

2. **Step scan:** Setiap langkah berisi satu aksi dengan hasil yang bisa dicek. Tidak ada "TBD" atau "tangani edge case" tanpa instruksi. Karena tidak ada file test baru, setiap task diawali **acceptance check** — tabel skenario dengan hasil yang diharapkan — dan-Stepunya di close oleh Task 5 (API) dan Task 6 (browser). Verifikasi happening di aplikasi yang jalan, bukan di test double.

3. **Pemisahan produksi vs test:** Tidak ada task yang menulis kode produksi dan kode test sekaligus, karena tidak ada file test baru. Tidak ada `git add` yang mencampur `backend/app` dengan `backend/tests/`.

4. **Type consistency:**
   - `App\Support\ApiResponse::success(mixed $data, int $status = 200)` dan `::error(string $code, string $message, array $fields = [], array $details = [], int $status = 422)` — dipakai di Task 1 dan 2.
   - `App\Http\Requests\Auth\UpdatePasswordRequest` menghasilkan array dengan kunci `current_password`, `password`, `password_confirmation` — sama dengan payload `updatePassword()` di `client.ts`.
   - `App\Http\Requests\User\StoreUserRequest` menghasilkan `name`, `email`, `password`, `role` — sama dengan payload `createUser()`.
   - `App\Http\Requests\User\UpdateUserRequest` menghasilkan `name`, `email`, `role`, `is_active` — sama dengan payload `updateUser()`.
   - `Envelope<T>` = `{ data: T; meta: Meta }`; `PaginatedEnvelope<T>` sudah ada di `types.ts` dari Phase 0 dan dipakai `fetchUsers()`.
   - `useAuth()` mengembalikan `{ user, isLoading, login, logout, updateProfile, updatePassword, refresh }` — dipakai `Sidebar`, `Topbar`, `ProfilePage`, `UserManagementPage`.
   - `User` di `types.ts` memuat `id`, `name`, `email`, `role`, `is_active`, `last_login_at`, `created_at?` — sama dengan `UserResource`.

5. **Verifikasi empiris (bukan asumsi):**
   - `withRouting(apiPrefix: 'api/v1')` tersedia di `ApplicationBuilder` Laravel 13.37.
   - `HasUuids` di Laravel 13 sudah memanggil `Str::uuid7()` — `newUniqueId()` tidak perlu di-override.
   - Rule `current_password` bawaan Laravel membandingkan dengan hash user yang sedang login.
   - `Rule::unique('users', 'email')` menghasilkan pesan validasi Bahasa Indonesia lewat `lang/id/validation.php` + `APP_LOCALE=id`.
   - Sanctum hanya memulai session untuk request stateful (Referer/Origin cocok dengan `sanctum.stateful`) — dev memakai Vite proxy supaya cookie tetap first-party.
   - `PreventRequestForgery` mem-bypass cek CSRF saat `runningUnitTests()` — test login tidak perlu mengirim token manual; live acceptance **wajib** mengirim token.
   - Extensi PHP `intl` tidak terpasang — `Illuminate\Support\Number::format()` akan melempar exception.
   - Semua pola file frontend (auth-context, AuthProvider, AuthGuard, LoginPage, ProfilePage, Sidebar, Topbar, PageState, NotFoundPage, UserManagementPage) sudah diverifikasi **lolos ESLint** dengan konfigurasi repo yang terpasang.

6. **Review Focus:** Setiap butir punya langkah verifikasi di task yang memiliki kodenya:
   - #1 (Karyawan → 403) → Task 2 skenario A dan I, Task 3 skenario E dan F.
   - #2 (ubah role/status berlaku) → Task 2 skenario F–H, Task 3 skenario C.
   - #3 (password lama salah) → Task 1 skenario A.
   - #4 (navigasi sesuai role, 404) → Task 3 skenario B, D, G.
   - #5 (ganti password berlaku) → Task 1 skenario D–F.
