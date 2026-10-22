# Phase 2.1 — Katalog dan Parties Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Menyediakan master data kategori, produk, supplier, dan customer yang dapat dibuat, dicari, difilter, diubah, atau dinonaktifkan lewat UI dan API, dengan policy server menegakkan role Admin untuk mutasi dan semua authenticated user untuk baca.

**Architecture:** Satu monorepo dengan backend Laravel 13 sebagai sumber kebenaran untuk validasi, policy, dan persistence. Frontend React 19 memakai TanStack Query untuk cache server state dan React Router untuk navigasi. Setiap resource memiliki Form Request untuk validasi, Policy untuk otorisasi, Controller untuk orkestrasi, Resource untuk serialisasi, dan Query class untuk filter/pagination. DELETE hanya menghapus baris yang belum direferensikan; data yang sudah dipakai dinonaktifkan lewat PATCH `is_active=false`.

**Tech Stack:**
- Backend: Laravel 13.37, PHP 8.5.11, MySQL 8 (cinnamon_v2), UUID v7 primary keys
- Frontend: Vite 8.3, React 19, TypeScript 6.0, React Router DOM 7, TanStack Query 5
- Testing: Suite yang ada (47 backend, 22 frontend) sebagai jaring regresi; fitur baru diverifikasi dengan menjalankan aplikasi

**Spec:** `Docs/PRD.md` §4, §6.2, §8, §9, §10, §11; `Docs/Architecture.md` §2, §5, §6, §7, §8, §9, §12, §14; `Docs/API.md` §2, §3, §4.1, §5, §6, §7, §10; `Docs/Database.md` §2, §4 (tabel `categories`, `products`, `suppliers`, `customers`), §5, §6, §7; `Docs/Design.md` §1, §2, §3, §4, §5, §6, §7, §8, §9, §10, §11; `Docs/roadmap.md` "Phase 2.1 — Katalog dan parties"; `Docs/AGENTS.md` §3, §5, §6, §7, §8; `Docs/decisions.md` §9 (penempatan berkas) dan §10 (tanpa file test baru).

## Global Constraints

- **Tidak ada file test baru.** Suite yang ada tetap jalan sebagai jaring regresi sebelum commit, tapi tidak diperluas — lihat `Docs/decisions.md` §10. Setiap task diverifikasi dengan menjalankan aplikasinya, bukan dengan menulis test.
- Prefix API `/api/v1`. Prefix soal routing dan env ada di `Docs/AGENTS.md` §6 dan plan ini.
- Semua response sukses: `{ "data": …, "meta": { "request_id": "…" } }`. Semua error: `{ "error": { "code", "message", "fields", "details" }, "meta": { "request_id" } }`.
- Mapping status error (dipin dari `Docs/API.md` §7): `VALIDATION_ERROR` → 422, `UNAUTHENTICATED` → 401, `FORBIDDEN` → 403, `NOT_FOUND` → 404, `BAD_REQUEST` → 400, `RATE_LIMITED` → 429, `SERVER_ERROR` → 500.
- **Kode produksi backend ditulis langsung di tempatnya** — `backend/app/…`, `backend/routes/`, `backend/config/`, `backend/database/`, `backend/bootstrap/`. Kode test hanya di `backend/tests/`. Kode produksi tidak boleh ditulis di `backend/tests/`.
- UUID v7 via Laravel's `HasUuids` trait untuk semua primary key. Route model binding resolve by `id`.
- Timestamp diserialisasi lewat `App\Support\Timestamp::iso8601()` (Asia/Jakarta, `+07:00`), bukan `->toIso8601String()` langsung.
- `lang/id/validation.php` + `APP_LOCALE=id` — pesan validasi tampil apa adanya di UI.
- Money 2 desimal, quantity 3 desimal, keduanya diserialisasi sebagai string.
- Dua role saja: `admin` dan `karyawan`.
- Bahasa antarmuka: Bahasa Indonesia.
- `.env` backend tidak masuk git. `.env` frontend hanya berisi `VITE_API_URL` dan konfigurasi non-rahasia.
- Extension PHP `intl` **tidak terpasang** — jangan pakai `Illuminate\Support\Number::format()`, pakai `number_format()`.
- Frontend harus lolos `tsc -b` dan ESLint flat config yang sudah ada **tanpa menambah rule**.
- Tidak ada dependensi baru di luar Tech Stack.
- Database `cinnamon` berisi fixture TA legacy. **Tidak boleh** dipakai untuk schema baru dan tidak boleh dihapus.
- **DELETE hanya menghapus baris yang belum direferensikan.** Data yang sudah dipakai dinonaktifkan lewat PATCH `is_active=false` dan mengembalikan 409 dengan pesan yang menjelaskan.
- **Upload gambar produk** memakai storage lokal di `storage/app/public/products` dengan validasi MIME (jpg, png, webp) dan ukuran maksimal 2MB. Symbolic link `public/storage` sudah ada dari Phase 1.

---

## Review Focus

Lima kondisi yang paling mungkin menggigit pengguna nyata. Masing-masing harus dibuktikan oleh langkah verifikasi di task yang memiliki kodenya.

1. **Karyawan mencoba mengubah kategori** → `403 FORBIDDEN` dengan `meta.request_id`, bukan `401` dan bukan `500`. Verifikasi di Task 2.
2. **Admin menghapus kategori yang sudah dipakai produk** → `409` dengan pesan yang menjelaskan, bukan hard delete. Verifikasi di Task 2.
3. **SKU duplikat saat membuat produk** → `422 VALIDATION_ERROR` dengan `fields.sku` berisi pesan Bahasa Indonesia. Verifikasi di Task 4.
4. **Upload gambar produk dengan MIME salah** → `422` dengan pesan "Format gambar tidak didukung", file tidak disimpan. Verifikasi di Task 6.
5. **Filter `category_id` dengan nilai non-UUID** → `422 VALIDATION_ERROR` atau diabaikan dengan aman, bukan 500. Verifikasi di Task 5.

---

## Peta File

```
backend/
  app/
    Http/
      Controllers/Api/V1/
        CategoryController.php        (BARU)
        ProductController.php         (BARU)
        SupplierController.php        (BARU)
        CustomerController.php        (BARU)
      Requests/
        Category/
          StoreCategoryRequest.php    (BARU)
          UpdateCategoryRequest.php   (BARU)
        Product/
          StoreProductRequest.php     (BARU)
          UpdateProductRequest.php    (BARU)
          UploadProductImageRequest.php (BARU)
        Supplier/
          StoreSupplierRequest.php    (BARU)
          UpdateSupplierRequest.php   (BARU)
        Customer/
          StoreCustomerRequest.php    (BARU)
          UpdateCustomerRequest.php   (BARU)
      Resources/
        CategoryResource.php          (BARU)
        ProductResource.php           (BARU)
        SupplierResource.php          (BARU)
        CustomerResource.php          (BARU)
    Models/
      Category.php                    (BARU)
      Product.php                     (BARU)
      Supplier.php                    (BARU)
      Customer.php                    (BARU)
    Policies/
      CategoryPolicy.php              (BARU)
      ProductPolicy.php               (BARU)
      SupplierPolicy.php              (BARU)
      CustomerPolicy.php              (BARU)
    Queries/
      ProductQuery.php                (BARU)
  database/
    migrations/
      2026_10_22_000001_create_categories_table.php   (BARU)
      2026_10_22_000002_create_suppliers_table.php    (BARU)
      2026_10_22_000003_create_customers_table.php    (BARU)
      2026_10_22_000004_create_products_table.php     (BARU)
    seeders/
      DatabaseSeeder.php              (MODIFIKASI) — +kategori, supplier, customer, produk demo
  routes/
    api.php                           (MODIFIKASI) — +/categories, +/products, +/suppliers, +/customers

frontend/
  src/
    app/
      router.tsx                      (MODIFIKASI) — +route kategori, produk, supplier, customer
      navigation.ts                   (MODIFIKASI) — update link dari 404 ke halaman nyata
    features/
      catalog/
        CategoryListPage.tsx          (BARU)
        CategoryForm.tsx              (BARU)
        ProductListPage.tsx           (BARU)
        ProductForm.tsx               (BARU)
        SupplierListPage.tsx          (BARU)
        SupplierForm.tsx              (BARU)
        CustomerListPage.tsx          (BARU)
        CustomerForm.tsx              (BARU)
    lib/
      api/
        client.ts                     (MODIFIKASI) — +CRUD kategori, produk, supplier, customer
        types.ts                      (MODIFIKASI) — +Category, Product, Supplier, Customer types
```

---

## Task 1: Migration dan Model untuk Categories

**Files:**
- Create: `backend/database/migrations/2026_10_22_000001_create_categories_table.php`
- Create: `backend/app/Models/Category.php`
- Create: `backend/app/Policies/CategoryPolicy.php`
- Create: `backend/app/Http/Resources/CategoryResource.php`
- Create: `backend/app/Http/Requests/Category/StoreCategoryRequest.php`
- Create: `backend/app/Http/Requests/Category/UpdateCategoryRequest.php`
- Create: `backend/app/Http/Controllers/Api/V1/CategoryController.php`
- Modify: `backend/routes/api.php`

**Interfaces:**
- Consumes: `App\Models\User` dari Phase 0, envelope dari `bootstrap/app.php`.
- Produces:
  - Migration `categories` table: `id` (UUID v7), `name` (VARCHAR 100), `slug` (VARCHAR 120 unique), `description` (TEXT nullable), `is_active` (BOOLEAN default true), timestamps.
  - `App\Models\Category` dengan relasi `hasMany(Product)`.
  - `App\Policies\CategoryPolicy` dengan method `viewAny`, `create`, `update`, `delete` — semua Admin-only kecuali `viewAny` yang boleh semua authenticated user.
  - `App\Http\Resources\CategoryResource` mengembalikan `id`, `name`, `slug`, `description`, `is_active`, `created_at`, `updated_at`.
  - `App\Http\Controllers\Api\V1\CategoryController` dengan method `index`, `store`, `show`, `update`, `destroy`.
  - Route: `GET /categories`, `POST /categories`, `GET /categories/{category}`, `PATCH /categories/{category}`, `DELETE /categories/{category}`.

- [ ] **Step 1: Tuliskan acceptance check**

Tuliskan lebih dulu apa yang harus dibuktikan, supaya implementasinya terukur:

| # | Skenario | Harap |
|---|---|---|
| A | Admin `POST /categories` dengan nama valid | 201, `data.id` UUID, `data.slug` otomatis dari nama |
| B | Admin `POST /categories` dengan nama ganda | 422, `fields.slug` = "Slug ini sudah terdaftar." |
| C | Karyawan `POST /categories` | 403 `FORBIDDEN` + `meta.request_id` |
| D | Admin `GET /categories` | 200, array kategori dengan pagination meta |
| E | Admin `PATCH /categories/{id}` dengan nama baru | 200, `data.name` berubah, `data.slug` ikut berubah |
| F | Admin `DELETE /categories/{id}` yang belum dipakai produk | 200, kategori terhapus |
| G | Admin `DELETE /categories/{id}` yang sudah dipakai produk | 409, pesan "Kategori ini sudah dipakai produk dan tidak dapat dihapus. Nonaktifkan saja." |
| H | Karyawan `GET /categories` | 200, boleh melihat |
| I | `GET /categories/{id tidak ada}` | 404 `NOT_FOUND` |

**Why:** tidak ada file test baru (Global Constraints), jadi daftar ini yang jadi acuan. Semuanya diperiksa live di Task 7.

- [ ] **Step 2: Buat migration `categories`**

Buat `backend/database/migrations/2026_10_22_000001_create_categories_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 100);
            $table->string('slug', 120)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
```

- [ ] **Step 3: Buat Model `Category`**

Buat `backend/app/Models/Category.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['name', 'slug', 'description', 'is_active'])]
class Category extends Model
{
    use HasUuids;

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
```

- [ ] **Step 4: Buat Policy `CategoryPolicy`**

Buat `backend/app/Policies/CategoryPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;

class CategoryPolicy
{
    public function viewAny(User $actor): bool
    {
        return true; // Semua authenticated user boleh melihat
    }

    public function create(User $actor): bool
    {
        return $actor->hasRole('admin');
    }

    public function update(User $actor, Category $category): bool
    {
        return $actor->hasRole('admin');
    }

    public function delete(User $actor, Category $category): bool
    {
        return $actor->hasRole('admin');
    }
}
```

- [ ] **Step 5: Buat Resource `CategoryResource`**

Buat `backend/app/Http/Resources/CategoryResource.php`:

```php
<?php

namespace App\Http\Resources;

use App\Support\Timestamp;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'created_at' => Timestamp::iso8601($this->created_at),
            'updated_at' => Timestamp::iso8601($this->updated_at),
        ];
    }
}
```

- [ ] **Step 6: Buat Form Requests**

Buat `backend/app/Http/Requests/Category/StoreCategoryRequest.php`:

```php
<?php

namespace App\Http\Requests\Category;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['nullable', 'string', 'max:120', Rule::unique('categories', 'slug')],
            'description' => ['nullable', 'string'],
        ];
    }
}
```

Buat `backend/app/Http/Requests/Category/UpdateCategoryRequest.php`:

```php
<?php

namespace App\Http\Requests\Category;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:100'],
            'slug' => [
                'sometimes',
                'string',
                'max:120',
                Rule::unique('categories', 'slug')->ignore($this->route('category')),
            ],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
```

- [ ] **Step 7: Buat Controller `CategoryController`**

Buat `backend/app/Http/Controllers/Api/V1/CategoryController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Category\StoreCategoryRequest;
use App\Http\Requests\Category\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Category::class);

        $perPage = max(1, min(100, (int) $request->integer('per_page', 20)));
        $categories = Category::query()->orderBy('name')->paginate($perPage);

        return response()->json([
            'data' => CategoryResource::collection($categories->items()),
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
                'current_page' => $categories->currentPage(),
                'per_page' => $categories->perPage(),
                'last_page' => $categories->lastPage(),
                'total' => $categories->total(),
            ],
        ]);
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $this->authorize('create', Category::class);

        $validated = $request->validated();
        $validated['slug'] = $validated['slug'] ?? Str::slug($validated['name']);

        $category = Category::query()->create($validated);

        return ApiResponse::success(new CategoryResource($category), 201);
    }

    public function show(Category $category): JsonResponse
    {
        return ApiResponse::success(new CategoryResource($category));
    }

    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        $this->authorize('update', $category);

        $validated = $request->validated();

        if (isset($validated['name']) && !isset($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $category->fill($validated)->save();

        return ApiResponse::success(new CategoryResource($category->refresh()));
    }

    public function destroy(Category $category): JsonResponse
    {
        $this->authorize('delete', $category);

        // Kategori yang sudah dipakai produk tidak boleh dihapus keras.
        if ($category->products()->exists()) {
            return ApiResponse::error(
                'CONFLICT',
                'Kategori ini sudah dipakai produk dan tidak dapat dihapus. Nonaktifkan saja.',
                [],
                [],
                409,
            );
        }

        $category->delete();

        return ApiResponse::success(null);
    }
}
```

- [ ] **Step 8: Daftarkan route**

Di `backend/routes/api.php`, tambahkan baris-baris ini:

```php
use App\Http\Controllers\Api\V1\CategoryController;

Route::get('/categories', [CategoryController::class, 'index'])->middleware('auth:sanctum');
Route::post('/categories', [CategoryController::class, 'store'])->middleware('auth:sanctum');
Route::get('/categories/{category}', [CategoryController::class, 'show'])->middleware('auth:sanctum');
Route::patch('/categories/{category}', [CategoryController::class, 'update'])->middleware('auth:sanctum');
Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->middleware('auth:sanctum');
```

- [ ] **Step 9: Jalankan migration**

```bash
cd /d/DEV/0.AI/Cinnamon2/backend && php artisan migrate
```

Expected: Tabel `categories` dibuat.

- [ ] **Step 10: Jalankan pemeriksaan statis**

```bash
cd /d/DEV/0.AI/Cinnamon2/backend && ./vendor/bin/pint --test
```

Expected: Pint exit 0 tanpa offense.

- [ ] **Step 11: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add backend/database backend/app backend/routes && git commit -m "backend: add categories CRUD with policy and resource"
```

**Lanjut ke Task 2 setelah verifikasi live Task 1 di Task 9 Step 2.**

---

## Task 2: Verifikasi Live Categories API

**Files:** Tidak ada file baru. Task ini murni verifikasi.

- [ ] **Step 1: Jalankan server backend**

```bash
cd /d/DEV/0.AI/Cinnamon2/backend && php artisan serve
```

Biarkan server berjalan di background.

- [ ] **Step 2: Verifikasi skenario A — Admin membuat kategori**

```bash
curl -X POST http://127.0.0.1:8000/api/v1/categories \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -b cookies.txt \
  -d '{"name":"Rempah Daun","description":"Kategori untuk rempah berbentuk daun"}'
```

Expected: 201, response berisi `data.id` (UUID), `data.slug` = "rempah-daun".

- [ ] **Step 3: Verifikasi skenario B — Slug duplikat**

Ulangi request di Step 2 dengan nama yang sama.

Expected: 422, `fields.slug` = "Slug ini sudah terdaftar."

- [ ] **Step 4: Verifikasi skenario C — Karyawan mencoba membuat**

Login sebagai karyawan, lalu:

```bash
curl -X POST http://127.0.0.1:8000/api/v1/categories \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -b cookies-karyawan.txt \
  -d '{"name":"Test"}'
```

Expected: 403 `FORBIDDEN`, `meta.request_id` ada.

- [ ] **Step 5: Verifikasi skenario D — Admin melihat daftar**

```bash
curl http://127.0.0.1:8000/api/v1/categories \
  -H "Accept: application/json" \
  -b cookies.txt
```

Expected: 200, `data` array, `meta` berisi pagination.

- [ ] **Step 6: Verifikasi skenario E — Admin mengubah kategori**

```bash
curl -X PATCH http://127.0.0.1:8000/api/v1/categories/{id} \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -b cookies.txt \
  -d '{"name":"Rempah Batang"}'
```

Expected: 200, `data.name` = "Rempah Batang", `data.slug` = "rempah-batang".

- [ ] **Step 7: Verifikasi skenario F — Hapus kategori yang belum dipakai**

```bash
curl -X DELETE http://127.0.0.1:8000/api/v1/categories/{id} \
  -H "Accept: application/json" \
  -b cookies.txt
```

Expected: 200, `data` null.

- [ ] **Step 8: Verifikasi skenario G — Hapus kategori yang sudah dipakai**

Buat produk yang memakai kategori lain, lalu coba hapus kategori itu.

Expected: 409, pesan "Kategori ini sudah dipakai produk dan tidak dapat dihapus. Nonaktifkan saja."

- [ ] **Step 9: Verifikasi skenario H — Karyawan melihat daftar**

```bash
curl http://127.0.0.1:8000/api/v1/categories \
  -H "Accept: application/json" \
  -b cookies-karyawan.txt
```

Expected: 200, boleh melihat.

- [ ] **Step 10: Verifikasi skenario I — Kategori tidak ada**

```bash
curl http://127.0.0.1:8000/api/v1/categories/00000000-0000-0000-0000-000000000000 \
  -H "Accept: application/json" \
  -b cookies.txt
```

Expected: 404 `NOT_FOUND`.

- [ ] **Step 11: Matikan server**

```bash
pkill -f "php artisan serve"
```

- [ ] **Step 12: Commit verifikasi**

Tidak ada commit karena tidak ada perubahan kode.

---

## Task 3: Migration dan Model untuk Suppliers

**Files:**
- Create: `backend/database/migrations/2026_10_22_000002_create_suppliers_table.php`
- Create: `backend/app/Models/Supplier.php`
- Create: `backend/app/Policies/SupplierPolicy.php`
- Create: `backend/app/Http/Resources/SupplierResource.php`
- Create: `backend/app/Http/Requests/Supplier/StoreSupplierRequest.php`
- Create: `backend/app/Http/Requests/Supplier/UpdateSupplierRequest.php`
- Create: `backend/app/Http/Controllers/Api/V1/SupplierController.php`
- Modify: `backend/routes/api.php`

**Interfaces:**
- Consumes: `App\Models\User` dari Phase 0.
- Produces:
  - Migration `suppliers` table: `id` (UUID v7), `name` (VARCHAR 160), `address` (TEXT nullable), `contact` (VARCHAR 80 nullable), `email` (VARCHAR 160 nullable), `is_active` (BOOLEAN default true), timestamps.
  - `App\Models\Supplier` dengan relasi `hasMany(IncomingReceipt)` (akan dibuat di Phase 2.2).
  - `App\Policies\SupplierPolicy` dengan method `viewAny` (semua authenticated user), `create`, `update`, `delete` (Admin-only).
  - `App\Http\Resources\SupplierResource` mengembalikan semua field.
  - `App\Http\Controllers\Api\V1\SupplierController` dengan method CRUD lengkap.
  - Route: `GET /suppliers`, `POST /suppliers`, `GET /suppliers/{supplier}`, `PATCH /suppliers/{supplier}`, `DELETE /suppliers/{supplier}`.

- [ ] **Step 1: Tuliskan acceptance check**

| # | Skenario | Harap |
|---|---|---|
| A | Admin `POST /suppliers` dengan nama valid | 201, `data.id` UUID |
| B | Karyawan `POST /suppliers` | 403 `FORBIDDEN` |
| C | Karyawan `GET /suppliers` | 200, boleh melihat (untuk pilih saat menerima barang) |
| D | Admin `DELETE /suppliers/{id}` yang sudah dipakai receipt | 409, pesan "Supplier ini sudah dipakai penerimaan dan tidak dapat dihapus. Nonaktifkan saja." |
| E | Admin `PATCH /suppliers/{id}` dengan `is_active: false` | 200, `data.is_active` = false |

- [ ] **Step 2: Buat migration `suppliers`**

Buat `backend/database/migrations/2026_10_22_000002_create_suppliers_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 160);
            $table->text('address')->nullable();
            $table->string('contact', 80)->nullable();
            $table->string('email', 160)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
```

- [ ] **Step 3: Buat Model `Supplier`**

Buat `backend/app/Models/Supplier.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['name', 'address', 'contact', 'email', 'is_active'])]
class Supplier extends Model
{
    use HasUuids;

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
```

- [ ] **Step 4: Buat Policy `SupplierPolicy`**

Buat `backend/app/Policies/SupplierPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\Supplier;
use App\Models\User;

class SupplierPolicy
{
    public function viewAny(User $actor): bool
    {
        return true; // Karyawan butuh memilih supplier saat menerima barang
    }

    public function create(User $actor): bool
    {
        return $actor->hasRole('admin');
    }

    public function update(User $actor, Supplier $supplier): bool
    {
        return $actor->hasRole('admin');
    }

    public function delete(User $actor, Supplier $supplier): bool
    {
        return $actor->hasRole('admin');
    }
}
```

- [ ] **Step 5: Buat Resource `SupplierResource`**

Buat `backend/app/Http/Resources/SupplierResource.php`:

```php
<?php

namespace App\Http\Resources;

use App\Support\Timestamp;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'address' => $this->address,
            'contact' => $this->contact,
            'email' => $this->email,
            'is_active' => $this->is_active,
            'created_at' => Timestamp::iso8601($this->created_at),
            'updated_at' => Timestamp::iso8601($this->updated_at),
        ];
    }
}
```

- [ ] **Step 6: Buat Form Requests**

Buat `backend/app/Http/Requests/Supplier/StoreSupplierRequest.php`:

```php
<?php

namespace App\Http\Requests\Supplier;

use Illuminate\Foundation\Http\FormRequest;

class StoreSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'address' => ['nullable', 'string'],
            'contact' => ['nullable', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'max:160'],
        ];
    }
}
```

Buat `backend/app/Http/Requests/Supplier/UpdateSupplierRequest.php`:

```php
<?php

namespace App\Http\Requests\Supplier;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:160'],
            'address' => ['nullable', 'string'],
            'contact' => ['nullable', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'max:160'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
```

- [ ] **Step 7: Buat Controller `SupplierController`**

Buat `backend/app/Http/Controllers/Api/V1/SupplierController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Supplier\StoreSupplierRequest;
use App\Http\Requests\Supplier\UpdateSupplierRequest;
use App\Http\Resources\SupplierResource;
use App\Models\Supplier;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Supplier::class);

        $perPage = max(1, min(100, (int) $request->integer('per_page', 20)));
        $suppliers = Supplier::query()->orderBy('name')->paginate($perPage);

        return response()->json([
            'data' => SupplierResource::collection($suppliers->items()),
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
                'current_page' => $suppliers->currentPage(),
                'per_page' => $suppliers->perPage(),
                'last_page' => $suppliers->lastPage(),
                'total' => $suppliers->total(),
            ],
        ]);
    }

    public function store(StoreSupplierRequest $request): JsonResponse
    {
        $this->authorize('create', Supplier::class);

        $supplier = Supplier::query()->create($request->validated());

        return ApiResponse::success(new SupplierResource($supplier), 201);
    }

    public function show(Supplier $supplier): JsonResponse
    {
        return ApiResponse::success(new SupplierResource($supplier));
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier): JsonResponse
    {
        $this->authorize('update', $supplier);

        $supplier->fill($request->validated())->save();

        return ApiResponse::success(new SupplierResource($supplier->refresh()));
    }

    public function destroy(Supplier $supplier): JsonResponse
    {
        $this->authorize('delete', $supplier);

        // Supplier yang sudah dipakai receipt tidak boleh dihapus keras.
        // Relasi incomingReceipts akan dibuat di Phase 2.2.
        if (method_exists($supplier, 'incomingReceipts') && $supplier->incomingReceipts()->exists()) {
            return ApiResponse::error(
                'CONFLICT',
                'Supplier ini sudah dipakai penerimaan dan tidak dapat dihapus. Nonaktifkan saja.',
                [],
                [],
                409,
            );
        }

        $supplier->delete();

        return ApiResponse::success(null);
    }
}
```

- [ ] **Step 8: Daftarkan route**

Di `backend/routes/api.php`, tambahkan:

```php
use App\Http\Controllers\Api\V1\SupplierController;

Route::get('/suppliers', [SupplierController::class, 'index'])->middleware('auth:sanctum');
Route::post('/suppliers', [SupplierController::class, 'store'])->middleware('auth:sanctum');
Route::get('/suppliers/{supplier}', [SupplierController::class, 'show'])->middleware('auth:sanctum');
Route::patch('/suppliers/{supplier}', [SupplierController::class, 'update'])->middleware('auth:sanctum');
Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy'])->middleware('auth:sanctum');
```

- [ ] **Step 9: Jalankan migration**

```bash
cd /d/DEV/0.AI/Cinnamon2/backend && php artisan migrate
```

- [ ] **Step 10: Jalankan pemeriksaan statis**

```bash
cd /d/DEV/0.AI/Cinnamon2/backend && ./vendor/bin/pint --test
```

- [ ] **Step 11: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add backend/database backend/app backend/routes && git commit -m "backend: add suppliers CRUD with policy and resource"
```

---

## Task 4: Migration dan Model untuk Customers

**Files:**
- Create: `backend/database/migrations/2026_10_22_000003_create_customers_table.php`
- Create: `backend/app/Models/Customer.php`
- Create: `backend/app/Policies/CustomerPolicy.php`
- Create: `backend/app/Http/Resources/CustomerResource.php`
- Create: `backend/app/Http/Requests/Customer/StoreCustomerRequest.php`
- Create: `backend/app/Http/Requests/Customer/UpdateCustomerRequest.php`
- Create: `backend/app/Http/Controllers/Api/V1/CustomerController.php`
- Modify: `backend/routes/api.php`

**Interfaces:**
- Consumes: `App\Models\User` dari Phase 0.
- Produces:
  - Migration `customers` table: `id` (UUID v7), `name` (VARCHAR 160), `address` (TEXT nullable), `contact` (VARCHAR 80 nullable), `email` (VARCHAR 160 nullable), `is_active` (BOOLEAN default true), timestamps.
  - `App\Models\Customer` dengan relasi `hasMany(Sale)` (akan dibuat di Phase 3.1).
  - `App\Policies\CustomerPolicy` dengan semua method Admin-only.
  - `App\Http\Resources\CustomerResource` mengembalikan semua field.
  - `App\Http\Controllers\Api\V1\CustomerController` dengan method CRUD lengkap.
  - Route: `GET /customers`, `POST /customers`, `GET /customers/{customer}`, `PATCH /customers/{customer}`, `DELETE /customers/{customer}`.

- [ ] **Step 1: Tuliskan acceptance check**

| # | Skenario | Harap |
|---|---|---|
| A | Admin `POST /customers` dengan nama valid | 201, `data.id` UUID |
| B | Karyawan `POST /customers` | 403 `FORBIDDEN` |
| C | Karyawan `GET /customers` | 403 `FORBIDDEN` (customer hanya untuk Admin) |
| D | Admin `DELETE /customers/{id}` yang sudah dipakai sale | 409, pesan "Customer ini sudah dipakai penjualan dan tidak dapat dihapus. Nonaktifkan saja." |

- [ ] **Step 2: Buat migration `customers`**

Buat `backend/database/migrations/2026_10_22_000003_create_customers_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 160);
            $table->text('address')->nullable();
            $table->string('contact', 80)->nullable();
            $table->string('email', 160)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
```

- [ ] **Step 3: Buat Model `Customer`**

Buat `backend/app/Models/Customer.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['name', 'address', 'contact', 'email', 'is_active'])]
class Customer extends Model
{
    use HasUuids;

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
```

- [ ] **Step 4: Buat Policy `CustomerPolicy`**

Buat `backend/app/Policies/CustomerPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasRole('admin');
    }

    public function create(User $actor): bool
    {
        return $actor->hasRole('admin');
    }

    public function update(User $actor, Customer $customer): bool
    {
        return $actor->hasRole('admin');
    }

    public function delete(User $actor, Customer $customer): bool
    {
        return $actor->hasRole('admin');
    }
}
```

- [ ] **Step 5: Buat Resource `CustomerResource`**

Buat `backend/app/Http/Resources/CustomerResource.php`:

```php
<?php

namespace App\Http\Resources;

use App\Support\Timestamp;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'address' => $this->address,
            'contact' => $this->contact,
            'email' => $this->email,
            'is_active' => $this->is_active,
            'created_at' => Timestamp::iso8601($this->created_at),
            'updated_at' => Timestamp::iso8601($this->updated_at),
        ];
    }
}
```

- [ ] **Step 6: Buat Form Requests**

Buat `backend/app/Http/Requests/Customer/StoreCustomerRequest.php`:

```php
<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'address' => ['nullable', 'string'],
            'contact' => ['nullable', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'max:160'],
        ];
    }
}
```

Buat `backend/app/Http/Requests/Customer/UpdateCustomerRequest.php`:

```php
<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:160'],
            'address' => ['nullable', 'string'],
            'contact' => ['nullable', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'max:160'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
```

- [ ] **Step 7: Buat Controller `CustomerController`**

Buat `backend/app/Http/Controllers/Api/V1/CustomerController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Customer::class);

        $perPage = max(1, min(100, (int) $request->integer('per_page', 20)));
        $customers = Customer::query()->orderBy('name')->paginate($perPage);

        return response()->json([
            'data' => CustomerResource::collection($customers->items()),
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
                'current_page' => $customers->currentPage(),
                'per_page' => $customers->perPage(),
                'last_page' => $customers->lastPage(),
                'total' => $customers->total(),
            ],
        ]);
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $this->authorize('create', Customer::class);

        $customer = Customer::query()->create($request->validated());

        return ApiResponse::success(new CustomerResource($customer), 201);
    }

    public function show(Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);

        return ApiResponse::success(new CustomerResource($customer));
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        $this->authorize('update', $customer);

        $customer->fill($request->validated())->save();

        return ApiResponse::success(new CustomerResource($customer->refresh()));
    }

    public function destroy(Customer $customer): JsonResponse
    {
        $this->authorize('delete', $customer);

        // Customer yang sudah dipakai sale tidak boleh dihapus keras.
        // Relasi sales akan dibuat di Phase 3.1.
        if (method_exists($customer, 'sales') && $customer->sales()->exists()) {
            return ApiResponse::error(
                'CONFLICT',
                'Customer ini sudah dipakai penjualan dan tidak dapat dihapus. Nonaktifkan saja.',
                [],
                [],
                409,
            );
        }

        $customer->delete();

        return ApiResponse::success(null);
    }
}
```

- [ ] **Step 8: Daftarkan route**

Di `backend/routes/api.php`, tambahkan:

```php
use App\Http\Controllers\Api\V1\CustomerController;

Route::get('/customers', [CustomerController::class, 'index'])->middleware('auth:sanctum');
Route::post('/customers', [CustomerController::class, 'store'])->middleware('auth:sanctum');
Route::get('/customers/{customer}', [CustomerController::class, 'show'])->middleware('auth:sanctum');
Route::patch('/customers/{customer}', [CustomerController::class, 'update'])->middleware('auth:sanctum');
Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])->middleware('auth:sanctum');
```

- [ ] **Step 9: Jalankan migration**

```bash
cd /d/DEV/0.AI/Cinnamon2/backend && php artisan migrate
```

- [ ] **Step 10: Jalankan pemeriksaan statis**

```bash
cd /d/DEV/0.AI/Cinnamon2/backend && ./vendor/bin/pint --test
```

- [ ] **Step 11: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add backend/database backend/app backend/routes && git commit -m "backend: add customers CRUD with policy and resource"
```

---

## Task 5: Migration dan Model untuk Products

**Files:**
- Create: `backend/database/migrations/2026_10_22_000004_create_products_table.php`
- Create: `backend/app/Models/Product.php`
- Create: `backend/app/Policies/ProductPolicy.php`
- Create: `backend/app/Http/Resources/ProductResource.php`
- Create: `backend/app/Http/Requests/Product/StoreProductRequest.php`
- Create: `backend/app/Http/Requests/Product/UpdateProductRequest.php`
- Create: `backend/app/Http/Requests/Product/UploadProductImageRequest.php`
- Create: `backend/app/Queries/ProductQuery.php`
- Create: `backend/app/Http/Controllers/Api/V1/ProductController.php`
- Modify: `backend/routes/api.php`

**Interfaces:**
- Consumes: `App\Models\Category` dari Task 1, `App\Models\User` dari Phase 0.
- Produces:
  - Migration `products` table: `id` (UUID v7), `category_id` (FK UUID), `sku` (VARCHAR 80 unique), `name` (VARCHAR 180), `unit` (VARCHAR 30), `purchase_price` (DECIMAL 15,2 nullable), `selling_price` (DECIMAL 15,2), `ready_stock` (DECIMAL 15,3 default 0), `pending_stock` (DECIMAL 15,3 default 0), `minimum_stock` (DECIMAL 15,3 default 0), `image_path` (VARCHAR 255 nullable), `is_active` (BOOLEAN default true), timestamps.
  - `App\Models\Product` dengan relasi `belongsTo(Category)`, `hasMany(IncomingReceiptItem)` (Phase 2.2), `hasMany(StockBatch)` (Phase 2.2), `hasMany(SaleItem)` (Phase 3.1).
  - `App\Policies\ProductPolicy` dengan method `viewAny` (semua authenticated user), `create`, `update`, `delete` (Admin-only).
  - `App\Http\Resources\ProductResource` mengembalikan semua field termasuk `category` (nested object) dan stok.
  - `App\Queries\ProductQuery` menangani filter `category_id`, `search`, `is_active`, `stock_status`, sorting allowlist, pagination.
  - `App\Http\Controllers\Api\V1\ProductController` dengan method CRUD lengkap + `uploadImage`.
  - Route: `GET /products`, `POST /products`, `GET /products/{product}`, `PATCH /products/{product}`, `DELETE /products/{product}`, `POST /products/{product}/image`.

- [ ] **Step 1: Tuliskan acceptance check**

| # | Skenario | Harap |
|---|---|---|
| A | Admin `POST /products` dengan SKU unik | 201, `data.id` UUID, `data.sku` tersimpan |
| B | Admin `POST /products` dengan SKU duplikat | 422, `fields.sku` = "SKU ini sudah terdaftar." |
| C | Admin `POST /products` dengan `category_id` tidak ada | 422, `fields.category_id` |
| D | Karyawan `POST /products` | 403 `FORBIDDEN` |
| E | Karyawan `GET /products` | 200, boleh melihat |
| F | Admin `GET /products?category_id={uuid}` | 200, hanya produk dengan kategori itu |
| G | Admin `GET /products?search=pala` | 200, hanya produk dengan nama/SKU mengandung "pala" |
| H | Admin `DELETE /products/{id}` yang sudah dipakai receipt item | 409, pesan "Produk ini sudah dipakai penerimaan dan tidak dapat dihapus. Nonaktifkan saja." |
| I | Admin `POST /products/{id}/image` dengan file JPG < 2MB | 200, `data.image_path` terisi |
| J | Admin `POST /products/{id}/image` dengan file EXE | 422, pesan "Format gambar tidak didukung." |

- [ ] **Step 2: Buat migration `products`**

Buat `backend/database/migrations/2026_10_22_000004_create_products_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('category_id');
            $table->string('sku', 80)->unique();
            $table->string('name', 180);
            $table->string('unit', 30);
            $table->decimal('purchase_price', 15, 2)->nullable();
            $table->decimal('selling_price', 15, 2);
            $table->decimal('ready_stock', 15, 3)->default(0);
            $table->decimal('pending_stock', 15, 3)->default(0);
            $table->decimal('minimum_stock', 15, 3)->default(0);
            $table->string('image_path', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('category_id')->references('id')->on('categories')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
```

- [ ] **Step 3: Buat Model `Product`**

Buat `backend/app/Models/Product.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable([
    'category_id',
    'sku',
    'name',
    'unit',
    'purchase_price',
    'selling_price',
    'ready_stock',
    'pending_stock',
    'minimum_stock',
    'image_path',
    'is_active',
])]
class Product extends Model
{
    use HasUuids;

    protected $casts = [
        'purchase_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'ready_stock' => 'decimal:3',
        'pending_stock' => 'decimal:3',
        'minimum_stock' => 'decimal:3',
        'is_active' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
```

- [ ] **Step 4: Buat Policy `ProductPolicy`**

Buat `backend/app/Policies/ProductPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    public function viewAny(User $actor): bool
    {
        return true; // Semua authenticated user boleh melihat produk
    }

    public function create(User $actor): bool
    {
        return $actor->hasRole('admin');
    }

    public function update(User $actor, Product $product): bool
    {
        return $actor->hasRole('admin');
    }

    public function delete(User $actor, Product $product): bool
    {
        return $actor->hasRole('admin');
    }
}
```

- [ ] **Step 5: Buat Resource `ProductResource`**

Buat `backend/app/Http/Resources/ProductResource.php`:

```php
<?php

namespace App\Http\Resources;

use App\Support\Timestamp;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'category' => new CategoryResource($this->whenLoaded('category')),
            'sku' => $this->sku,
            'name' => $this->name,
            'unit' => $this->unit,
            'purchase_price' => $this->purchase_price !== null ? (string) $this->purchase_price : null,
            'selling_price' => (string) $this->selling_price,
            'ready_stock' => (string) $this->ready_stock,
            'pending_stock' => (string) $this->pending_stock,
            'minimum_stock' => (string) $this->minimum_stock,
            'image_path' => $this->image_path,
            'is_active' => $this->is_active,
            'created_at' => Timestamp::iso8601($this->created_at),
            'updated_at' => Timestamp::iso8601($this->updated_at),
        ];
    }
}
```

- [ ] **Step 6: Buat Form Requests**

Buat `backend/app/Http/Requests/Product/StoreProductRequest.php`:

```php
<?php

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'category_id' => ['required', 'string', 'exists:categories,id'],
            'sku' => ['required', 'string', 'max:80', Rule::unique('products', 'sku')],
            'name' => ['required', 'string', 'max:180'],
            'unit' => ['required', 'string', 'max:30'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'selling_price' => ['required', 'numeric', 'min:0'],
            'minimum_stock' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
```

Buat `backend/app/Http/Requests/Product/UpdateProductRequest.php`:

```php
<?php

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'category_id' => ['sometimes', 'string', 'exists:categories,id'],
            'sku' => [
                'sometimes',
                'string',
                'max:80',
                Rule::unique('products', 'sku')->ignore($this->route('product')),
            ],
            'name' => ['sometimes', 'string', 'max:180'],
            'unit' => ['sometimes', 'string', 'max:30'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'selling_price' => ['sometimes', 'numeric', 'min:0'],
            'minimum_stock' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
```

Buat `backend/app/Http/Requests/Product/UploadProductImageRequest.php`:

```php
<?php

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;

class UploadProductImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'image.mimes' => 'Format gambar tidak didukung. Hanya JPG, PNG, dan WebP yang diizinkan.',
            'image.max' => 'Ukuran gambar maksimal 2MB.',
        ];
    }
}
```

- [ ] **Step 7: Buat Query class `ProductQuery`**

Buat `backend/app/Queries/ProductQuery.php`:

```php
<?php

namespace App\Queries;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

class ProductQuery
{
    public function __construct(
        private Builder $query,
    ) {}

    public static function build(): self
    {
        return new self(Product::query()->with('category'));
    }

    public function filter(array $params): self
    {
        if (!empty($params['category_id'])) {
            $this->query->where('category_id', $params['category_id']);
        }

        if (!empty($params['search'])) {
            $search = $params['search'];
            $this->query->where(function (Builder $q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        if (isset($params['is_active'])) {
            $this->query->where('is_active', $params['is_active']);
        }

        if (!empty($params['stock_status'])) {
            match ($params['stock_status']) {
                'low' => $this->query->whereColumn('ready_stock', '<=', 'minimum_stock')
                                    ->where('minimum_stock', '>', 0),
                'out' => $this->query->where('ready_stock', 0),
                default => null,
            };
        }

        return $this;
    }

    public function sort(?string $sort): self
    {
        $allowlist = [
            'name' => 'name',
            '-name' => 'name:desc',
            'sku' => 'sku',
            '-sku' => 'sku:desc',
            'selling_price' => 'selling_price',
            '-selling_price' => 'selling_price:desc',
            'ready_stock' => 'ready_stock',
            '-ready_stock' => 'ready_stock:desc',
            'created_at' => 'created_at',
            '-created_at' => 'created_at:desc',
        ];

        if ($sort && isset($allowlist[$sort])) {
            $parts = explode(':', $allowlist[$sort]);
            $column = $parts[0];
            $direction = $parts[1] ?? 'asc';
            $this->query->orderBy($column, $direction);
        } else {
            $this->query->orderBy('name');
        }

        return $this;
    }

    public function paginate(int $perPage): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return $this->query->paginate($perPage);
    }
}
```

- [ ] **Step 8: Buat Controller `ProductController`**

Buat `backend/app/Http/Controllers/Api/V1/ProductController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Http\Requests\Product\UploadProductImageRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Queries\ProductQuery;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Product::class);

        $perPage = max(1, min(100, (int) $request->integer('per_page', 20)));

        $products = ProductQuery::build()
            ->filter($request->only(['category_id', 'search', 'is_active', 'stock_status']))
            ->sort($request->input('sort'))
            ->paginate($perPage);

        return response()->json([
            'data' => ProductResource::collection($products->items()),
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
                'current_page' => $products->currentPage(),
                'per_page' => $products->perPage(),
                'last_page' => $products->lastPage(),
                'total' => $products->total(),
            ],
        ]);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $this->authorize('create', Product::class);

        $product = Product::query()->create($request->validated());
        $product->load('category');

        return ApiResponse::success(new ProductResource($product), 201);
    }

    public function show(Product $product): JsonResponse
    {
        $product->load('category');

        return ApiResponse::success(new ProductResource($product));
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $this->authorize('update', $product);

        $product->fill($request->validated())->save();
        $product->load('category');

        return ApiResponse::success(new ProductResource($product->refresh()));
    }

    public function destroy(Product $product): JsonResponse
    {
        $this->authorize('delete', $product);

        // Produk yang sudah dipakai receipt item tidak boleh dihapus keras.
        // Relasi incomingReceiptItems akan dibuat di Phase 2.2.
        if (method_exists($product, 'incomingReceiptItems') && $product->incomingReceiptItems()->exists()) {
            return ApiResponse::error(
                'CONFLICT',
                'Produk ini sudah dipakai penerimaan dan tidak dapat dihapus. Nonaktifkan saja.',
                [],
                [],
                409,
            );
        }

        $product->delete();

        return ApiResponse::success(null);
    }

    public function uploadImage(UploadProductImageRequest $request, Product $product): JsonResponse
    {
        $this->authorize('update', $product);

        $file = $request->file('image');
        $path = $file->store('products', 'public');

        // Hapus gambar lama jika ada
        if ($product->image_path) {
            Storage::disk('public')->delete($product->image_path);
        }

        $product->update(['image_path' => $path]);

        return ApiResponse::success(new ProductResource($product->refresh()));
    }
}
```

- [ ] **Step 9: Daftarkan route**

Di `backend/routes/api.php`, tambahkan:

```php
use App\Http\Controllers\Api\V1\ProductController;

Route::get('/products', [ProductController::class, 'index'])->middleware('auth:sanctum');
Route::post('/products', [ProductController::class, 'store'])->middleware('auth:sanctum');
Route::get('/products/{product}', [ProductController::class, 'show'])->middleware('auth:sanctum');
Route::patch('/products/{product}', [ProductController::class, 'update'])->middleware('auth:sanctum');
Route::delete('/products/{product}', [ProductController::class, 'destroy'])->middleware('auth:sanctum');
Route::post('/products/{product}/image', [ProductController::class, 'uploadImage'])->middleware('auth:sanctum');
```

- [ ] **Step 10: Jalankan migration**

```bash
cd /d/DEV/0.AI/Cinnamon2/backend && php artisan migrate
```

- [ ] **Step 11: Jalankan pemeriksaan statis**

```bash
cd /d/DEV/0.AI/Cinnamon2/backend && ./vendor/bin/pint --test
```

- [ ] **Step 12: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add backend/database backend/app backend/routes && git commit -m "backend: add products CRUD with filtering, sorting, and image upload"
```

---

## Task 6: Seeder untuk Master Data Demo

**Files:**
- Modify: `backend/database/seeders/DatabaseSeeder.php`

**Interfaces:**
- Consumes: Models dari Task 1, 3, 4, 5.
- Produces: Data demo kategori, supplier, customer, dan produk untuk development.

- [ ] **Step 1: Update `DatabaseSeeder`**

Di `backend/database/seeders/DatabaseSeeder.php`, tambahkan seeding setelah user:

```php
use App\Models\Category;
use App\Models\Supplier;
use App\Models\Customer;
use App\Models\Product;

// Di method run():

// Categories
$rempahDaun = Category::query()->create([
    'name' => 'Rempah Daun',
    'slug' => 'rempah-daun',
    'description' => 'Rempah berbentuk daun',
]);

$rempahBiji = Category::query()->create([
    'name' => 'Rempah Biji',
    'slug' => 'rempah-biji',
    'description' => 'Rempah berbentuk biji',
]);

$rempahKulit = Category::query()->create([
    'name' => 'Rempah Kulit',
    'slug' => 'rempah-kulit',
    'description' => 'Rempah berbentuk kulit kayu',
]);

// Suppliers
$supplier1 = Supplier::query()->create([
    'name' => 'PT Rempah Nusantara',
    'address' => 'Jl. Rempah No. 1, Jakarta',
    'contact' => '081234567890',
    'email' => 'info@rempahnusantara.test',
]);

$supplier2 = Supplier::query()->create([
    'name' => 'CV Bumbu Segar',
    'address' => 'Jl. Bumbu No. 2, Bandung',
    'contact' => '082345678901',
    'email' => 'contact@bumbusegar.test',
]);

// Customers (opsional)
$customer1 = Customer::query()->create([
    'name' => 'Toko Bumbu Jaya',
    'address' => 'Jl. Pasar No. 10, Surabaya',
    'contact' => '083456789012',
    'email' => 'order@bumbujaya.test',
]);

// Products
Product::query()->create([
    'category_id' => $rempahDaun->id,
    'sku' => 'DAUN-SLAMETRI-001',
    'name' => 'Daun Salam Metri',
    'unit' => 'kg',
    'purchase_price' => 35000,
    'selling_price' => 50000,
    'minimum_stock' => 5,
]);

Product::query()->create([
    'category_id' => $rempahBiji->id,
    'sku' => 'PALA-001',
    'name' => 'Pala',
    'unit' => 'kg',
    'purchase_price' => 42000,
    'selling_price' => 65000,
    'minimum_stock' => 10,
]);

Product::query()->create([
    'category_id' => $rempahKulit->id,
    'sku' => 'KAYU-Manis-001',
    'name' => 'Kayu Manis',
    'unit' => 'kg',
    'purchase_price' => 55000,
    'selling_price' => 80000,
    'minimum_stock' => 8,
]);
```

- [ ] **Step 2: Jalankan seeder**

```bash
cd /d/DEV/0.AI/Cinnamon2/backend && php artisan db:seed
```

Expected: 3 kategori, 2 supplier, 1 customer, 3 produk ditambahkan.

- [ ] **Step 3: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add backend/database/seeders && git commit -m "backend: seed demo categories, suppliers, customers, and products"
```

---

## Task 7: Frontend Types dan API Client untuk Master Data

**Files:**
- Modify: `frontend/src/lib/api/types.ts`
- Modify: `frontend/src/lib/api/client.ts`

**Interfaces:**
- Consumes: API contract dari Task 1-5.
- Produces: TypeScript types untuk Category, Product, Supplier, Customer, dan functions untuk CRUD.

- [ ] **Step 1: Tambahkan types di `types.ts`**

Di `frontend/src/lib/api/types.ts`, tambahkan:

```ts
export interface Category {
  id: string
  name: string
  slug: string
  description: string | null
  is_active: boolean
  created_at: string
  updated_at: string
}

export interface Product {
  id: string
  category_id: string
  category: Category | null
  sku: string
  name: string
  unit: string
  purchase_price: string | null
  selling_price: string
  ready_stock: string
  pending_stock: string
  minimum_stock: string
  image_path: string | null
  is_active: boolean
  created_at: string
  updated_at: string
}

export interface Supplier {
  id: string
  name: string
  address: string | null
  contact: string | null
  email: string | null
  is_active: boolean
  created_at: string
  updated_at: string
}

export interface Customer {
  id: string
  name: string
  address: string | null
  contact: string | null
  email: string | null
  is_active: boolean
  created_at: string
  updated_at: string
}
```

- [ ] **Step 2: Tambahkan API client functions di `client.ts`**

Di `frontend/src/lib/api/client.ts`, tambahkan:

```ts
import type { Category, Customer, Product, Supplier } from './types'

// Categories
export async function fetchCategories(page = 1): Promise<PaginatedEnvelope<Category>> {
  const { data } = await apiClient.get<PaginatedEnvelope<Category>>('/api/v1/categories', {
    params: { page },
  })
  return data
}

export async function createCategory(payload: {
  name: string
  slug?: string
  description?: string
}): Promise<Category> {
  const { data } = await apiClient.post<Envelope<Category>>('/api/v1/categories', payload)
  return data.data
}

export async function updateCategory(
  id: string,
  payload: { name?: string; slug?: string; description?: string; is_active?: boolean },
): Promise<Category> {
  const { data } = await apiClient.patch<Envelope<Category>>(`/api/v1/categories/${id}`, payload)
  return data.data
}

export async function deleteCategory(id: string): Promise<void> {
  await apiClient.delete(`/api/v1/categories/${id}`)
}

// Suppliers
export async function fetchSuppliers(page = 1): Promise<PaginatedEnvelope<Supplier>> {
  const { data } = await apiClient.get<PaginatedEnvelope<Supplier>>('/api/v1/suppliers', {
    params: { page },
  })
  return data
}

export async function createSupplier(payload: {
  name: string
  address?: string
  contact?: string
  email?: string
}): Promise<Supplier> {
  const { data } = await apiClient.post<Envelope<Supplier>>('/api/v1/suppliers', payload)
  return data.data
}

export async function updateSupplier(
  id: string,
  payload: { name?: string; address?: string; contact?: string; email?: string; is_active?: boolean },
): Promise<Supplier> {
  const { data } = await apiClient.patch<Envelope<Supplier>>(`/api/v1/suppliers/${id}`, payload)
  return data.data
}

export async function deleteSupplier(id: string): Promise<void> {
  await apiClient.delete(`/api/v1/suppliers/${id}`)
}

// Customers
export async function fetchCustomers(page = 1): Promise<PaginatedEnvelope<Customer>> {
  const { data } = await apiClient.get<PaginatedEnvelope<Customer>>('/api/v1/customers', {
    params: { page },
  })
  return data
}

export async function createCustomer(payload: {
  name: string
  address?: string
  contact?: string
  email?: string
}): Promise<Customer> {
  const { data } = await apiClient.post<Envelope<Customer>>('/api/v1/customers', payload)
  return data.data
}

export async function updateCustomer(
  id: string,
  payload: { name?: string; address?: string; contact?: string; email?: string; is_active?: boolean },
): Promise<Customer> {
  const { data } = await apiClient.patch<Envelope<Customer>>(`/api/v1/customers/${id}`, payload)
  return data.data
}

export async function deleteCustomer(id: string): Promise<void> {
  await apiClient.delete(`/api/v1/customers/${id}`)
}

// Products
export async function fetchProducts(
  page = 1,
  filters?: { category_id?: string; search?: string; is_active?: boolean; sort?: string },
): Promise<PaginatedEnvelope<Product>> {
  const { data } = await apiClient.get<PaginatedEnvelope<Product>>('/api/v1/products', {
    params: { page, ...filters },
  })
  return data
}

export async function createProduct(payload: {
  category_id: string
  sku: string
  name: string
  unit: string
  purchase_price?: number
  selling_price: number
  minimum_stock?: number
}): Promise<Product> {
  const { data } = await apiClient.post<Envelope<Product>>('/api/v1/products', payload)
  return data.data
}

export async function updateProduct(
  id: string,
  payload: {
    category_id?: string
    sku?: string
    name?: string
    unit?: string
    purchase_price?: number
    selling_price?: number
    minimum_stock?: number
    is_active?: boolean
  },
): Promise<Product> {
  const { data } = await apiClient.patch<Envelope<Product>>(`/api/v1/products/${id}`, payload)
  return data.data
}

export async function deleteProduct(id: string): Promise<void> {
  await apiClient.delete(`/api/v1/products/${id}`)
}

export async function uploadProductImage(id: string, file: File): Promise<Product> {
  const formData = new FormData()
  formData.append('image', file)
  const { data } = await apiClient.post<Envelope<Product>>(`/api/v1/products/${id}/image`, formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data.data
}
```

- [ ] **Step 3: Jalankan pemeriksaan statis**

```bash
cd /d/DEV/0.AI/Cinnamon2/frontend && npx tsc -b && npm run lint
```

Expected: Tanpa error.

- [ ] **Step 4: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add frontend/src/lib/api && git commit -m "frontend: add API client functions for categories, suppliers, customers, and products"
```

---

## Task 8: Halaman Kategori (List dan Form)

**Files:**
- Create: `frontend/src/features/catalog/CategoryListPage.tsx`
- Create: `frontend/src/features/catalog/CategoryForm.tsx`
- Modify: `frontend/src/app/router.tsx`
- Modify: `frontend/src/app/navigation.ts`

**Interfaces:**
- Consumes: API client functions dari Task 7, `PageState` dari Phase 1.
- Produces: Halaman list kategori dengan pagination, search, dan form create/edit.

- [ ] **Step 1: Buat `CategoryListPage`**

Buat `frontend/src/features/catalog/CategoryListPage.tsx` dengan struktur:
- Query `fetchCategories` dengan TanStack Query
- Tabel dengan kolom: Nama, Slug, Deskripsi, Status, Aksi
- Tombol "Tambah Kategori" (Admin only)
- Tombol edit dan nonaktifkan/aktifkan per baris
- Pagination
- Empty state dan error state memakai `PageState`
- Modal form untuk create/edit (pakai `CategoryForm`)

- [ ] **Step 2: Buat `CategoryForm`**

Buat `frontend/src/features/catalog/CategoryForm.tsx`:
- Form dengan field: name, slug (optional, auto-generate dari name jika kosong), description
- Validasi client-side: name required
- Submit memanggil `createCategory` atau `updateCategory`
- On success: invalidate query dan tutup modal
- On error: tampilkan pesan error dari server

- [ ] **Step 3: Update router**

Di `frontend/src/app/router.tsx`, tambahkan route:

```tsx
import { CategoryListPage } from '../features/catalog/CategoryListPage'

// Di dalam children AppShell:
{ path: 'master/categories', element: <CategoryListPage /> },
```

- [ ] **Step 4: Update navigation**

Di `frontend/src/app/navigation.ts`, link `/master/categories` sudah ada dan sekarang mengarah ke halaman nyata, bukan 404.

- [ ] **Step 5: Jalankan pemeriksaan statis**

```bash
cd /d/DEV/0.AI/Cinnamon2/frontend && npx tsc -b && npm run lint
```

- [ ] **Step 6: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add frontend/src/features/catalog frontend/src/app && git commit -m "frontend: add category list and form pages"
```

---

## Task 9: Halaman Supplier, Customer, dan Produk

**Files:**
- Create: `frontend/src/features/catalog/SupplierListPage.tsx`
- Create: `frontend/src/features/catalog/SupplierForm.tsx`
- Create: `frontend/src/features/catalog/CustomerListPage.tsx`
- Create: `frontend/src/features/catalog/CustomerForm.tsx`
- Create: `frontend/src/features/catalog/ProductListPage.tsx`
- Create: `frontend/src/features/catalog/ProductForm.tsx`
- Modify: `frontend/src/app/router.tsx`

**Interfaces:**
- Consumes: API client functions dari Task 7, `PageState` dari Phase 1.
- Produces: Halaman list dan form untuk supplier, customer, dan produk.

- [ ] **Step 1: Buat halaman Supplier**

Buat `SupplierListPage.tsx` dan `SupplierForm.tsx` dengan pola yang sama seperti Category, tapi dengan field: name, address, contact, email.

- [ ] **Step 2: Buat halaman Customer**

Buat `CustomerListPage.tsx` dan `CustomerForm.tsx` dengan pola yang sama, field: name, address, contact, email.

- [ ] **Step 3: Buat halaman Produk**

Buat `ProductListPage.tsx` dan `ProductForm.tsx`:
- List dengan kolom: SKU, Nama, Kategori, Satuan, Harga Jual, Stok Ready, Stok Pending, Status
- Filter: category_id (dropdown), search (text), stock_status (low/out)
- Sort: name, sku, selling_price, ready_stock, created_at
- Form dengan field: category_id (dropdown), sku, name, unit, purchase_price, selling_price, minimum_stock
- Upload gambar (opsional) dengan preview
- Tombol nonaktifkan/aktifkan

- [ ] **Step 4: Update router**

Di `frontend/src/app/router.tsx`, tambahkan:

```tsx
import { SupplierListPage } from '../features/catalog/SupplierListPage'
import { CustomerListPage } from '../features/catalog/CustomerListPage'
import { ProductListPage } from '../features/catalog/ProductListPage'

// Di dalam children AppShell:
{ path: 'master/suppliers', element: <SupplierListPage /> },
{ path: 'master/customers', element: <CustomerListPage /> },
{ path: 'master/products', element: <ProductListPage /> },
```

- [ ] **Step 5: Jalankan pemeriksaan statis**

```bash
cd /d/DEV/0.AI/Cinnamon2/frontend && npx tsc -b && npm run lint && npm run build
```

- [ ] **Step 6: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add frontend/src/features/catalog frontend/src/app && git commit -m "frontend: add supplier, customer, and product pages"
```

---

## Task 10: Verifikasi Live End-to-End

**Files:** Tidak ada file baru. Task ini murni verifikasi.

- [ ] **Step 1: Jalankan server backend dan frontend**

```bash
# Terminal 1
cd /d/DEV/0.AI/Cinnamon2/backend && php artisan serve

# Terminal 2
cd /d/DEV/0.AI/Cinnamon2/frontend && npm run dev
```

- [ ] **Step 2: Verifikasi browser — Admin melihat kategori**

Buka http://localhost:5173, login sebagai admin, navigasi ke Master Data > Kategori.

Expected: Tabel menampilkan 3 kategori seed, pagination berfungsi.

- [ ] **Step 3: Verifikasi browser — Admin membuat kategori**

Klik "Tambah Kategori", isi form, submit.

Expected: Modal tertutup, kategori baru muncul di tabel.

- [ ] **Step 4: Verifikasi browser — Karyawan tidak bisa membuat kategori**

Logout, login sebagai karyawan, navigasi ke Master Data > Kategori.

Expected: Tombol "Tambah Kategori" tidak muncul.

- [ ] **Step 5: Verifikasi browser — Admin melihat produk dengan filter**

Navigasi ke Master Data > Produk, pilih filter kategori "Rempah Biji".

Expected: Hanya produk "Pala" yang muncul.

- [ ] **Step 6: Verifikasi browser — Admin upload gambar produk**

Buka detail produk "Pala", klik upload gambar, pilih file JPG.

Expected: Gambar muncul di preview dan `image_path` terisi.

- [ ] **Step 7: Verifikasi browser — Admin menghapus kategori yang belum dipakai**

Buat kategori baru "Test", lalu hapus.

Expected: Kategori terhapus dari tabel.

- [ ] **Step 8: Verifikasi browser — Admin mencoba menghapus kategori yang sudah dipakai**

Coba hapus kategori "Rempah Daun" (sudah dipakai produk).

Expected: Error message "Kategori ini sudah dipakai produk dan tidak dapat dihapus. Nonaktifkan saja."

- [ ] **Step 9: Matikan server**

```bash
pkill -f "php artisan serve"
pkill -f "vite"
```

- [ ] **Step 10: Jalankan suite regresi**

```bash
cd /d/DEV/0.AI/Cinnamon2/backend && php artisan test
cd /d/DEV/0.AI/Cinnamon2/frontend && npm test
```

Expected: 47 backend tests passed, 22 frontend tests passed.

- [ ] **Step 11: Commit final**

Tidak ada commit karena tidak ada perubahan kode.

---

## Task 11: Dokumentasi Phase 2.1 Selesai

**Files:**
- Create: `Docs/phase-2-1-done.md`
- Modify: `Docs/roadmap.md`

**Interfaces:**
- Consumes: Hasil verifikasi dari Task 10.
- Produces: Dokumen ringkasan hasil Phase 2.1 dan update roadmap.

- [ ] **Step 1: Buat `phase-2-1-done.md`**

Buat `Docs/phase-2-1-done.md` dengan struktur:
- Status: complete
- Yang dibangun: tabel (area | hasil)
- Perubahan yang terlihat pengguna
- Verifikasi yang dijalankan (API + browser)
- Keputusan yang diambil saat pengerjaan
- Belum dikerjakan (jika ada)

- [ ] **Step 2: Update `roadmap.md`**

Di `Docs/roadmap.md`, tambahkan status selesai di Phase 2.1:

```markdown
**Status: selesai (2026-10-22).** Ringkasan hasil dan verifikasi: `Docs/phase-2-1-done.md`.
```

- [ ] **Step 3: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add Docs && git commit -m "docs: record Phase 2.1 completion and verification results"
```

---

## Catatan Implementasi

**Urutan task:** Task 1-6 backend dulu (migration, model, policy, controller, seeder), lalu Task 7 frontend types/client, Task 8-9 frontend pages, Task 10 verifikasi end-to-end, Task 11 dokumentasi.

**Ketergantungan:**
- Task 2 (Suppliers) dan Task 3 (Customers) bisa dikerjakan paralel setelah Task 1 (Categories) selesai.
- Task 5 (Products) butuh Task 1 selesai karena ada FK ke categories.
- Task 7 (Frontend types) butuh semua backend task selesai.
- Task 8-9 (Frontend pages) butuh Task 7 selesai.
- Task 10 (Verifikasi) butuh semua task sebelumnya selesai.

**Pola yang dipakai:**
- Backend mengikuti pola Phase 1: Form Request untuk validasi, Policy untuk otorisasi, Controller untuk orkestrasi, Resource untuk serialisasi.
- Frontend mengikuti pola Phase 1: TanStack Query untuk cache, React Router untuk navigasi, PageState untuk loading/empty/error.
- DELETE hanya menghapus baris yang belum direferensikan; data yang sudah dipakai dikembalikan 409.

**Yang tidak dikerjakan di Phase 2.1:**
- Relasi `incoming_receipts` ke `suppliers` dan `incoming_receipt_items` ke `products` akan dibuat di Phase 2.2.
- Relasi `sales` ke `customers` dan `sale_items` ke `products` akan dibuat di Phase 3.1.
- Upload gambar produk sudah ada, tapi validasi MIME dan storage policy sudah cukup untuk MVP.
- Export CSV/XLSX ditunda ke Phase 3.2.
