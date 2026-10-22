# Phase 2.3 — Stock Overview dan Koreksi Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Memberi Admin satu tempat untuk membaca saldo stok dengan jejak batch dan movement, dan satu cara yang bisa diaudit untuk mengoreksi selisih stok tanpa pernah mengedit histori.

**Architecture:** Backend Laravel 13 tetap sumber kebenaran. Tiga endpoint baca (`/inventory/stock`, `/inventory/movements`, `/inventory/adjustments`) memakai kelas `Query` terpisah, dan angka rekonsiliasi diambil dari subquery agregat di dalam satu statement SQL — bukan N+1. Koreksi stok adalah dokumen: `stock_adjustments` (draft → posted → cancelled) dengan `stock_adjustment_items` yang **wajib menunjuk satu batch**, sehingga `SUM(stock_batches.quantity_remaining)` selalu sama dengan `products.ready_stock`. Posting koreksi menulis satu `stock_movements` dan satu `ledger_entries` per item dalam satu transaksi, mengunci adjustment, produk, dan batch.

**Tech Stack:**
- Backend: Laravel 13, PHP 8.5.11, MySQL 8 (`cinnamon_v2`), UUID v7 primary keys, `bcmath`
- Frontend: Vite 8.3, React 19, TypeScript 6, React Router DOM 7, TanStack Query 5, Axios
- Testing: Suite yang ada (47 backend, 22 frontend) sebagai jaring regresi; fitur baru diverifikasi dengan menjalankan aplikasi (live API + browser)

**Spec:** `Docs/PRD.md` §4, §6.5, §8 (Inventory), §9, §11; `Docs/API.md` §2, §3, §4.1, §4.3, §5.4, §6, §7, §8, §10; `Docs/Database.md` §4 (`products`, `stock_batches`, `stock_movements`, `stock_adjustments`, `stock_adjustment_items`, `ledger_entries`, `audit_logs`), §5, §6, §7, §10; `Docs/Architecture.md` §2, §4, §5, §6, §7, §8, §9, §10, §14; `Docs/Design.md` §3, §4, §5, §6, §8, §9, §10; `Docs/roadmap.md` "Phase 2.3 — Stock overview dan koreksi"; `Docs/AGENTS.md` §3, §4, §5, §6, §7, §8; `Docs/decisions.md` §1, §4, §5, §6, §8, §9, §10, §11–§19.

**Ketergantungan keras:** Plan ini mengasumsikan **Phase 2.1 dan Phase 2.2 sudah dieksekusi**. Phase 2.1 menyediakan `products`, `suppliers`, dan `GET /products/{product}`; Phase 2.2 menyediakan `stock_batches`, `stock_movements`, `ledger_entries`, `audit_logs`, `idempotency_keys`, `App\Support\Idempotency`, `App\Exceptions\BusinessRuleException`, `App\Support\RequestId`, `App\Enums\MovementType`, dan komponen UI `StatusBadge`, `ConfirmDialog`, `Field`. Migration Phase 2.3 memakai `000012` dan `000013` karena `000001`–`000011` dipakai Phase 2.1 dan 2.2. Kalau Phase 2.1 atau 2.2 belum dijalankan, kerjakan plan-nya lebih dulu.

---

## Global Constraints

- **Tidak ada file test baru.** Suite yang ada tetap jalan sebagai jaring regresi sebelum commit, tapi tidak diperluas — `Docs/decisions.md` §10. Setiap task diverifikasi dengan menjalankan aplikasinya.
- Prefix API `/api/v1`. Envelope sukses `{ "data": …, "meta": { "request_id": "…" } }`; envelope error `{ "error": { "code", "message", "fields", "details" }, "meta": { "request_id" } }`.
- Mapping status error (dipin dari `Docs/API.md` §7): `VALIDATION_ERROR` → 422, `UNAUTHENTICATED` → 401, `FORBIDDEN` → 403, `NOT_FOUND` → 404, `BAD_REQUEST` → 400, `RATE_LIMITED` → 429, `SERVER_ERROR` → 500. Kode khusus fase ini: `DOCUMENT_ALREADY_POSTED` → 409, `IDEMPOTENCY_CONFLICT` → 409, `CONFLICT` → 409.
- **Kode produksi backend ditulis langsung di tempatnya** — `backend/app/…`, `backend/routes/`, `backend/database/`, `backend/bootstrap/`, `backend/app/Console/Commands/`. Kode test hanya di `backend/tests/`.
- UUID v7 via `HasUuids` untuk semua primary key **dan** untuk kolom polimorfik (`source_id`, `resource_id`).
- Timestamp diserialisasi lewat `App\Support\Timestamp::iso8601()` (Asia/Jakarta, `+07:00`).
- `lang/id/validation.php` + `APP_LOCALE=id` — pesan validasi tampil apa adanya di UI.
- Money 2 desimal, quantity 3 desimal, keduanya diserialisasi sebagai **string**. Hitungannya memakai `bcmath`, bukan float.
- Dua role saja: `admin` dan `karyawan`. **Seluruh endpoint `/inventory/*` adalah Admin-only** — `Docs/API.md` §4.3 dan `Docs/PRD.md` §4 (Karyawan tidak membuka laporan administratif).
- Bahasa antarmuka: Bahasa Indonesia.
- Extension PHP `intl` **tidak terpasang** — jangan pakai `Illuminate\Support\Number::format()`, pakai `number_format()`.
- Frontend harus lolos `tsc -b` dan ESLint flat config yang sudah ada **tanpa menambah rule**.
- Tidak ada dependensi baru di luar Tech Stack.
- Database `cinnamon` berisi fixture TA legacy. **Tidak boleh** dipakai dan tidak boleh dihapus.
- **Tidak ada hard delete pada dokumen koreksi.** Status `cancelled` adalah akhir alur; koreksi yang salah dibalik dengan membuat dokumen koreksi baru, bukan dengan mengedit atau menghapus.
- `Idempotency-Key` **wajib** pada `POST /inventory/adjustments/{adjustment}/post`. Tanpa header → `400 BAD_REQUEST`.
- Key yang sama dengan payload berbeda → `409 IDEMPOTENCY_CONFLICT`. Key yang sama dengan payload sama → response asli diputar ulang tanpa efek samping.
- `stock_movements` bersifat append-only. Koreksi menambah baris baru; tidak pernah mengubah atau menghapus baris lama.
- Angka stok dan uang dihitung ulang di server. Nilai yang dikirim browser (selisih, total) tidak otoritatif.

---

## Deviasi dari dokumen acuan (perlu dibaca sebelum mulai)

Enam titik di mana dokumen acuan tidak lengkap atau tidak konsisten. Semuanya diselesaikan di dalam plan ini dengan alasan tertulis; tidak ada yang mengubah aturan stok atau hak akses tanpa persetujuan eksplisit (lihat blok "Keputusan yang sudah dikonfirmasi" di bawah).

| # | Konflik | Penyelesaian di plan ini | Alasan |
|---|---|---|---|
| 1 | `Docs/API.md` §5.4 mengirim `adjustment_date`, tetapi `Docs/Database.md` §4 `stock_adjustments` tidak punya kolom tanggal. | Tabel `stock_adjustments` mendapat kolom `adjustment_date` DATE. | Tanggal opname adalah bagian dari dokumen koreksi — tanpa itu, "koreksi periode kapan" tidak bisa dijawab, dan `Docs/Database.md` §7 mengharuskan index `(status, adjustment_date)` untuk Adjustment, sama seperti `incoming_receipts`. |
| 2 | `Docs/Database.md` §4 `stock_adjustment_items` tidak punya kolom batch, sedangkan keputusan pemilik produk mengharuskan koreksi menunjuk batch tertentu. | `stock_adjustment_items.stock_batch_id` **NOT NULL**, FK ke `stock_batches.id`. | Tanpa kolom batch, koreksi hanya mengubah `products.ready_stock` dan `SUM(stock_batches.quantity_remaining)` tidak lagi sama dengan ready stock — rekonsiliasi yang diminta `Docs/roadmap.md` Phase 2.3 mustahil. |
| 3 | `Docs/API.md` §5.4 mengizinkan `stock_bucket` apa pun; keputusan pemilik produk membatasi koreksi ke bucket `ready`. | `stock_bucket` hanya menerima `ready`. Nilai lain dijawab 422 dengan pesan Bahasa Indonesia. | Stok pending adalah reservasi yang menempel pada item penerimaan (`Docs/decisions.md` §15). Mengoreksinya langsung membuat proyeksi pending tidak sinkron dengan dokumennya. |
| 4 | `Docs/decisions.md` §6 memuat dua hal yang tidak bisa berlaku bersamaan: `balance = SUM(debit) − SUM(credit)` (debit = kas masuk, kredit = kas keluar) **dan** `balance == batch_qty_remaining × unit_cost + opening_kas`. | §6 ditulis ulang. Definisi debit/kredit **tidak berubah**; yang dibetulkan adalah rumus rekonsiluasinya menjadi `saldo_ledger + nilai_stok − 2 × nilai_koreksi = laba_kumulatif + kas_awal`. | Dengan definisi debit/kredit yang berlaku, saldo ledger adalah posisi kas dan nilai stok adalah nilai gudang — keduanya tidak pernah saling menggantikan. Rumus lama tidak mungkin true, sehingga harus dibetulkan agar bisa dipakai sebagai invarian. |
| 5 | `Docs/API.md` §4.3 menyebut filter `product_id, movement_type, date_from, date_to, period, search, page, per_page` untuk movement. | Filter itu semua dipakai, **plus** `source_type` (untuk menyaring "hanya dari penerimaan" atau "hanya dari koreksi"). | `source_type` adalah satu kolom yang sudah ada, dan menelusuri satu sumber dokumen adalah kebutuhan utama saat memeriksa audit. |
| 6 | `Docs/API.md` §4.3 tidak menyebut angka rekonsiliasi pada `GET /inventory/stock`, padahal `Docs/roadmap.md` Phase 2.3 meminta "Query rekonsiliasi yang melaporkan selisih antara projection, batch, dan movement". | `GET /inventory/stock` mengembalikan kolom `batch_remaining`, `movement_net`, `pending_weight`, dan tiga selisihnya, plus `is_balanced`. | Rekonsiliasi harus terlihat oleh orang yang menjalankan aplikasi, bukan hanya di terminal. |

### Keputusan yang sudah dikonfirmasi (hasil brainstorming bersama pengguna)

1. **Koreksi menunjuk batch secara eksplisit.** Kalau Admin mengurangi 0,5 kg, dia memilih batch mana yang kurang; `quantity_remaining` batch itu yang dikurangi. Untuk penambahan, Admin juga memilih batch tujuan — tidak ada batch yang dibuat otomatis, dan tidak ada spreading ke beberapa batch.
2. **Posting koreksi menulis entri ledger.** Koreksi masuk = `debit`, koreksi keluar = `credit` (penandaan yang dipilih pemilik produk; lihat Deviasi #4 untuk konsekuensinya pada rumus rekonsiliasi).
3. **Hasil rekonsiliasi tampil di Stock Overview dan di `php artisan inventory:reconcile`.** Kolom selisih di UI; command untuk pemeriksaan menyeluruh.
4. **Hanya bucket `ready` boleh dikoreksi.**
5. **Empat keputusan lanjutan (dipilih dari rekomendasi):** penambahan koreksi juga menunjuk batch yang ada (bukan membuat batch baru); `quantity_received` batch ikut naik saat penambahan agar invarian `quantity_remaining ≤ quantity_received` tetap berlaku; Admin membuat dan mem-posting koreksinya sendiri tanpa pemisahan two-eyes; koreksi yang sudah diposting tidak bisa dibatalkan — pembalikannya lewat dokumen koreksi baru.

---

## Review Focus

Lima kondisi yang paling mungkin menggigit pengguna nyata. Masing-masing harus dibuktikan oleh langkah verifikasi di task yang memiliki kodenya.

1. **Koreksi diposting dua kali (klik ganda atau retry jaringan)** → stok ready berubah **satu kali**. Bukti: Task 5 (idempotency key sama) dan Task 5 (`lockForUpdate` pada adjustment plus pengecekan status di dalam transaksi).
2. **Karyawan membuka ringkasan stok atau riwayat movement** → `403 FORBIDDEN` dengan `meta.request_id`, bukan 401 dan bukan 500. Bukti: Task 3 (Policy) dan Task 8.
3. **Koreksi lebih besar dari sisa batch** → `422` dengan pesan yang menyebut batch mana dan berapa yang tersisa, dan **tidak ada** perubahan sebagian: stok, batch, movement, ledger tetap seperti sebelum. Bukti: Task 5 (cek di dalam transaksi sebelum writes).
4. **Rekonsiliasi melaporkan selisih, tidak memperbaikinya** → ketika angka batch dan proyeksi berbeda, `GET /inventory/stock` menampilkan selisih dan `inventory:reconcile` mencetak detailnya, tapi tidak ada baris yang ditulis. Bukti: Task 7.
5. **Koreksi lama tidak pernah diedit** → correcting adjustment yang sudah `posted` dijawab `409 DOCUMENT_ALREADY_POSTED`; pembalikannya hanya lewat dokumen baru. Bukti: Task 5 dan Task 8.

---

## Peta File

```
backend/
  app/
    Actions/Inventory/
      PostAdjustment.php         (BARU)  draft -> posted, movement + ledger + audit
      CancelAdjustment.php       (BARU)  draft -> cancelled
      UpdateAdjustment.php       (BARU)  ubah draft
    Console/Commands/
      ReconcileInventory.php     (BARU)  php artisan inventory:reconcile
    Domain/Inventory/
      AdjustmentCalculator.php   (BARU)  snapshot system/physical/difference, nilai batch
      AdjustmentNumbers.php      (BARU)  ADJ-YYYY-MM-NNN
      StockReconciler.php        (BARU)  bandingkan proyeksi vs batch vs movement
    Enums/
      AdjustmentStatus.php       (BARU)  draft, posted, cancelled
    Http/Controllers/Api/V1/
      StockOverviewController.php (BARU)
      MovementController.php      (BARU)
      StockAdjustmentController.php (BARU)
    Http/Requests/Inventory/
      StoreStockAdjustmentRequest.php   (BARU)
      UpdateStockAdjustmentRequest.php  (BARU)
    Http/Resources/
      StockOverviewResource.php   (BARU)
      StockMovementResource.php   (BARU)
      StockAdjustmentResource.php  (BARU)
      StockAdjustmentItemResource.php (BARU)
    Models/
      StockAdjustment.php         (BARU)
      StockAdjustmentItem.php     (BARU)
      Product.php                 (MODIFIKASI) + relasi batches()
    Policies/
      StockAdjustmentPolicy.php   (BARU)
    Queries/
      StockOverviewQuery.php      (BARU)
      MovementQuery.php           (BARU)
      StockAdjustmentQuery.php    (BARU)
  database/
    migrations/
      2026_10_22_000012_create_stock_adjustments_table.php      (BARU)
      2026_10_22_000013_create_stock_adjustment_items_table.php  (BARU)
  routes/
    api.php                      (MODIFIKASI)

frontend/
  src/
    app/
      router.tsx                 (MODIFIKASI)
    components/
      ui/
        StatusBadge.tsx          (MODIFIKASI) + status 'posted'
    features/
      inventory/
        StockOverviewPage.tsx     (BARU)
        MovementHistoryPage.tsx   (BARU)
        AdjustmentListPage.tsx    (BARU)
        AdjustmentFormPage.tsx    (BARU)
        AdjustmentDetailPage.tsx  (BARU)
    lib/
      api/
        client.ts                 (MODIFIKASI)
        types.ts                  (MODIFIKASI)
```

---

## Task 1: Migration dan Model untuk Koreksi Stok

**Files:**
- Create: `backend/database/migrations/2026_10_22_000012_create_stock_adjustments_table.php`
- Create: `backend/database/migrations/2026_10_22_000013_create_stock_adjustment_items_table.php`
- Create: `backend/app/Enums/AdjustmentStatus.php`
- Create: `backend/app/Models/StockAdjustment.php`
- Create: `backend/app/Models/StockAdjustmentItem.php`
- Modify: `backend/app/Models/Product.php` — tambah relasi `batches()`
- Modify: `backend/app/Models/StockBatch.php` — tambah relasi `adjustmentItems()`

**Interfaces:**
- Consumes: `App\Models\Product`, `App\Models\User`, `App\Models\StockBatch` (Phase 2.1 / 2.2).
- Produces:
  - Tabel `stock_adjustments`: `id` (uuid PK), `adjustment_number` (varchar 40 unique), `status` (enum `draft,posted,cancelled`, default `draft`), `adjustment_date` (date), `reason` (varchar 255), `created_by` (uuid FK → `users.id`), `posted_by` (uuid FK → `users.id` nullable), `posted_at` (datetime nullable), timestamps. Index `(status, adjustment_date)` dan `created_by`.
  - Tabel `stock_adjustment_items`: `id` (uuid PK), `stock_adjustment_id` (uuid FK → `stock_adjustments.id`, cascade delete), `product_id` (uuid FK → `products.id`), `stock_batch_id` (uuid FK → `stock_batches.id`), `system_quantity` (decimal 15,3), `physical_quantity` (decimal 15,3), `difference` (decimal 15,3), `note` (text nullable), timestamps. Index `stock_adjustment_id` dan `(product_id, stock_batch_id)`.
  - `App\Enums\AdjustmentStatus`: backed string enum — `Draft='draft'`, `Posted='posted'`, `Cancelled='cancelled'`.
  - `App\Models\StockAdjustment` — fillable `['adjustment_number','status','adjustment_date','reason','created_by','posted_by','posted_at']`; relasi `items()` (HasMany), `creator()` (BelongsTo), `poster()` (BelongsTo); cast `status` → `AdjustmentStatus`, `adjustment_date` → `date`, `posted_at` → `datetime`.
  - `App\Models\StockAdjustmentItem` — fillable `['stock_adjustment_id','product_id','stock_batch_id','system_quantity','physical_quantity','difference','note']`; relasi `adjustment()`, `product()`, `batch()` (BelongsTo `StockBatch` via `stock_batch_id`); cast quantity → `decimal:3`.
  - `App\Models\Product` — tambah `batches(): HasMany` ke `StockBatch` via `product_id`.
  - `App\Models\StockBatch` — tambah `adjustmentItems(): HasMany` ke `StockAdjustmentItem` via `stock_batch_id`.

- [ ] **Step 1: Tuliskan acceptance check**

| # | Skenario | Harap |
|---|---|---|
| A | Migration dijalankan pada `cinnamon_v2` | 2 tabel baru dibuat, rollback mengembalikan ke 18 tabel |
| B | `StockAdjustment` dibuat | `id` berupa UUID v7, `status` default `draft` |
| C | `StockAdjustment` dengan `status` di luar enum | error saat simpan, bukan diam-diam diterima |
| D | `StockAdjustmentItem` dengan `stock_batch_id` menunjuk batch produk lain | foreign key tetap lolos (dia FK ke batch), tetapi **validasi di Form Request** menolak (Task 3) |
| E | `decimal` cast pada `difference` | mengembalikan string, bukan float |
| F | `batches()` milik `Product` | mengembalikan `HasMany` ke `StockBatch` |

**Why:** tidak ada file test baru (Global Constraints), jadi daftar inilah acuannya. Diverifikasi live di Task 8.

- [ ] **Step 2: Buat enum `AdjustmentStatus`**

Buat `backend/app/Enums/AdjustmentStatus.php`:

```php
<?php

namespace App\Enums;

enum AdjustmentStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Cancelled = 'cancelled';
}
```

- [ ] **Step 3: Buat migration `stock_adjustments`**

Buat `backend/database/migrations/2026_10_22_000012_create_stock_adjustments_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('adjustment_number', 40)->unique();
            $table->enum('status', ['draft', 'posted', 'cancelled'])->default('draft');
            $table->date('adjustment_date');
            $table->string('reason', 255);
            $table->foreignUuid('created_by')->constrained('users');
            $table->foreignUuid('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'adjustment_date']);
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustments');
    }
};
```

- [ ] **Step 4: Buat migration `stock_adjustment_items`**

Buat `backend/database/migrations/2026_10_22_000013_create_stock_adjustment_items_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustment_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('stock_adjustment_id')
                ->constrained('stock_adjustments')
                ->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('products')->restrictOnDelete();
            // Every correction names the batch it hits, so the batch total
            // keeps matching the ready-stock projection. See Docs/decisions.md §20.
            $table->foreignUuid('stock_batch_id')->constrained('stock_batches')->restrictOnDelete();
            $table->decimal('system_quantity', 15, 3);
            $table->decimal('physical_quantity', 15, 3);
            $table->decimal('difference', 15, 3);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index('stock_adjustment_id');
            $table->index(['product_id', 'stock_batch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustment_items');
    }
};
```

- [ ] **Step 5: Buat model `StockAdjustment`**

Buat `backend/app/Models/StockAdjustment.php`:

```php
<?php

namespace App\Models;

use App\Enums\AdjustmentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'adjustment_number',
    'status',
    'adjustment_date',
    'reason',
    'created_by',
    'posted_by',
    'posted_at',
])]
class StockAdjustment extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AdjustmentStatus::class,
            'adjustment_date' => 'date',
            'posted_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockAdjustmentItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
```

- [ ] **Step 6: Buat model `StockAdjustmentItem`**

Buat `backend/app/Models/StockAdjustmentItem.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'stock_adjustment_id',
    'product_id',
    'stock_batch_id',
    'system_quantity',
    'physical_quantity',
    'difference',
    'note',
])]
class StockAdjustmentItem extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'system_quantity' => 'decimal:3',
            'physical_quantity' => 'decimal:3',
            'difference' => 'decimal:3',
        ];
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(StockAdjustment::class, 'stock_adjustment_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'stock_batch_id');
    }
}
```

- [ ] **Step 7: Tambahkan relasi pada `Product` dan `StockBatch`**

Di `backend/app/Models/Product.php`, tambahkan import `HasMany` dan method:

```php
    public function batches(): HasMany
    {
        return $this->hasMany(StockBatch::class);
    }
```

Di `backend/app/Models/StockBatch.php`, tambahkan import `HasMany` dan method:

```php
    public function adjustmentItems(): HasMany
    {
        return $this->hasMany(StockAdjustmentItem::class);
    }
```

- [ ] **Step 8: Jalankan migration dan pemeriksaan statis**

```bash
cd /d/DEV/0.AI/Cinnamon2/backend && php artisan migrate && ./vendor/bin/pint --test
```

Expected: 2 tabel baru dibuat; Pint exit 0.

- [ ] **Step 9: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add backend/database backend/app && git commit -m "backend: add stock adjustment documents and items"
```

---

## Task 2: Domain — Perhitungan Koreksi, Penomoran, dan Rekonsiliasi

**Files:**
- Create: `backend/app/Domain/Inventory/AdjustmentCalculator.php`
- Create: `backend/app/Domain/Inventory/AdjustmentNumbers.php`
- Create: `backend/app/Domain/Inventory/StockReconciler.php`

**Interfaces:**
- Consumes: `App\Models\Product`, `App\Models\StockBatch`.
- Produces:
  - `App\Domain\Inventory\AdjustmentCalculator`:
    - `public static function snapshot(string $readyStock, string $difference): array{system_quantity: string, physical_quantity: string, difference: string}` — `system_quantity` = stok ready produk saat draft dibuat; `physical_quantity` = `system + difference`; `difference` = selisih 3 desimal. Semua string.
    - `public static function value(StockBatch $batch, string $difference): string` — `|difference| × unit_cost` batch, 2 desimal. Ini nilai yang masuk ke ledger.
  - `App\Domain\Inventory\AdjustmentNumbers`:
    - `public static function next(DateTimeInterface $date): string` — `ADJ-YYYY-MM-NNN`, `NNN` mulai dari `001` pada bulan berjalan, nol-pad 3 digit.
  - `App\Domain\Inventory\StockReconciler`:
    - `public static function check(string $readyStock, string $pendingStock, string $batchRemaining, string $movementNet, string $pendingWeight): array` — mengembalikan `difference_batch`, `difference_movement`, `difference_pending`, dan `is_balanced` (boolean).

- [ ] **Step 1: Buat `AdjustmentCalculator`**

Buat `backend/app/Domain/Inventory/AdjustmentCalculator.php`:

```php
<?php

namespace App\Domain\Inventory;

use App\Models\StockBatch;

/**
 * The arithmetic behind a stock correction.
 *
 * The browser sends the difference it counted; the server derives the system
 * quantity and the physical quantity from the store's own books, so a
 * correction can never claim a system quantity the store did not have.
 */
class AdjustmentCalculator
{
    /**
     * The snapshot taken when the draft is written.
     *
     * @return array{system_quantity: string, physical_quantity: string, difference: string}
     */
    public static function snapshot(string $readyStock, string $difference): array
    {
        $system = bcadd($readyStock, '0', 3);
        $delta = bcadd($difference, '0', 3);
        $physical = bcadd($system, $delta, 3);

        return [
            'system_quantity' => $system,
            'physical_quantity' => $physical,
            'difference' => $delta,
        ];
    }

    /**
     * The value of the change, priced at the batch's own cost.
     *
     * A correction is worth whatever the goods in that batch are worth — the
     * batch carries the unit cost the store paid, and using any other price
     * would make the ledger and the stock disagree.
     */
    public static function value(StockBatch $batch, string $difference): string
    {
        return bcmul(bcabs(bcadd($difference, '0', 3)), (string) $batch->unit_cost, 2);
    }
}
```

- [ ] **Step 2: Buat `AdjustmentNumbers`**

Buat `backend/app/Domain/Inventory/AdjustmentNumbers.php`:

```php
<?php

namespace App\Domain\Inventory;

use App\Models\StockAdjustment;
use DateTimeInterface;

/**
 * Human-readable correction numbers.
 *
 * The number is assigned when the draft is created, so an Admin can quote it
 * while the correction is still being reviewed — same rule as receipts, per
 * Docs/decisions.md §14.
 */
class AdjustmentNumbers
{
    /**
     * `ADJ-2026-10-001` — correction number for the given month.
     */
    public static function next(DateTimeInterface $date): string
    {
        $prefix = 'ADJ-'.$date->format('Y-m').'-';

        $last = StockAdjustment::query()
            ->where('adjustment_number', 'like', $prefix.'%')
            ->orderByDesc('adjustment_number')
            ->value('adjustment_number');

        $sequence = $last ? ((int) substr((string) $last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);
    }
}
```

- [ ] **Step 3: Buat `StockReconciler`**

Buat `backend/app/Domain/Inventory/StockReconciler.php`:

```php
<?php

namespace App\Domain\Inventory;

/**
 * Compares the store's own books with each other.
 *
 * The ready-stock projection, the batch balances, and the movement history
 * are three independent records of the same thing. When they agree, the books
 * are consistent; when they do not, something is wrong and the difference is
 * reported — never silently repaired, per Docs/roadmap.md Phase 2.3.
 */
class StockReconciler
{
    /**
     * @return array{
     *     difference_batch: string,
     *     difference_movement: string,
     *     difference_pending: string,
     *     is_balanced: bool
     * }
     */
    public static function check(
        string $readyStock,
        string $pendingStock,
        string $batchRemaining,
        string $movementNet,
        string $pendingWeight,
    ): array {
        $differenceBatch = bcsub(bcadd($readyStock, '0', 3), bcadd($batchRemaining, '0', 3), 3);
        $differenceMovement = bcsub(bcadd($readyStock, '0', 3), bcadd($movementNet, '0', 3), 3);
        $differencePending = bcsub(bcadd($pendingStock, '0', 3), bcadd($pendingWeight, '0', 3), 3);

        return [
            'difference_batch' => $differenceBatch,
            'difference_movement' => $differenceMovement,
            'difference_pending' => $differencePending,
            'is_balanced' => bccomp($differenceBatch, '0', 3) === 0
                && bccomp($differenceMovement, '0', 3) === 0
                && bccomp($differencePending, '0', 3) === 0,
        ];
    }
}
```

- [ ] **Step 4: Jalankan pemeriksaan statis**

```bash
cd /d/DEV/0.AI/Cinnamon2/backend && ./vendor/bin/pint --test
```

Expected: exit 0 tanpa offense.

- [ ] **Step 5: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add backend/app && git commit -m "backend: add adjustment arithmetic, numbering, and stock reconciliation"
```

---

## Task 3: Policy, Form Request, dan Resource Koreksi

**Files:**
- Create: `backend/app/Policies/StockAdjustmentPolicy.php`
- Create: `backend/app/Http/Requests/Inventory/StoreStockAdjustmentRequest.php`
- Create: `backend/app/Http/Requests/Inventory/UpdateStockAdjustmentRequest.php`
- Create: `backend/app/Http/Resources/StockAdjustmentResource.php`
- Create: `backend/app/Http/Resources/StockAdjustmentItemResource.php`
- Create: `backend/app/Http/Resources/StockMovementResource.php`
- Create: `backend/app/Http/Resources/StockOverviewResource.php`

**Interfaces:**
- Consumes: `App\Models\StockAdjustment`, `App\Models\StockAdjustmentItem`, `App\Models\StockMovement`, `App\Models\Product`, `App\Models\StockBatch`, `App\Models\User`.
- Produces:
  - `App\Policies\StockAdjustmentPolicy`:
    - `viewAny(User $actor): bool` → `$actor->hasRole('admin')`.
    - `view(User $actor, StockAdjustment $adjustment): bool` → admin.
    - `create(User $actor): bool` → admin.
    - `update(User $actor, StockAdjustment $adjustment): bool` → admin **dan** status masih `draft`.
    - `post(User $actor, StockAdjustment $adjustment): bool` → admin **dan** status masih `draft`.
    - `cancel(User $actor, StockAdjustment $adjustment): bool` → admin **dan** status masih `draft`.
  - `StoreStockAdjustmentRequest` rules:
    - `adjustment_date` → `required`, `date_format:Y-m-d`, `before_or_equal:today`.
    - `reason` → `required`, `string`, `max:255`.
    - `items` → `required`, `array`, `min:1`.
    - `items.*.product_id` → `required`, `uuid`, `exists:products,id`.
    - `items.*.stock_batch_id` → `required`, `uuid`, `exists:stock_batches,id`.
    - `items.*.difference` → `required`, `numeric`, `min:-999999999`, `max:999999999`. Nilai nol ditolak di `withValidator()` (bukan lewat `not_in`, karena perbandingan string Laravel tidak menangkap `"0.000"`).
    - `items.*.note` → `nullable`, `string`, `max:1000`.
    - `withValidator()` menambah tiga error:
      - batch yang dipilih bukan milik produk pada baris itu → `items.{i}.stock_batch_id` = "Batch ini bukan milik produk yang dipilih."
      - batch berstatus bukan `open` atau `quantity_remaining` = 0 → `items.{i}.stock_batch_id` = "Batch ini sudah habis dan tidak dapat dikoreksi."
      - pengurangan melebihi `quantity_remaining` batch → `items.{i}.difference` = "Pengurangan melebihi sisa batch. Sisa batch {sisa} {satuan}." (angka diisi di runtime).
    - `authorize()` → true (policy dicek di controller).
  - `UpdateStockAdjustmentRequest` rules: sama seperti store, tapi `adjustment_date` dan `reason` opsional (`sometimes`), `items` opsional (`sometimes`, `array`, `min:1`). Item yang sudah ada diganti seluruhnya (cascade delete) — item adjustment yang sudah diposting tidak pernah diubah.
  - `StockAdjustmentItemResource` mengembalikan: `id`, `product_id`, `product_name`, `sku`, `unit`, `stock_batch_id`, `batch_code`, `batch_remaining` (sisa batch saat item dibuat), `system_quantity`, `physical_quantity`, `difference`, `note`, `created_at`, `updated_at`.
  - `StockAdjustmentResource` mengembalikan: `id`, `adjustment_number`, `status`, `adjustment_date` (`Y-m-d`), `reason`, `created_by`, `created_by_name`, `posted_by`, `posted_by_name`, `posted_at`, `items` (collection `StockAdjustmentItemResource`), `created_at`, `updated_at`.
  - `StockMovementResource` mengembalikan: `id`, `product_id`, `product_name`, `sku`, `unit`, `stock_batch_id`, `batch_code`, `movement_type`, `quantity_in`, `quantity_out`, `ready_before`, `ready_after`, `pending_before`, `pending_after`, `source_type`, `source_id`, `reason`, `recorded_by`, `recorded_by_name`, `occurred_at`.
  - `StockOverviewResource` mengembalikan: `id` (product id), `sku`, `name`, `unit`, `category_name`, `ready_stock`, `pending_stock`, `minimum_stock`, `batch_remaining`, `movement_net`, `pending_weight`, `difference_batch`, `difference_movement`, `difference_pending`, `is_balanced`, `is_low_stock`, `batch_count`, `is_active`.

- [ ] **Step 1: Buat `StockAdjustmentPolicy`**

Buat `backend/app/Policies/StockAdjustmentPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Enums\AdjustmentStatus;
use App\Models\StockAdjustment;
use App\Models\User;

/**
 * Stock corrections are Admin-only end to end, per Docs/PRD.md §4 and
 * Docs/API.md §4.3. A Karyawan never sees the inventory module.
 */
class StockAdjustmentPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasRole('admin');
    }

    public function view(User $actor, StockAdjustment $adjustment): bool
    {
        return $actor->hasRole('admin');
    }

    public function create(User $actor): bool
    {
        return $actor->hasRole('admin');
    }

    public function update(User $actor, StockAdjustment $adjustment): bool
    {
        return $actor->hasRole('admin')
            && $adjustment->status === AdjustmentStatus::Draft;
    }

    public function post(User $actor, StockAdjustment $adjustment): bool
    {
        return $actor->hasRole('admin')
            && $adjustment->status === AdjustmentStatus::Draft;
    }

    public function cancel(User $actor, StockAdjustment $adjustment): bool
    {
        return $actor->hasRole('admin')
            && $adjustment->status === AdjustmentStatus::Draft;
    }
}
```

- [ ] **Step 2: Buat `StoreStockAdjustmentRequest`**

Buat `backend/app/Http/Requests/Inventory/StoreStockAdjustmentRequest.php`:

```php
<?php

namespace App\Http\Requests\Inventory;

use App\Models\Product;
use App\Models\StockBatch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreStockAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'adjustment_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'reason' => ['required', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'uuid', 'exists:products,id'],
            'items.*.stock_batch_id' => ['required', 'uuid', 'exists:stock_batches,id'],
            'items.*.difference' => ['required', 'numeric', 'min:-999999999', 'max:999999999'],
            'items.*.note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * A correction must name a batch of the right product that still has
     * stock to correct.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ((array) $this->input('items', []) as $index => $item) {
                $difference = bcadd((string) ($item['difference'] ?? '0'), '0', 3);

                // A zero difference is not a correction. It would post a
                // movement that changes nothing while still touching the
                // batch, so it is refused here rather than silently dropped.
                if (bccomp($difference, '0', 3) === 0) {
                    $validator->errors()->add(
                        "items.{$index}.difference",
                        'Selisih tidak boleh nol. Koreksi harus menunjukkan perbedaan yang nyata.',
                    );

                    continue;
                }

                $batch = StockBatch::query()->find($item['stock_batch_id'] ?? null);

                if (! $batch) {
                    continue;
                }

                if ($batch->product_id !== ($item['product_id'] ?? null)) {
                    $validator->errors()->add(
                        "items.{$index}.stock_batch_id",
                        'Batch ini bukan milik produk yang dipilih.',
                    );

                    continue;
                }

                if ($batch->status !== 'open' || bccomp((string) $batch->quantity_remaining, '0', 3) <= 0) {
                    $validator->errors()->add(
                        "items.{$index}.stock_batch_id",
                        'Batch ini sudah habis dan tidak dapat dikoreksi.',
                    );

                    continue;
                }

                if (bccomp($difference, '0', 3) < 0) {
                    $reduction = bcsub('0', $difference, 3);

                    if (bccomp((string) $batch->quantity_remaining, $reduction, 3) < 0) {
                        $product = Product::query()->find($batch->product_id);
                        $remaining = (string) $batch->quantity_remaining;

                        $validator->errors()->add(
                            "items.{$index}.difference",
                            "Pengurangan melebihi sisa batch. Sisa batch {$remaining} {$product?->unit}.",
                        );
                    }
                }
            }
        });
    }
}
```

- [ ] **Step 3: Buat `UpdateStockAdjustmentRequest`**

Buat `backend/app/Http/Requests/Inventory/UpdateStockAdjustmentRequest.php`:

```php
<?php

namespace App\Http\Requests\Inventory;

use App\Models\Product;
use App\Models\StockBatch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateStockAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'adjustment_date' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:today'],
            'reason' => ['sometimes', 'string', 'max:255'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'uuid', 'exists:products,id'],
            'items.*.stock_batch_id' => ['required', 'uuid', 'exists:stock_batches,id'],
            'items.*.difference' => ['required', 'numeric', 'min:-999999999', 'max:999999999'],
            'items.*.note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ((array) $this->input('items', []) as $index => $item) {
                $difference = bcadd((string) ($item['difference'] ?? '0'), '0', 3);

                if (bccomp($difference, '0', 3) === 0) {
                    $validator->errors()->add(
                        "items.{$index}.difference",
                        'Selisih tidak boleh nol. Koreksi harus menunjukkan perbedaan yang nyata.',
                    );

                    continue;
                }

                $batch = StockBatch::query()->find($item['stock_batch_id'] ?? null);

                if (! $batch) {
                    continue;
                }

                if ($batch->product_id !== ($item['product_id'] ?? null)) {
                    $validator->errors()->add(
                        "items.{$index}.stock_batch_id",
                        'Batch ini bukan milik produk yang dipilih.',
                    );

                    continue;
                }

                if ($batch->status !== 'open' || bccomp((string) $batch->quantity_remaining, '0', 3) <= 0) {
                    $validator->errors()->add(
                        "items.{$index}.stock_batch_id",
                        'Batch ini sudah habis dan tidak dapat dikoreksi.',
                    );

                    continue;
                }

                if (bccomp($difference, '0', 3) < 0) {
                    $reduction = bcsub('0', $difference, 3);

                    if (bccomp((string) $batch->quantity_remaining, $reduction, 3) < 0) {
                        $product = Product::query()->find($batch->product_id);
                        $remaining = (string) $batch->quantity_remaining;

                        $validator->errors()->add(
                            "items.{$index}.difference",
                            "Pengurangan melebihi sisa batch. Sisa batch {$remaining} {$product?->unit}.",
                        );
                    }
                }
            }
        });
    }
}
```

- [ ] **Step 4: Buat Resource `StockAdjustmentItemResource`**

Buat `backend/app/Http/Resources/StockAdjustmentItemResource.php`:

```php
<?php

namespace App\Http\Resources;

use App\Support\Timestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockAdjustmentItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_name' => $this->product?->name,
            'sku' => $this->product?->sku,
            'unit' => $this->product?->unit,
            'stock_batch_id' => $this->stock_batch_id,
            'batch_code' => $this->batch?->batch_code,
            'batch_remaining' => $this->batch?->quantity_remaining,
            'system_quantity' => $this->system_quantity,
            'physical_quantity' => $this->physical_quantity,
            'difference' => $this->difference,
            'note' => $this->note,
            'created_at' => Timestamp::iso8601($this->created_at),
            'updated_at' => Timestamp::iso8601($this->updated_at),
        ];
    }
}
```

- [ ] **Step 5: Buat Resource `StockAdjustmentResource`**

Buat `backend/app/Http/Resources/StockAdjustmentResource.php`:

```php
<?php

namespace App\Http\Resources;

use App\Support\Timestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockAdjustmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'adjustment_number' => $this->adjustment_number,
            'status' => $this->status?->value,
            'adjustment_date' => $this->adjustment_date?->format('Y-m-d'),
            'reason' => $this->reason,
            'created_by' => $this->created_by,
            'created_by_name' => $this->creator?->name,
            'posted_by' => $this->posted_by,
            'posted_by_name' => $this->poster?->name,
            'posted_at' => Timestamp::iso8601($this->posted_at),
            'items' => StockAdjustmentItemResource::collection($this->whenLoaded('items')),
            'created_at' => Timestamp::iso8601($this->created_at),
            'updated_at' => Timestamp::iso8601($this->updated_at),
        ];
    }
}
```

- [ ] **Step 6: Buat Resource `StockMovementResource`**

Buat `backend/app/Http/Resources/StockMovementResource.php`:

```php
<?php

namespace App\Http\Resources;

use App\Support\Timestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockMovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_name' => $this->product?->name,
            'sku' => $this->product?->sku,
            'unit' => $this->product?->unit,
            'stock_batch_id' => $this->stock_batch_id,
            'batch_code' => $this->batch?->batch_code,
            'movement_type' => $this->movement_type?->value,
            'quantity_in' => $this->quantity_in,
            'quantity_out' => $this->quantity_out,
            'ready_before' => $this->ready_before,
            'ready_after' => $this->ready_after,
            'pending_before' => $this->pending_before,
            'pending_after' => $this->pending_after,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'reason' => $this->reason,
            'recorded_by' => $this->recorded_by,
            'recorded_by_name' => $this->recorder?->name,
            'occurred_at' => Timestamp::iso8601($this->occurred_at),
        ];
    }
}
```

- [ ] **Step 7: Buat Resource `StockOverviewResource`**

Buat `backend/app/Http/Resources/StockOverviewResource.php`:

```php
<?php

namespace App\Http\Resources;

use App\Support\Timestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockOverviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'unit' => $this->unit,
            'category_name' => $this->category?->name,
            'ready_stock' => $this->ready_stock,
            'pending_stock' => $this->pending_stock,
            'minimum_stock' => $this->minimum_stock,
            'batch_remaining' => (string) ($this->batch_remaining ?? '0'),
            'movement_net' => (string) ($this->movement_net ?? '0'),
            'pending_weight' => (string) ($this->pending_weight ?? '0'),
            'difference_batch' => (string) ($this->difference_batch ?? '0'),
            'difference_movement' => (string) ($this->difference_movement ?? '0'),
            'difference_pending' => (string) ($this->difference_pending ?? '0'),
            'is_balanced' => (bool) ($this->is_balanced ?? true),
            'is_low_stock' => (bool) ($this->is_low_stock ?? false),
            'batch_count' => (int) ($this->batch_count ?? 0),
            'is_active' => (bool) $this->is_active,
            'created_at' => Timestamp::iso8601($this->created_at),
            'updated_at' => Timestamp::iso8601($this->updated_at),
        ];
    }
}
```

Resource ini menerima baris hasil query agregat (`stdClass`), bukan model `Product`, karena angkanya berasal dari subquery. `batch_remaining`, `movement_net`, `pending_weight`, dan ketiga selisih dihitung di `StockOverviewQuery` (Task 4).

- [ ] **Step 8: Jalankan pemeriksaan statis**

```bash
cd /d/DEV/0.AI/Cinnamon2/backend && ./vendor/bin/pint --test
```

Expected: exit 0.

- [ ] **Step 9: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add backend/app && git commit -m "backend: add adjustment policy, requests, and resources"
```

---

## Task 4: Query Layer — Stock Overview, Movement, dan Adjustment

**Files:**
- Create: `backend/app/Queries/StockOverviewQuery.php`
- Create: `backend/app/Queries/MovementQuery.php`
- Create: `backend/app/Queries/StockAdjustmentQuery.php`

**Interfaces:**
- Consumes: `App\Models\Product`, `App\Models\StockMovement`, `App\Models\StockAdjustment`, `App\Models\IncomingReceiptItem`.
- Produces:
  - `App\Queries\StockOverviewQuery`:
    - `public static function build(): self`
    - `public function filter(array $params): self` — `search` (nama/SKU), `category_id`, `stock_status` (`low` = `ready_stock <= minimum_stock` **dan** `minimum_stock > 0`; `out` = `ready_stock = 0`; `unbalanced` = ada selisih), `is_active`.
    - `public function sort(?string $sort): self` — allowlist: `name`, `sku`, `ready_stock`, `pending_stock`, `minimum_stock`, `updated_at`, dan versi `-`.
    - `public function paginate(int $perPage): LengthAwarePaginator`
    - Angka rekonsiliasi (`batch_remaining`, `movement_net`, `pending_weight`, `difference_batch`, `difference_movement`, `difference_pending`, `is_balanced`, `is_low_stock`, `batch_count`) dihitung sebagai **derived table** yang di-`JOIN` ke `products`, bukan lewat relasi — supaya satu baris produk = satu baris hasil, tanpa N+1.
  - `App\Queries\MovementQuery`:
    - `public static function build(): self`
    - `public function filter(array $params): self` — `product_id`, `movement_type`, `source_type`, `date_from`, `date_to`, `period` (`YYYY-MM`), `search` (batch code / nama produk / SKU).
    - `public function sort(?string $sort): self` — allowlist: `occurred_at`, `quantity_in`, `quantity_out`, `product`, dan versi `-`. Default `occurred_at desc, id desc`.
    - `public function paginate(int $perPage): LengthAwarePaginator`
  - `App\Queries\StockAdjustmentQuery`:
    - `public static function build(): self`
    - `public function filter(array $params): self` — `status`, `product_id`, `date_from`, `date_to`, `search` (nomor koreksi / alasan).
    - `public function sort(?string $sort): self` — allowlist: `adjustment_number`, `adjustment_date`, `created_at`, `status`, dan versi `-`. Default `adjustment_date desc, id desc`.
    - `public function paginate(int $perPage): LengthAwarePaginator`

- [ ] **Step 1: Buat `StockOverviewQuery`**

Buat `backend/app/Queries/StockOverviewQuery.php`:

```php
<?php

namespace App\Queries;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class StockOverviewQuery
{
    /**
     * @param  Builder<Product>  $query
     */
    public function __construct(private Builder $query)
    {
    }

    public static function build(): self
    {
        $query = Product::query()->with('category');

        // The three records of the same stock, each read as one derived table
        // so a page of products costs a fixed number of queries.
        //
        // The literals inside these strings are our own constants, never user
        // input, so inlining them is safe — and it keeps the derived tables
        // free of bindings, which is what keeps the join's binding order
        // simple.
        $batchRemainingSql = "SELECT product_id, COALESCE(SUM(quantity_remaining), 0) AS batch_remaining"
            ." FROM stock_batches WHERE status = 'open' GROUP BY product_id";

        $movementNetSql = 'SELECT product_id, COALESCE(SUM(quantity_in - quantity_out), 0) AS movement_net'
            .' FROM stock_movements GROUP BY product_id';

        // Pending stock is a projection over receipt lines still waiting for
        // verification, not a movement — see Docs/decisions.md §15.
        $pendingWeightSql = 'SELECT iri.product_id,'
            .' COALESCE(SUM(COALESCE(iri.weight_final, iri.weight_initial)), 0) AS pending_weight'
            .' FROM incoming_receipt_items iri'
            .' INNER JOIN incoming_receipts ir ON ir.id = iri.incoming_receipt_id'
            ." WHERE iri.status = 'pending' AND ir.status = 'pending'"
            .' GROUP BY iri.product_id';

        $query->select('products.*')
            ->leftJoin(DB::raw("({$batchRemainingSql}) AS b"), 'b.product_id', '=', 'products.id')
            ->leftJoin(DB::raw("({$movementNetSql}) AS m"), 'm.product_id', '=', 'products.id')
            ->leftJoin(DB::raw("({$pendingWeightSql}) AS p"), 'p.product_id', '=', 'products.id')
            ->selectRaw('COALESCE(b.batch_remaining, 0) AS batch_remaining')
            ->selectRaw('COALESCE(m.movement_net, 0) AS movement_net')
            ->selectRaw('COALESCE(p.pending_weight, 0) AS pending_weight')
            ->selectRaw(
                '(SELECT COUNT(*) FROM stock_batches sb WHERE sb.product_id = products.id'
                ." AND sb.status = 'open') AS batch_count",
            )
            ->selectRaw('(products.ready_stock - COALESCE(b.batch_remaining, 0)) AS difference_batch')
            ->selectRaw('(products.ready_stock - COALESCE(m.movement_net, 0)) AS difference_movement')
            ->selectRaw('(products.pending_stock - COALESCE(p.pending_weight, 0)) AS difference_pending')
            ->selectRaw(
                'CASE WHEN products.ready_stock = COALESCE(b.batch_remaining, 0) '
                .'AND products.ready_stock = COALESCE(m.movement_net, 0) '
                .'AND products.pending_stock = COALESCE(p.pending_weight, 0) THEN 1 ELSE 0 END AS is_balanced',
            )
            ->selectRaw(
                'CASE WHEN products.minimum_stock > 0 AND products.ready_stock <= products.minimum_stock'
                .' THEN 1 ELSE 0 END AS is_low_stock',
            );

        return new self($query);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function filter(array $params): self
    {
        if (! empty($params['search'])) {
            $search = $params['search'];

            $this->query->where(function (Builder $q) use ($search) {
                $q->where('products.name', 'like', "%{$search}%")
                    ->orWhere('products.sku', 'like', "%{$search}%");
            });
        }

        if (! empty($params['category_id'])) {
            $this->query->where('products.category_id', $params['category_id']);
        }

        if (isset($params['is_active'])) {
            $this->query->where('products.is_active', $params['is_active']);
        }

        if (! empty($params['stock_status'])) {
            match ($params['stock_status']) {
                'low' => $this->query->where('products.minimum_stock', '>', 0)
                    ->whereColumn('products.ready_stock', '<=', 'products.minimum_stock'),
                'out' => $this->query->where('products.ready_stock', 0),
                'unbalanced' => $this->query->whereRaw(
                    'products.ready_stock <> COALESCE(b.batch_remaining, 0) '
                    .'OR products.ready_stock <> COALESCE(m.movement_net, 0) '
                    .'OR products.pending_stock <> COALESCE(p.pending_weight, 0)',
                ),
                default => null,
            };
        }

        return $this;
    }

    public function sort(?string $sort): self
    {
        $allowlist = [
            'name' => 'products.name',
            '-name' => 'products.name:desc',
            'sku' => 'products.sku',
            '-sku' => 'products.sku:desc',
            'ready_stock' => 'products.ready_stock',
            '-ready_stock' => 'products.ready_stock:desc',
            'pending_stock' => 'products.pending_stock',
            '-pending_stock' => 'products.pending_stock:desc',
            'minimum_stock' => 'products.minimum_stock',
            '-minimum_stock' => 'products.minimum_stock:desc',
            'updated_at' => 'products.updated_at',
            '-updated_at' => 'products.updated_at:desc',
        ];

        if ($sort && isset($allowlist[$sort])) {
            $parts = explode(':', $allowlist[$sort]);
            $this->query->orderBy($parts[0], $parts[1] ?? 'asc');
        } else {
            $this->query->orderBy('products.name');
        }

        return $this;
    }

    /**
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function paginate(int $perPage)
    {
        return $this->query->paginate($perPage);
    }
}
```

**Catatan implementasi.** Angka agregat dibaca lewat tiga derived table yang di-`JOIN` ke `products` — bukan lewat relasi Eloquent — sehingga satu halaman produk tetap memerlukan jumlah query yang tetap, bukan satu query per produk. `DB::raw()` dipakai hanya untuk membungkus SQL turunan yang literalnya kita tulis sendiri, tidak ada input pengguna di dalamnya; filter pencarian tetap memakai binding seperti biasa.

**Catatan tentang `is_balanced`.** `difference_batch` dihitung dari batch yang berstatus `open` saja. Batch yang `depleted` (sisa 0) tidak dihitung, karena `quantity_remaining`-nya sudah 0 dan tidak mengubah jumlah. Batch `cancelled` juga tidak dihitung — namun status `cancelled` belum dipakai di fase ini (lihat "Yang tidak dikerjakan").

- [ ] **Step 2: Buat `MovementQuery`**

Buat `backend/app/Queries/MovementQuery.php`:

```php
<?php

namespace App\Queries;

use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Builder;

class MovementQuery
{
    /**
     * @param  Builder<StockMovement>  $query
     */
    public function __construct(private Builder $query)
    {
    }

    public static function build(): self
    {
        return new self(StockMovement::query()->with(['product', 'batch']));
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function filter(array $params): self
    {
        if (! empty($params['product_id'])) {
            $this->query->where('product_id', $params['product_id']);
        }

        if (! empty($params['movement_type'])) {
            $this->query->where('movement_type', $params['movement_type']);
        }

        if (! empty($params['source_type'])) {
            $this->query->where('source_type', $params['source_type']);
        }

        if (! empty($params['date_from'])) {
            $this->query->whereDate('occurred_at', '>=', $params['date_from']);
        }

        if (! empty($params['date_to'])) {
            $this->query->whereDate('occurred_at', '<=', $params['date_to']);
        }

        if (! empty($params['period'])) {
            // The period is bound as a parameter; the format string is our
            // own literal. Single quotes keep it valid under ANSI_QUOTES too.
            $this->query->whereRaw("DATE_FORMAT(occurred_at, '%Y-%m') = ?", [$params['period']]);
        }


        if (! empty($params['search'])) {
            $search = $params['search'];

            $this->query->where(function (Builder $q) use ($search) {
                $q->whereHas('batch', function (Builder $b) use ($search) {
                    $b->where('batch_code', 'like', "%{$search}%");
                })->orWhereHas('product', function (Builder $p) use ($search) {
                    $p->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%");
                });
            });
        }

        return $this;
    }

    public function sort(?string $sort): self
    {
        $allowlist = [
            'occurred_at' => 'occurred_at',
            '-occurred_at' => 'occurred_at:desc',
            'quantity_in' => 'quantity_in',
            '-quantity_in' => 'quantity_in:desc',
            'quantity_out' => 'quantity_out',
            '-quantity_out' => 'quantity_out:desc',
        ];

        if ($sort && isset($allowlist[$sort])) {
            $parts = explode(':', $allowlist[$sort]);
            $this->query->orderBy($parts[0], $parts[1] ?? 'asc');
        } else {
            $this->query->orderByDesc('occurred_at')->orderByDesc('id');
        }

        return $this;
    }

    /**
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function paginate(int $perPage)
    {
        return $this->query->paginate($perPage);
    }
}
```

- [ ] **Step 3: Buat `StockAdjustmentQuery`**

Buat `backend/app/Queries/StockAdjustmentQuery.php`:

```php
<?php

namespace App\Queries;

use App\Models\StockAdjustment;
use Illuminate\Database\Eloquent\Builder;

class StockAdjustmentQuery
{
    /**
     * @param  Builder<StockAdjustment>  $query
     */
    public function __construct(private Builder $query)
    {
    }

    public static function build(): self
    {
        return new self(StockAdjustment::query()->with(['creator', 'poster', 'items.product']));
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function filter(array $params): self
    {
        if (! empty($params['status'])) {
            $this->query->where('status', $params['status']);
        }

        if (! empty($params['product_id'])) {
            $this->query->whereHas('items', function (Builder $q) use ($params) {
                $q->where('product_id', $params['product_id']);
            });
        }

        if (! empty($params['date_from'])) {
            $this->query->whereDate('adjustment_date', '>=', $params['date_from']);
        }

        if (! empty($params['date_to'])) {
            $this->query->whereDate('adjustment_date', '<=', $params['date_to']);
        }

        if (! empty($params['search'])) {
            $search = $params['search'];

            $this->query->where(function (Builder $q) use ($search) {
                $q->where('adjustment_number', 'like', "%{$search}%")
                    ->orWhere('reason', 'like', "%{$search}%");
            });
        }

        return $this;
    }

    public function sort(?string $sort): self
    {
        $allowlist = [
            'adjustment_number' => 'adjustment_number',
            '-adjustment_number' => 'adjustment_number:desc',
            'adjustment_date' => 'adjustment_date',
            '-adjustment_date' => 'adjustment_date:desc',
            'created_at' => 'created_at',
            '-created_at' => 'created_at:desc',
            'status' => 'status',
            '-status' => 'status:desc',
        ];

        if ($sort && isset($allowlist[$sort])) {
            $parts = explode(':', $allowlist[$sort]);
            $this->query->orderBy($parts[0], $parts[1] ?? 'asc');
        } else {
            $this->query->orderByDesc('adjustment_date')->orderByDesc('id');
        }

        return $this;
    }

    /**
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function paginate(int $perPage)
    {
        return $this->query->paginate($perPage);
    }
}
```

- [ ] **Step 4: Jalankan pemeriksaan statis**

```bash
cd /d/DEV/0.AI/Cinnamon2/backend && ./vendor/bin/pint --test
```

Expected: exit 0.

- [ ] **Step 5: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add backend/app && git commit -m "backend: add stock overview, movement, and adjustment queries"
```

---

## Task 5: Action `PostAdjustment` (movement, ledger, audit)

**Files:**
- Create: `backend/app/Actions/Inventory/PostAdjustment.php`
- Create: `backend/app/Actions/Inventory/CancelAdjustment.php`
- Create: `backend/app/Actions/Inventory/UpdateAdjustment.php`

**Interfaces:**
- Consumes: `AdjustmentCalculator`, `AdjustmentNumbers`, `AdjustmentStatus`, `MovementType`, model `StockAdjustment`, `StockAdjustmentItem`, `Product`, `StockBatch`, `StockMovement`, `LedgerEntry`, `AuditLog`.
- Produces:
  - `PostAdjustment::handle(StockAdjustment $adjustment, User $actor): StockAdjustment`
    - Satu `DB::transaction`:
      1. Kunci adjustment: `StockAdjustment::query()->lockForUpdate()->findOrFail($adjustment->id)`. Bila status bukan `draft` → `BusinessRuleException` `DOCUMENT_ALREADY_POSTED` (409) dengan pesan "Koreksi ini sudah diposting dan tidak dapat diubah. Buat koreksi baru untuk membatalkannya."
      2. Muat item beserta produk dan batch-nya. Bila item kosong → `BusinessRuleException` `VALIDATION_ERROR` (422) dengan `fields.items`.
      3. Kunci tiap produk (`lockForUpdate`) dan tiap batch (`lockForUpdate`) yang disebut item.
      4. Untuk tiap item, hitung `effectiveDifference` = `difference` yang tersimpan. Bila negatif, `reduction = |difference|`. Bila `reduction > batch.quantity_remaining` → `BusinessRuleException` `VALIDATION_ERROR` (422) dengan `fields.items` = ["Pengurangan melebihi sisa batch {batch_code}. Sisa batch {sisa} {unit}."].
      5. Terapkan ke batch: pengurangan → `quantity_remaining -= reduction`; penambahan → `quantity_remaining += |difference|` **dan** `quantity_received += |difference|` (agar `quantity_remaining ≤ quantity_received` tetap berlaku). Bila `quantity_remaining` jadi 0 → `status` = `depleted`.
      6. Terapkan ke produk: `ready_stock` += `effectiveDifference` (bertanda). Bila hasilnya negatif → `BusinessRuleException` `VALIDATION_ERROR` (422) dengan pesan "Koreksi ini membuat stok siap jual produk menjadi negatif."
      7. Buat satu `stock_movements` per item: `movement_type` = `AdjustmentIn` bila `difference > 0`, `AdjustmentOut` bila `difference < 0`; `quantity_in` = `|difference|` untuk `AdjustmentIn` (0 untuk `AdjustmentOut`), `quantity_out` = `|difference|` untuk `AdjustmentOut` (0 untuk `AdjustmentIn`); `ready_before`/`ready_after` = nilai produk sebelum/sesudah; `pending_before` = `pending_after` = `pending_stock` produk (tidak berubah); `stock_batch_id` = batch yang dikoreksi; `source_type` = `stock_adjustment`; `source_id` = adjustment id; `reason` = alasan adjustment; `recorded_by` = actor; `occurred_at` = `now()`.
      8. Buat satu `ledger_entries` per item: `entry_date` = `adjustment_date`; `source_type` = `stock_adjustment`; `source_id` = adjustment id; `description` = "Koreksi stok {adjustment_number} — {batch_code}"; `debit` = nilai koreksi bila `difference > 0`, 0 bila negatif; `credit` = nilai koreksi bila `difference < 0`, 0 bila positif; `balance` = running balance (`balance` baris terakhir `ledger_entries` ditambah `debit − credit`); `created_by` = actor.
      9. Set adjustment `status` = `posted`, `posted_by` = actor, `posted_at` = `now()`.
      10. `audit_logs`: `action` = `stock_adjustment.posted`, `before_json` = status lama + stok per produk + sisa batch per batch, `after_json` = status baru + stok per produk + sisa batch per batch, `request_id` = `RequestId::current()`.
    - Mengembalikan adjustment yang di-refresh dengan `items`, `creator`, `poster` dimuat.
    - **Tidak** mengubah `pending_stock` — hanya bucket `ready` yang dikoreksi.
  - `CancelAdjustment::handle(StockAdjustment $adjustment, User $actor, ?string $reason = null): StockAdjustment`
    - Satu `DB::transaction`:
      1. Kunci adjustment. Bila status bukan `draft` → `BusinessRuleException` `DOCUMENT_ALREADY_POSTED` (409) "Koreksi yang sudah diposting tidak dapat dibatalkan. Buat koreksi baru untuk membatalkannya." Bila status `cancelled` → `BAD_REQUEST` (400) "Koreksi ini sudah dibatalkan."
      2. Set status `cancelled`. Item adjustment tidak pernah menyentuh stok, jadi tidak ada yang perlu dilepas.
      3. `audit_logs` dengan `action` = `stock_adjustment.cancelled`.
    - Tidak menulis movement, ledger, atau mengubah stok.
  - `UpdateAdjustment::handle(StockAdjustment $adjustment, User $actor, array $payload): StockAdjustment`
    - Satu `DB::transaction`:
      1. Kunci adjustment. Bila status bukan `draft` → `BusinessRuleException` `DOCUMENT_ALREADY_POSTED` (409) "Koreksi ini sudah diposting dan tidak dapat diubah."
      2. Update kolom dokumen (`adjustment_date`, `reason`).
      3. Bila `items` ada: hapus item lama dan buat ulang dengan snapshot baru (`system_quantity` dari `ready_stock` produk saat itu, `physical_quantity` = system + difference, `difference` dari input). Item adjustment yang sudah diposting tidak pernah dihapus — draft belum pernah menyentuh stok.
      4. `audit_logs` dengan `action` = `stock_adjustment.updated`.
    - Tidak menyentuh stok, movement, maupun ledger.

**Catatan tanda ledger.** Koreksi masuk (barang ditemukan lebih banyak) = `debit`; koreksi keluar (barang hilang) = `credit`. Ini penandaan yang dipilih pemilik produk, dan konsekuensinya pada rumus rekonsiliasi dijelaskan di Deviasi #4 dan dicatat di `Docs/decisions.md` §20 pada Task 11.

- [ ] **Step 1: Buat `PostAdjustment`**

Buat `backend/app/Actions/Inventory/PostAdjustment.php`:

```php
<?php

namespace App\Actions\Inventory;

use App\Domain\Inventory\AdjustmentCalculator;
use App\Enums\AdjustmentStatus;
use App\Enums\MovementType;
use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\LedgerEntry;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\RequestId;
use Illuminate\Support\Facades\DB;

class PostAdjustment
{
    /**
     * Turns a draft correction into an actual change of the books.
     *
     * Everything happens in one transaction: the batch balance, the product
     * projection, the movement, the ledger entry, and the audit trail. A
     * failure anywhere leaves the store exactly as it was.
     */
    public function handle(StockAdjustment $adjustment, User $actor): StockAdjustment
    {
        return DB::transaction(function () use ($adjustment, $actor): StockAdjustment {
            $locked = StockAdjustment::query()
                ->lockForUpdate()
                ->findOrFail($adjustment->id);

            if ($locked->status !== AdjustmentStatus::Draft) {
                throw new BusinessRuleException(
                    'DOCUMENT_ALREADY_POSTED',
                    'Koreksi ini sudah diposting dan tidak dapat diubah. Buat koreksi baru untuk membatalkannya.',
                );
            }

            $items = $locked->items()->orderBy('id')->get();

            if ($items->isEmpty()) {
                throw new BusinessRuleException(
                    'VALIDATION_ERROR',
                    'Koreksi ini tidak memiliki item.',
                    422,
                    ['items' => ['Koreksi harus memiliki minimal satu item.']],
                );
            }

            $products = [];
            $batches = [];

            foreach ($items as $item) {
                $products[$item->product_id] = Product::query()
                    ->lockForUpdate()
                    ->findOrFail($item->product_id);

                $batches[$item->stock_batch_id] = StockBatch::query()
                    ->lockForUpdate()
                    ->findOrFail($item->stock_batch_id);
            }

            $before = [
                'status' => $locked->status->value,
                'products' => [],
                'batches' => [],
            ];

            foreach ($products as $product) {
                $before['products'][$product->id] = [
                    'ready_stock' => $product->ready_stock,
                    'pending_stock' => $product->pending_stock,
                ];
            }

            foreach ($batches as $batch) {
                $before['batches'][$batch->id] = [
                    'batch_code' => $batch->batch_code,
                    'quantity_remaining' => $batch->quantity_remaining,
                ];
            }

            $now = now();

            foreach ($items as $item) {
                $product = $products[$item->product_id];
                $batch = $batches[$item->stock_batch_id];

                $difference = bcadd((string) $item->difference, '0', 3);
                $magnitude = bcabs($difference);
                $isAddition = bccomp($difference, '0', 3) > 0;

                if (! $isAddition) {
                    $reduction = bcsub('0', $difference, 3);

                    if (bccomp((string) $batch->quantity_remaining, $reduction, 3) < 0) {
                        throw new BusinessRuleException(
                            'VALIDATION_ERROR',
                            "Pengurangan melebihi sisa batch {$batch->batch_code}. Sisa batch {$batch->quantity_remaining} {$product->unit}.",
                            422,
                            ['items' => ["Pengurangan melebihi sisa batch {$batch->batch_code}."]],
                        );
                    }

                    $batch->quantity_remaining = bcsub(
                        (string) $batch->quantity_remaining,
                        $reduction,
                        3,
                    );
                } else {
                    // The batch now holds more than it was received with, so
                    // the received total moves too — otherwise the invariant
                    // `quantity_remaining <= quantity_received` would break.
                    $batch->quantity_remaining = bcadd(
                        (string) $batch->quantity_remaining,
                        $magnitude,
                        3,
                    );
                    $batch->quantity_received = bcadd(
                        (string) $batch->quantity_received,
                        $magnitude,
                        3,
                    );
                }

                if (bccomp((string) $batch->quantity_remaining, '0', 3) <= 0) {
                    $batch->status = 'depleted';
                }

                $batch->save();

                $readyBefore = (string) $product->ready_stock;
                $readyAfter = bcadd($readyBefore, $difference, 3);

                if (bccomp($readyAfter, '0', 3) < 0) {
                    throw new BusinessRuleException(
                        'VALIDATION_ERROR',
                        'Koreksi ini membuat stok siap jual produk menjadi negatif.',
                        422,
                        ['items' => ['Stok siap jual tidak boleh menjadi negatif.']],
                    );
                }

                $product->ready_stock = $readyAfter;
                $product->save();

                StockMovement::query()->create([
                    'product_id' => $product->id,
                    'stock_batch_id' => $batch->id,
                    'movement_type' => $isAddition ? MovementType::AdjustmentIn : MovementType::AdjustmentOut,
                    'quantity_in' => $isAddition ? $magnitude : '0',
                    'quantity_out' => $isAddition ? '0' : $magnitude,
                    'ready_before' => $readyBefore,
                    'ready_after' => $readyAfter,
                    'pending_before' => $product->pending_stock,
                    'pending_after' => $product->pending_stock,
                    'source_type' => 'stock_adjustment',
                    'source_id' => $locked->id,
                    'reason' => $locked->reason,
                    'recorded_by' => $actor->id,
                    'occurred_at' => $now,
                ]);

                $this->writeLedgerEntry($locked, $batch, $difference, $isAddition, $actor);
            }

            $locked->status = AdjustmentStatus::Posted;
            $locked->posted_by = $actor->id;
            $locked->posted_at = $now;
            $locked->save();

            $after = [
                'status' => $locked->status->value,
                'products' => [],
                'batches' => [],
            ];

            foreach ($products as $product) {
                $after['products'][$product->id] = [
                    'ready_stock' => $product->fresh()->ready_stock,
                    'pending_stock' => $product->fresh()->pending_stock,
                ];
            }

            foreach ($batches as $batch) {
                $after['batches'][$batch->id] = [
                    'batch_code' => $batch->batch_code,
                    'quantity_remaining' => $batch->fresh()->quantity_remaining,
                ];
            }

            AuditLog::query()->create([
                'actor_id' => $actor->id,
                'action' => 'stock_adjustment.posted',
                'resource_type' => 'stock_adjustment',
                'resource_id' => $locked->id,
                'before_json' => $before,
                'after_json' => $after,
                'request_id' => RequestId::current(),
                'occurred_at' => $now,
            ]);

            return $locked->refresh()->load(['items', 'creator', 'poster']);
        });
    }

    /**
     * Records the correction in the operational ledger.
     *
     * A correction in is a debit and a correction out is a credit — the
     * sign convention the store owner chose, documented in
     * `Docs/decisions.md` §20.
     */
    private function writeLedgerEntry(
        StockAdjustment $adjustment,
        StockBatch $batch,
        string $difference,
        bool $isAddition,
        User $actor,
    ): void {
        $value = AdjustmentCalculator::value($batch, $difference);

        $running = LedgerEntry::query()
            ->orderByDesc('id')
            ->first()?->balance ?? '0';

        $debit = $isAddition ? $value : '0';
        $credit = $isAddition ? '0' : $value;

        LedgerEntry::query()->create([
            'entry_date' => $adjustment->adjustment_date->toDateString(),
            'source_type' => 'stock_adjustment',
            'source_id' => $adjustment->id,
            'description' => "Koreksi stok {$adjustment->adjustment_number} — {$batch->batch_code}",
            'debit' => $debit,
            'credit' => $credit,
            'balance' => bcadd(bcsub((string) $running, $credit, 2), $debit, 2),
            'created_by' => $actor->id,
        ]);
    }
}
```

**Catatan running balance.** `Docs/decisions.md` §6 menetapkan `balance` = running cumulative `SUM(debit) − SUM(credit)` diurut `id`. Kode di atas mengambil `balance` baris terakhir lalu menambah `debit` dan mengurangi `credit`. Karena `id` UUID v7 bersifat time-ordered, `orderByDesc('id')` mengambil baris terbaru, dan ledger bersifat append-only, jadi cara ini setara dengan `SUM`.

- [ ] **Step 2: Buat `CancelAdjustment`**

Buat `backend/app/Actions/Inventory/CancelAdjustment.php`:

```php
<?php

namespace App\Actions\Inventory;

use App\Enums\AdjustmentStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Support\RequestId;
use Illuminate\Support\Facades\DB;

class CancelAdjustment
{
    /**
     * Cancels a draft correction.
     *
     * A draft never touched the books, so cancelling it only closes the
     * document. A posted correction is never cancelled: reversing it means
     * writing a new correction, so the history keeps both entries.
     */
    public function handle(StockAdjustment $adjustment, User $actor, ?string $reason = null): StockAdjustment
    {
        return DB::transaction(function () use ($adjustment, $actor, $reason): StockAdjustment {
            $locked = StockAdjustment::query()
                ->lockForUpdate()
                ->findOrFail($adjustment->id);

            if ($locked->status === AdjustmentStatus::Posted) {
                throw new BusinessRuleException(
                    'DOCUMENT_ALREADY_POSTED',
                    'Koreksi yang sudah diposting tidak dapat dibatalkan. Buat koreksi baru untuk membatalkannya.',
                );
            }

            if ($locked->status === AdjustmentStatus::Cancelled) {
                throw new BusinessRuleException(
                    'BAD_REQUEST',
                    'Koreksi ini sudah dibatalkan.',
                );
            }

            $before = [
                'status' => $locked->status->value,
                'reason' => $locked->reason,
            ];

            $locked->status = AdjustmentStatus::Cancelled;
            $locked->save();

            AuditLog::query()->create([
                'actor_id' => $actor->id,
                'action' => 'stock_adjustment.cancelled',
                'resource_type' => 'stock_adjustment',
                'resource_id' => $locked->id,
                'before_json' => $before,
                'after_json' => [
                    'status' => $locked->status->value,
                    'reason' => $reason ?? $locked->reason,
                ],
                'request_id' => RequestId::current(),
                'occurred_at' => now(),
            ]);

            return $locked->refresh()->load(['items', 'creator', 'poster']);
        });
    }
}
```

- [ ] **Step 3: Buat `UpdateAdjustment`**

Buat `backend/app/Actions/Inventory/UpdateAdjustment.php`:

```php
<?php

namespace App\Actions\Inventory;

use App\Domain\Inventory\AdjustmentCalculator;
use App\Enums\AdjustmentStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use App\Models\User;
use App\Support\RequestId;
use Illuminate\Support\Facades\DB;

class UpdateAdjustment
{
    /**
     * Edits a draft correction.
     *
     * Only a draft may be edited. A posted correction is part of the
     * books, and the only way to correct it is a new document.
     */
    public function handle(StockAdjustment $adjustment, User $actor, array $payload): StockAdjustment
    {
        return DB::transaction(function () use ($adjustment, $actor, $payload): StockAdjustment {
            $locked = StockAdjustment::query()
                ->lockForUpdate()
                ->findOrFail($adjustment->id);

            if ($locked->status !== AdjustmentStatus::Draft) {
                throw new BusinessRuleException(
                    'DOCUMENT_ALREADY_POSTED',
                    'Koreksi ini sudah diposting dan tidak dapat diubah.',
                );
            }

            $before = [
                'adjustment_date' => $locked->adjustment_date?->toDateString(),
                'reason' => $locked->reason,
                'items' => $locked->items()->get()->map(fn (StockAdjustmentItem $item) => [
                    'product_id' => $item->product_id,
                    'stock_batch_id' => $item->stock_batch_id,
                    'difference' => $item->difference,
                ])->all(),
            ];

            foreach (['adjustment_date', 'reason'] as $field) {
                if (array_key_exists($field, $payload)) {
                    $locked->{$field} = $payload[$field];
                }
            }

            if (array_key_exists('items', $payload)) {
                $locked->items()->delete();

                foreach ($payload['items'] as $item) {
                    $product = Product::query()->findOrFail($item['product_id']);

                    $snapshot = AdjustmentCalculator::snapshot(
                        (string) $product->ready_stock,
                        (string) $item['difference'],
                    );

                    StockAdjustmentItem::query()->create([
                        'stock_adjustment_id' => $locked->id,
                        'product_id' => $product->id,
                        'stock_batch_id' => $item['stock_batch_id'],
                        'system_quantity' => $snapshot['system_quantity'],
                        'physical_quantity' => $snapshot['physical_quantity'],
                        'difference' => $snapshot['difference'],
                        'note' => $item['note'] ?? null,
                    ]);
                }
            }

            $locked->save();

            AuditLog::query()->create([
                'actor_id' => $actor->id,
                'action' => 'stock_adjustment.updated',
                'resource_type' => 'stock_adjustment',
                'resource_id' => $locked->id,
                'before_json' => $before,
                'after_json' => [
                    'adjustment_date' => $locked->adjustment_date?->toDateString(),
                    'reason' => $locked->reason,
                    'items' => $locked->items()->get()->map(fn (StockAdjustmentItem $item) => [
                        'product_id' => $item->product_id,
                        'stock_batch_id' => $item->stock_batch_id,
                        'difference' => $item->difference,
                    ])->all(),
                ],
                'request_id' => RequestId::current(),
                'occurred_at' => now(),
            ]);

            return $locked->refresh()->load(['items', 'creator', 'poster']);
        });
    }
}
```

- [ ] **Step 4: Jalankan pemeriksaan statis**

```bash
cd /d/DEV/0.AI/Cinnamon2/backend && ./vendor/bin/pint --test
```

Expected: exit 0.

- [ ] **Step 5: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add backend/app && git commit -m "backend: post, cancel, and update stock corrections in one transaction"
```

---

## Task 6: Action `CreateAdjustment` (membuat draft)

**Files:**
- Create: `backend/app/Actions/Inventory/CreateAdjustment.php`

**Interfaces:**
- Consumes: `AdjustmentCalculator`, `AdjustmentNumbers`, model `StockAdjustment`, `StockAdjustmentItem`, `Product`, `User`.
- Produces:
  - `CreateAdjustment::handle(User $actor, array $payload): StockAdjustment`
    - Satu `DB::transaction`:
      1. Buat adjustment dengan `adjustment_number` dari `AdjustmentNumbers::next($payload['adjustment_date'])`, `status` = `draft`, `reason`, `created_by` = actor.
      2. Untuk tiap item: ambil produk, ambil `ready_stock` saat ini, hitung snapshot lewat `AdjustmentCalculator::snapshot($readyStock, $difference)`, lalu buat `stock_adjustment_items` dengan `stock_batch_id`, `system_quantity`, `physical_quantity`, `difference`, `note`.
      3. `audit_logs` dengan `action` = `stock_adjustment.created`, `after_json` berisi ringkasan item.
    - Mengembalikan adjustment dengan `items`, `creator` dimuat.
    - Tidak menyentuh stok, batch, movement, maupun ledger — draft belum pernah mengubah apa pun.

- [ ] **Step 1: Buat `CreateAdjustment`**

Buat `backend/app/Actions/Inventory/CreateAdjustment.php`:

```php
<?php

namespace App\Actions\Inventory;

use App\Domain\Inventory\AdjustmentCalculator;
use App\Domain\Inventory\AdjustmentNumbers;
use App\Enums\AdjustmentStatus;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use App\Models\User;
use App\Support\RequestId;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CreateAdjustment
{
    /**
     * Creates a draft correction.
     *
     * The draft records the system's own quantity at the moment it was
     * written, so the store can see later what it thought the stock was
     * compared with what was actually counted.
     */
    public function handle(User $actor, array $payload): StockAdjustment
    {
        return DB::transaction(function () use ($actor, $payload): StockAdjustment {
            $adjustmentDate = Carbon::parse($payload['adjustment_date']);

            $adjustment = StockAdjustment::query()->create([
                'adjustment_number' => AdjustmentNumbers::next($adjustmentDate),
                'status' => AdjustmentStatus::Draft,
                'adjustment_date' => $adjustmentDate->toDateString(),
                'reason' => $payload['reason'],
                'created_by' => $actor->id,
            ]);

            $summary = [];

            foreach ($payload['items'] as $item) {
                $product = Product::query()->findOrFail($item['product_id']);

                $snapshot = AdjustmentCalculator::snapshot(
                    (string) $product->ready_stock,
                    (string) $item['difference'],
                );

                StockAdjustmentItem::query()->create([
                    'stock_adjustment_id' => $adjustment->id,
                    'product_id' => $product->id,
                    'stock_batch_id' => $item['stock_batch_id'],
                    'system_quantity' => $snapshot['system_quantity'],
                    'physical_quantity' => $snapshot['physical_quantity'],
                    'difference' => $snapshot['difference'],
                    'note' => $item['note'] ?? null,
                ]);

                $summary[] = [
                    'product_id' => $product->id,
                    'stock_batch_id' => $item['stock_batch_id'],
                    'difference' => $snapshot['difference'],
                ];
            }

            AuditLog::query()->create([
                'actor_id' => $actor->id,
                'action' => 'stock_adjustment.created',
                'resource_type' => 'stock_adjustment',
                'resource_id' => $adjustment->id,
                'before_json' => null,
                'after_json' => [
                    'status' => $adjustment->status->value,
                    'adjustment_number' => $adjustment->adjustment_number,
                    'items' => $summary,
                ],
                'request_id' => RequestId::current(),
                'occurred_at' => now(),
            ]);

            return $adjustment->refresh()->load(['items', 'creator', 'poster']);
        });
    }
}
```

- [ ] **Step 2: Jalankan pemeriksaan statis**

```bash
cd /d/DEV/0.AI/Cinnamon2/backend && ./vendor/bin/pint --test
```

Expected: exit 0.

- [ ] **Step 3: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add backend/app && git commit -m "backend: add draft creation for stock corrections"
```

---

## Task 7: Controller dan Route Inventori

**Files:**
- Create: `backend/app/Http/Controllers/Api/V1/StockOverviewController.php`
- Create: `backend/app/Http/Controllers/Api/V1/MovementController.php`
- Create: `backend/app/Http/Controllers/Api/V1/StockAdjustmentController.php`
- Modify: `backend/routes/api.php`

**Interfaces:**
- Consumes: action dari Task 5–6, request dari Task 3, resource dari Task 3, query dari Task 4, `App\Support\Idempotency`.
- Produces:
  - `StockOverviewController`:
    - `index(Request $request): JsonResponse` — `GET /inventory/stock`. Memanggil `$this->authorize('viewAny', StockAdjustment::class)` (policy inventori = Admin). Filter: `search`, `category_id`, `stock_status` (`low`|`out`|`unbalanced`), `is_active`, `sort`, `page`, `per_page` (maks 100).
  - `MovementController`:
    - `index(Request $request): JsonResponse` — `GET /inventory/movements`. Filter: `product_id`, `movement_type`, `source_type`, `date_from`, `date_to`, `period`, `search`, `sort`, `page`, `per_page`.
  - `StockAdjustmentController`:
    - `index(Request $request): JsonResponse` — `GET /inventory/adjustments`. Filter: `status`, `product_id`, `date_from`, `date_to`, `search`, `sort`, `page`, `per_page`.
    - `store(StoreStockAdjustmentRequest $request): JsonResponse` — `POST /inventory/adjustments`, jalankan `CreateAdjustment`, 201.
    - `show(StockAdjustment $adjustment): JsonResponse` — `GET /inventory/adjustments/{adjustment}`.
    - `update(UpdateStockAdjustmentRequest $request, StockAdjustment $adjustment): JsonResponse` — `PATCH /inventory/adjustments/{adjustment}`, jalankan `UpdateAdjustment`.
    - `post(Request $request, StockAdjustment $adjustment): JsonResponse` — `POST /inventory/adjustments/{adjustment}/post`, **wajib `Idempotency-Key`**, jalankan `PostAdjustment`.
    - `cancel(Request $request, StockAdjustment $adjustment): JsonResponse` — `POST /inventory/adjustments/{adjustment}/cancel`, jalankan `CancelAdjustment`.
  - Route di `backend/routes/api.php`:
    ```php
    Route::get('/inventory/stock', [StockOverviewController::class, 'index'])->middleware('auth:sanctum');
    Route::get('/inventory/movements', [MovementController::class, 'index'])->middleware('auth:sanctum');
    Route::get('/inventory/adjustments', [StockAdjustmentController::class, 'index'])->middleware('auth:sanctum');
    Route::post('/inventory/adjustments', [StockAdjustmentController::class, 'store'])->middleware('auth:sanctum');
    Route::get('/inventory/adjustments/{adjustment}', [StockAdjustmentController::class, 'show'])->middleware('auth:sanctum');
    Route::patch('/inventory/adjustments/{adjustment}', [StockAdjustmentController::class, 'update'])->middleware('auth:sanctum');
    Route::post('/inventory/adjustments/{adjustment}/post', [StockAdjustmentController::class, 'post'])->middleware('auth:sanctum');
    Route::post('/inventory/adjustments/{adjustment}/cancel', [StockAdjustmentController::class, 'cancel'])->middleware('auth:sanctum');
    ```

- [ ] **Step 1: Buat `StockOverviewController`**

Buat `backend/app/Http/Controllers/Api/V1/StockOverviewController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\StockOverviewResource;
use App\Models\StockAdjustment;
use App\Queries\StockOverviewQuery;
use App\Support\RequestId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockOverviewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StockAdjustment::class);

        $perPage = max(1, min(100, (int) $request->integer('per_page', 20)));

        $products = StockOverviewQuery::build()
            ->filter($request->only(['search', 'category_id', 'stock_status', 'is_active']))
            ->sort($request->input('sort'))
            ->paginate($perPage);

        return response()->json([
            'data' => StockOverviewResource::collection($products->items()),
            'meta' => [
                'request_id' => RequestId::current(),
                'current_page' => $products->currentPage(),
                'per_page' => $products->perPage(),
                'last_page' => $products->lastPage(),
                'total' => $products->total(),
            ],
        ]);
    }
}
```

**Catatan.** `authorize('viewAny', StockAdjustment::class)` dipakai di sini karena policy inventori adalah satu-satunya policy Admin yang tersedia di fase ini; `StockAdjustmentPolicy::viewAny` hanya mengizinkan Admin. Bila nanti ada policy `InventoryPolicy`, ganti rujukan ini.

- [ ] **Step 2: Buat `MovementController`**

Buat `backend/app/Http/Controllers/Api/V1/MovementController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\StockMovementResource;
use App\Models\StockAdjustment;
use App\Queries\MovementQuery;
use App\Support\RequestId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MovementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StockAdjustment::class);

        $perPage = max(1, min(100, (int) $request->integer('per_page', 20)));

        $movements = MovementQuery::build()
            ->filter($request->only([
                'product_id',
                'movement_type',
                'source_type',
                'date_from',
                'date_to',
                'period',
                'search',
            ]))
            ->sort($request->input('sort'))
            ->paginate($perPage);

        return response()->json([
            'data' => StockMovementResource::collection($movements->items()),
            'meta' => [
                'request_id' => RequestId::current(),
                'current_page' => $movements->currentPage(),
                'per_page' => $movements->perPage(),
                'last_page' => $movements->lastPage(),
                'total' => $movements->total(),
            ],
        ]);
    }
}
```

- [ ] **Step 3: Buat `StockAdjustmentController`**

Buat `backend/app/Http/Controllers/Api/V1/StockAdjustmentController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Inventory\CancelAdjustment;
use App\Actions\Inventory\CreateAdjustment;
use App\Actions\Inventory\PostAdjustment;
use App\Actions\Inventory\UpdateAdjustment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreStockAdjustmentRequest;
use App\Http\Requests\Inventory\UpdateStockAdjustmentRequest;
use App\Http\Resources\StockAdjustmentResource;
use App\Models\StockAdjustment;
use App\Queries\StockAdjustmentQuery;
use App\Support\ApiResponse;
use App\Support\Idempotency;
use App\Support\RequestId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockAdjustmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StockAdjustment::class);

        $perPage = max(1, min(100, (int) $request->integer('per_page', 20)));

        $adjustments = StockAdjustmentQuery::build()
            ->filter($request->only(['status', 'product_id', 'date_from', 'date_to', 'search']))
            ->sort($request->input('sort'))
            ->paginate($perPage);

        return response()->json([
            'data' => StockAdjustmentResource::collection($adjustments->items()),
            'meta' => [
                'request_id' => RequestId::current(),
                'current_page' => $adjustments->currentPage(),
                'per_page' => $adjustments->perPage(),
                'last_page' => $adjustments->lastPage(),
                'total' => $adjustments->total(),
            ],
        ]);
    }

    public function store(StoreStockAdjustmentRequest $request): JsonResponse
    {
        $this->authorize('create', StockAdjustment::class);

        $adjustment = app(CreateAdjustment::class)->handle($request->user(), $request->validated());

        return ApiResponse::success(
            new StockAdjustmentResource($adjustment->load(['items.product', 'items.batch', 'creator', 'poster'])),
            201,
        );
    }

    public function show(StockAdjustment $adjustment): JsonResponse
    {
        $this->authorize('view', $adjustment);

        return ApiResponse::success(
            new StockAdjustmentResource($adjustment->load(['items.product', 'items.batch', 'creator', 'poster'])),
        );
    }

    public function update(UpdateStockAdjustmentRequest $request, StockAdjustment $adjustment): JsonResponse
    {
        $this->authorize('update', $adjustment);

        $updated = app(UpdateAdjustment::class)->handle(
            $adjustment,
            $request->user(),
            $request->validated(),
        );

        return ApiResponse::success(
            new StockAdjustmentResource($updated->load(['items.product', 'items.batch', 'creator', 'poster'])),
        );
    }

    /**
     * Posts a draft correction.
     *
     * The same key with the same payload replays the stored response, so a
     * double click or a retry after a network hiccup cannot correct the
     * stock twice.
     */
    public function post(Request $request, StockAdjustment $adjustment): JsonResponse
    {
        $this->authorize('post', $adjustment);

        return Idempotency::run(
            'stock-adjustment.post',
            $adjustment->id,
            function () use ($adjustment, $request): JsonResponse {
                $result = app(PostAdjustment::class)->handle($adjustment, $request->user());

                return ApiResponse::success(
                    new StockAdjustmentResource($result->load(['items.product', 'items.batch', 'creator', 'poster'])),
                );
            },
            'stock_adjustment',
            $adjustment->id,
        );
    }

    /**
     * Cancels a draft correction.
     */
    public function cancel(Request $request, StockAdjustment $adjustment): JsonResponse
    {
        $this->authorize('cancel', $adjustment);

        $reason = $request->input('reason');

        $result = app(CancelAdjustment::class)->handle($adjustment, $request->user(), $reason);

        return ApiResponse::success(
            new StockAdjustmentResource($result->load(['items.product', 'items.batch', 'creator', 'poster'])),
        );
    }
}
```

**Catatan idempotensi.** Fingerprint untuk `post` hanya `adjustment->id` — isinya sudah terkunci dalam dokumen, jadi tidak ada payload lain yang bisa berbeda untuk adjustment yang sama. Kalau nanti ada alasan yang bisa berubah antar-attempt, masukkan ke fingerprint.

- [ ] **Step 4: Daftarkan route**

Di `backend/routes/api.php`, tambahkan import:

```php
use App\Http\Controllers\Api\V1\MovementController;
use App\Http\Controllers\Api\V1\StockAdjustmentController;
use App\Http\Controllers\Api\V1\StockOverviewController;
```

Lalu tambahkan blok route di bawah route yang sudah ada:

```php
Route::get('/inventory/stock', [StockOverviewController::class, 'index'])->middleware('auth:sanctum');
Route::get('/inventory/movements', [MovementController::class, 'index'])->middleware('auth:sanctum');
Route::get('/inventory/adjustments', [StockAdjustmentController::class, 'index'])->middleware('auth:sanctum');
Route::post('/inventory/adjustments', [StockAdjustmentController::class, 'store'])->middleware('auth:sanctum');
Route::get('/inventory/adjustments/{adjustment}', [StockAdjustmentController::class, 'show'])->middleware('auth:sanctum');
Route::patch('/inventory/adjustments/{adjustment}', [StockAdjustmentController::class, 'update'])->middleware('auth:sanctum');
Route::post('/inventory/adjustments/{adjustment}/post', [StockAdjustmentController::class, 'post'])->middleware('auth:sanctum');
Route::post('/inventory/adjustments/{adjustment}/cancel', [StockAdjustmentController::class, 'cancel'])->middleware('auth:sanctum');
```

**Penting:** route `/inventory/adjustments/{adjustment}` harus terdaftar **setelah** `/inventory/adjustments` (GET vs GET, tidak bentrok) dan **tidak boleh** ada route wildcard yang lebih dulu.

- [ ] **Step 5: Jalankan pemeriksaan statis**

```bash
cd /d/DEV/0.AI/Cinnamon2/backend && ./vendor/bin/pint --test
```

Expected: exit 0.

- [ ] **Step 6: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add backend/app backend/routes && git commit -m "backend: expose stock overview, movement, and adjustment endpoints"
```

---

## Task 8: Command Rekonsiliasi `inventory:reconcile`

**Files:**
- Create: `backend/app/Console/Commands/ReconcileInventory.php`

**Interfaces:**
- Consumes: `App\Models\Product`, `App\Models\StockBatch`, `App\Models\StockMovement`, `App\Models\IncomingReceiptItem`, `App\Domain\Inventory\StockReconciler`.
- Produces:
  - Command Artisan `inventory:reconcile`:
    - Signature: `inventory:reconcile {--product= : Batasi ke satu produk (UUID)} {--json : Keluarkan JSON} {--unbalanced-only : Hanya produk yang tidak seimbang}` (default: `--unbalanced-only` aktif, `--all` menampilkan semuanya).
    - Membaca angka yang sama dengan `StockOverviewQuery` — batch remaining, movement net, pending weight — lalu membandingkan dengan proyeksi memakai `StockReconciler::check()`.
    - **Tidak menulis apa pun ke database.** Perintah ini hanya melaporkan; memperbaiki selisih berarti membuat dokumen koreksi.
    - Keluar dengan exit code `0` bila semua seimbang, `1` bila ada selisih (berguna untuk pemeriksaan terjadwal).

- [ ] **Step 1: Buat command**

Buat `backend/app/Console/Commands/ReconcileInventory.php`:

```php
<?php

namespace App\Console\Commands;

use App\Domain\Inventory\StockReconciler;
use App\Models\IncomingReceiptItem;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockMovement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcileInventory extends Command
{
    protected $signature = 'inventory:reconcile
        {--product= : Batasi ke satu produk (UUID)}
        {--all : Tampilkan semua produk, bukan hanya yang tidak seimbang}
        {--json : Keluarkan JSON}';

    protected $description = 'Bandingkan proyeksi stok, sisa batch, dan movement tanpa memperbaikinya diam-diam';

    public function handle(): int
    {
        $products = Product::query()
            ->when($this->option('product'), fn ($query, $id) => $query->whereKey($id))
            ->orderBy('name')
            ->get();

        $rows = [];

        foreach ($products as $product) {
            $batchRemaining = (string) (StockBatch::query()
                ->where('product_id', $product->id)
                ->where('status', 'open')
                ->sum('quantity_remaining') ?? '0');

            $movementNet = (string) (StockMovement::query()
                ->where('product_id', $product->id)
                ->sum(DB::raw('quantity_in - quantity_out')) ?? '0');

            $pendingWeight = (string) (IncomingReceiptItem::query()
                ->where('product_id', $product->id)
                ->where('status', 'pending')
                ->whereHas('receipt', fn ($query) => $query->where('status', 'pending'))
                ->sum(DB::raw('COALESCE(weight_final, weight_initial)')) ?? '0');

            $check = StockReconciler::check(
                (string) $product->ready_stock,
                (string) $product->pending_stock,
                $batchRemaining,
                $movementNet,
                $pendingWeight,
            );

            $rows[] = [
                'sku' => $product->sku,
                'name' => $product->name,
                'ready_stock' => (string) $product->ready_stock,
                'batch_remaining' => $batchRemaining,
                'movement_net' => $movementNet,
                'difference_batch' => $check['difference_batch'],
                'difference_movement' => $check['difference_movement'],
                'difference_pending' => $check['difference_pending'],
                'is_balanced' => $check['is_balanced'],
            ];
        }

        if (! $this->option('all')) {
            $rows = array_values(array_filter(
                $rows,
                fn (array $row) => $row['is_balanced'] === false,
            ));
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $this->hasUnbalanced($rows) ? self::FAILURE : self::SUCCESS;
        }

        if ($rows === []) {
            $this->info('Semua stok seimbang: proyeksi, batch, dan movement cocok.');

            return self::SUCCESS;
        }

        $this->table(
            ['SKU', 'Produk', 'Ready', 'Batch', 'Movement', 'Selisih batch', 'Selisih movement', 'Selisih pending'],
            array_map(fn (array $row) => [
                $row['sku'],
                $row['name'],
                $row['ready_stock'],
                $row['batch_remaining'],
                $row['movement_net'],
                $row['difference_batch'],
                $row['difference_movement'],
                $row['difference_pending'],
            ], $rows),
        );

        $this->warn('Selisih di atas hanya dilaporkan. Perbaikannya dengan membuat dokumen koreksi stok.');

        return $this->hasUnbalanced($rows) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function hasUnbalanced(array $rows): bool
    {
        foreach ($rows as $row) {
            if ($row['is_balanced'] === false) {
                return true;
            }
        }

        return false;
    }
}
```

**Catatan implementasi.** `sum()` dengan raw expression mengembalikan nilai agregat; kalau MySQL mengembalikan string, `bcsub` di `StockReconciler` tetap aman karena semua perhitungan memakai `bcmath`. Perintah ini **tidak menulis apa pun** ke database — hanya membaca dan mencetak.

- [ ] **Step 2: Daftarkan command**

Laravel 13 discovering command di `app/Console/Commands` secara otomatis. Pastikan `bootstrap/app.php` tidak memblokir auto-discovery; kalau `withCommands()` ada, tambahkan:

```php
    ->withCommands([
        App\Console\Commands\ReconcileInventory::class,
    ])
```

- [ ] **Step 3: Jalankan perintah dan periksa hasilnya**

```bash
cd /d/DEV/0.AI/Cinnamon2/backend && php artisan inventory:reconcile
```

Expected: tabel tercetak. Dengan data seed Phase 2.2 (1 batch Pala 24,500 kg, 1 movement receipt 24,500 kg, `ready_stock` 24,500), produk Pala harus **seimbang** dan tidak muncul di output default.

- [ ] **Step 4: Jalankan pemeriksaan statis**

```bash
cd /d/DEV/0.AI/Cinnamon2/backend && ./vendor/bin/pint --test
```

Expected: exit 0.

- [ ] **Step 5: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add backend/app && git commit -m "backend: add inventory reconcile command that reports differences without fixing them"
```

---

## Task 9: Verifikasi Live API Inventori

**Files:** Tidak ada file baru. Task ini murni verifikasi.

- [ ] **Step 1: Jalankan server backend**

```bash
cd /d/DEV/0.AI/Cinnamon2/backend && php artisan serve
```

- [ ] **Step 2: Login Admin dan simpan cookie**

```bash
curl -c cookies.txt -X POST http://127.0.0.1:8000/api/v1/auth/login \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"email":"admin@cinnamon.test","password":"password"}'
```

Ambil `XSRF-TOKEN` dari cookie jar (`grep XSRF cookies.txt`) dan pakai sebagai header `X-XSRF-TOKEN` di setiap request yang mengubah data. Kalau muncul 419, ambil ulang cookie CSRF lewat `curl -c cookies.txt http://127.0.0.1:8000/sanctum/csrf-cookie`.

- [ ] **Step 3: Verifikasi skenario A — Ringkasan stok**

```bash
curl -X GET "http://127.0.0.1:8000/api/v1/inventory/stock" \
  -H "Accept: application/json" -b cookies.txt
```

Expected: 200. Setiap baris punya `ready_stock`, `pending_stock`, `batch_remaining`, `movement_net`, `difference_batch`, `is_balanced`. Untuk produk seed Phase 2.2: `ready_stock` = "24.500", `batch_remaining` = "24.500", `movement_net` = "24.500", `is_balanced` = true.

- [ ] **Step 4: Verifikasi skenario B — Filter low stock**

```bash
curl -X GET "http://127.0.0.1:8000/api/v1/inventory/stock?stock_status=low" \
  -H "Accept: application/json" -b cookies.txt
```

Expected: hanya produk dengan `minimum_stock > 0` dan `ready_stock <= minimum_stock`.

- [ ] **Step 5: Verifikasi skenario C — Filter tidak seimbang**

```bash
curl -X GET "http://127.0.0.1:8000/api/v1/inventory/stock?stock_status=unbalanced" \
  -H "Accept: application/json" -b cookies.txt
```

Expected: 200 dengan `data` kosong saat buku konsisten.

- [ ] **Step 6: Verifikasi skenario D — Riwayat movement**

```bash
curl -X GET "http://127.0.0.1:8000/api/v1/inventory/movements?movement_type=receipt" \
  -H "Accept: application/json" -b cookies.txt
```

Expected: 200, satu baris dari seed Phase 2.2 dengan `movement_type` = "receipt", `quantity_in` = "24.500", `ready_before` = "0.000", `ready_after` = "24.500", `batch_code` = "BATCH-202610-001", `recorded_by_name` terisi.

- [ ] **Step 7: Verifikasi skenario E — Karyawan ditolak**

Login sebagai karyawan, lalu `GET /api/v1/inventory/stock`.

Expected: 403 `FORBIDDEN`, `meta.request_id` ada, `data` tidak ada.

- [ ] **Step 8: Verifikasi skenario F — Buat draft koreksi**

Ambil `product_id` dan `stock_batch_id` dari `GET /api/v1/inventory/stock` (field `id` dan `GET /api/v1/products/{id}` atau query batch), lalu:

```bash
curl -X POST http://127.0.0.1:8000/api/v1/inventory/adjustments \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -b cookies.txt -H "X-XSRF-TOKEN: <TOKEN>" \
  -d '{"adjustment_date":"2026-10-22","reason":"Selisih hasil stock opname","items":[{"product_id":"<PRODUCT_ID>","stock_batch_id":"<BATCH_ID>","difference":"-0.500","note":"Kurang 0,5 kg"}]}'
```

Expected: 201, `data.adjustment_number` = "ADJ-2026-10-001", `data.status` = "draft", `data.items[0].system_quantity` = "24.500", `data.items[0].physical_quantity` = "24.000", `data.items[0].difference` = "-0.500".

- [ ] **Step 9: Verifikasi skenario G — Draft tidak mengubah stok**

`GET /api/v1/inventory/stock` lagi.

Expected: `ready_stock` masih "24.500", `batch_remaining` masih "24.500".

- [ ] **Step 10: Verifikasi skenario H — Batch dari produk lain ditolak**

Buat draft dengan `stock_batch_id` milik produk lain.

Expected: 422, `fields.items.0.stock_batch_id` = "Batch ini bukan milik produk yang dipilih."

- [ ] **Step 11: Verifikasi skenario I — Pengurangan melebihi sisa batch ditolak**

Buat draft dengan `difference` = "-999.000" pada batch bersisa 24,5.

Expected: 422, `fields.items.0.difference` memuat "Pengurangan melebihi sisa batch. Sisa batch 24.500 kg."

- [ ] **Step 12: Verifikasi skenario J — Selisih nol ditolak**

Buat draft dengan `difference` = "0".

Expected: 422, `fields.items.0.difference` ada.

- [ ] **Step 13: Verifikasi skenario K — Post tanpa Idempotency-Key ditolak**

```bash
curl -X POST http://127.0.0.1:8000/api/v1/inventory/adjustments/<ADJ_ID>/post \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -b cookies.txt -H "X-XSRF-TOKEN: <TOKEN>"
```

Expected: 400 `BAD_REQUEST`, pesan "Header Idempotency-Key wajib untuk operasi ini."

- [ ] **Step 14: Verifikasi skenario L — Post memindahkan stok**

```bash
curl -X POST http://127.0.0.1:8000/api/v1/inventory/adjustments/<ADJ_ID>/post \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -b cookies.txt -H "X-XSRF-TOKEN: <TOKEN>" -H "Idempotency-Key: post-001"
```

Lalu `GET /api/v1/inventory/stock`.

Expected: 200, `data.status` = "posted". `ready_stock` = "24.000", `batch_remaining` = "24.000", `movement_net` = "24.000", `is_balanced` = true (selisih nol — koreksi bergerak di ketiga sumber).

- [ ] **Step 15: Verifikasi skenario M — Post dengan key sama tidak menggandakan**

Ulangi persis perintah Step 14 dengan `Idempotency-Key: post-001` yang sama.

Expected: 200 dengan response identik, `ready_stock` **tetap** "24.000".

- [ ] **Step 16: Verifikasi skenario N — Post kedua pada dokumen yang sama**

`POST /inventory/adjustments/<ADJ_ID>/post` dengan key baru (`post-002`).

Expected: 409 `DOCUMENT_ALREADY_POSTED`, pesan "Koreksi ini sudah diposting dan tidak dapat diubah. Buat koreksi baru untuk membatalkannya."

- [ ] **Step 17: Verifikasi skenario O — Post tidak mengubah pending stock**

`GET /api/v1/inventory/stock` — kolom `pending_stock` dan `difference_pending` tetap sama seperti sebelum koreksi.

Expected: `pending_stock` tidak berubah.

- [ ] **Step 18: Verifikasi skenario P — Cancel draft**

Buat draft baru, lalu `POST /inventory/adjustments/<ADJ_ID>/cancel`.

Expected: 200, `data.status` = "cancelled", stok tidak berubah.

- [ ] **Step 19: Verifikasi skenario Q — Cancel koreksi yang sudah diposting ditolak**

`POST /inventory/adjustments/<ADJ_ID>/cancel` pada adjustment dari Step 14.

Expected: 409 `DOCUMENT_ALREADY_POSTED`, pesan "Koreksi yang sudah diposting tidak dapat dibatalkan. Buat koreksi baru untuk membatalkannya."

- [ ] **Step 17: Verifikasi skenario R — Update draft mengubah isi**

`PATCH /api/v1/inventory/adjustments/<ADJ_ID>` dengan `difference` baru.

Expected: 200, `data.items[0].difference` = nilai baru, `data.items[0].system_quantity` dihitung ulang dari `ready_stock` saat itu.

- [ ] **Step 18: Verifikasi skenario S — Update koreksi yang sudah diposting ditolak**

`PATCH /api/v1/inventory/adjustments/<ADJ_ID>` pada adjustment dari Step 14.

Expected: 409 `DOCUMENT_ALREADY_POSTED`.

- [ ] **Step 19: Verifikasi skenario T — Ledger dan movement tertulis**

```bash
cd /d/DEV/0.AI/Cinnamon2/backend && php artisan tinker --execute="
echo 'adjustments: '.App\Models\StockAdjustment::count().PHP_EOL;
echo 'movements: '.App\Models\StockMovement::count().PHP_EOL;
echo 'ledger: '.App\Models\LedgerEntry::count().PHP_EOL;
echo 'audits: '.App\Models\AuditLog::count().PHP_EOL;
echo App\Models\StockMovement::where('movement_type','adjustment_out')->first()?->movement_type->value.PHP_EOL;
"
```

Expected: `adjustments` ≥ 2, `movements` bertambah 1, `ledger` bertambah 1, `audits` bertambah ≥ 2. `movement_type` = `adjustment_out`.

- [ ] **Step 20: Verifikasi skenario U — Ledger koreksi keluar memakai credit**

```bash
cd /d/DEV/0.AI/Cinnamon2/backend && php artisan tinker --execute="
\$entry = App\Models\LedgerEntry::where('source_type','stock_adjustment')->first();
echo 'debit: '.\$entry->debit.PHP_EOL;
echo 'credit: '.\$entry->credit.PHP_EOL;
"
```

Expected: `debit: 0.00`, `credit: 21000.00` (0,500 kg × Rp 42.000).

- [ ] **Step 21: Verifikasi skenario V — Command rekonsiliasi**

```bash
cd /d/DEV/0.AI/Cinnamon2/backend && php artisan inventory:reconcile
```

Expected: exit code 0 dan pesan "Semua stok seimbang: proyeksi, batch, dan movement cocok." karena koreksi Step 14 bergerak di ketiga sumber sekaligus.

- [ ] **Step 22: Verifikasi skenario W — Command melaporkan selisih tanpa memperbaiki**

Buat selisih secara sengaja (ubah `products.ready_stock` langsung lewat tinker), jalankan `php artisan inventory:reconcile --all`.

Expected: tabel memuat baris dengan `Selisih batch` ≠ 0, exit code 1, dan **tidak ada** baris baru di `stock_movements` maupun `audit_logs` (perintah hanya membaca).

- [ ] **Step 23: Verifikasi skenario X — Batalkan selisih tadi dengan koreksi**

Buat draft koreksi untuk produk yang sama dengan `difference` yang kebalikan, post, lalu `php artisan inventory:reconcile`.

Expected: kembali seimbang, exit code 0.

- [ ] **Step 24: Matikan server**

```bash
pkill -f "php artisan serve"
```

---

## Task 10: Frontend Types dan API Client

**Files:**
- Modify: `frontend/src/lib/api/types.ts`
- Modify: `frontend/src/lib/api/client.ts`
- Modify: `frontend/src/lib/api/errors.ts` — tambah kode error baru ke `API_ERROR_CODES`

**Interfaces:**
- Consumes: kontrak API dari Task 7.
- Produces:
  - Types: `AdjustmentStatus` (`'draft' | 'posted' | 'cancelled'`), `StockOverviewRow`, `StockMovement`, `StockAdjustmentItem`, `StockAdjustment`.
  - Fungsi client:
    - `fetchStockOverview(page, filters?): Promise<PaginatedEnvelope<StockOverviewRow>>`
    - `fetchStockMovements(page, filters?): Promise<PaginatedEnvelope<StockMovement>>`
    - `fetchStockAdjustments(page, filters?): Promise<PaginatedEnvelope<StockAdjustment>>`
    - `fetchStockAdjustment(id): Promise<StockAdjustment>`
    - `createStockAdjustment(payload): Promise<StockAdjustment>`
    - `updateStockAdjustment(id, payload): Promise<StockAdjustment>`
    - `postStockAdjustment(id, idempotencyKey): Promise<StockAdjustment>`
    - `cancelStockAdjustment(id, reason?): Promise<StockAdjustment>`
  - `API_ERROR_CODES` ditambah: `'CONFLICT'`, `'DOCUMENT_ALREADY_POSTED'`, `'IDEMPOTENCY_CONFLICT'`, `'RECEIPT_ALREADY_APPROVED'`.

- [ ] **Step 1: Tambah types di `types.ts`**

Tambahkan di `frontend/src/lib/api/types.ts`:

```ts
export type AdjustmentStatus = 'draft' | 'posted' | 'cancelled'

export interface StockOverviewRow {
  id: string
  sku: string
  name: string
  unit: string
  category_name: string | null
  ready_stock: string
  pending_stock: string
  minimum_stock: string
  batch_remaining: string
  movement_net: string
  pending_weight: string
  difference_batch: string
  difference_movement: string
  difference_pending: string
  is_balanced: boolean
  is_low_stock: boolean
  batch_count: number
  is_active: boolean
  created_at: string
  updated_at: string
}

export interface StockMovement {
  id: string
  product_id: string
  product_name: string | null
  sku: string | null
  unit: string | null
  stock_batch_id: string | null
  batch_code: string | null
  movement_type: 'receipt' | 'sale' | 'adjustment_in' | 'adjustment_out'
  quantity_in: string
  quantity_out: string
  ready_before: string
  ready_after: string
  pending_before: string
  pending_after: string
  source_type: string | null
  source_id: string | null
  reason: string | null
  recorded_by: string
  recorded_by_name: string | null
  occurred_at: string
}

export interface StockAdjustmentItem {
  id: string
  product_id: string
  product_name: string | null
  sku: string | null
  unit: string | null
  stock_batch_id: string
  batch_code: string | null
  batch_remaining: string | null
  system_quantity: string
  physical_quantity: string
  difference: string
  note: string | null
  created_at: string
  updated_at: string
}

export interface StockAdjustment {
  id: string
  adjustment_number: string
  status: AdjustmentStatus
  adjustment_date: string
  reason: string
  created_by: string
  created_by_name: string | null
  posted_by: string | null
  posted_by_name: string | null
  posted_at: string | null
  items: StockAdjustmentItem[]
  created_at: string
  updated_at: string
}
```

- [ ] **Step 2: Tambah kode error di `errors.ts`**

Di `frontend/src/lib/api/errors.ts`, ubah `API_ERROR_CODES` menjadi:

```ts
export const API_ERROR_CODES = [
  'VALIDATION_ERROR',
  'UNAUTHENTICATED',
  'FORBIDDEN',
  'NOT_FOUND',
  'BAD_REQUEST',
  'RATE_LIMITED',
  'SERVER_ERROR',
  'CONFLICT',
  'RECEIPT_ALREADY_APPROVED',
  'DOCUMENT_ALREADY_POSTED',
  'IDEMPOTENCY_CONFLICT',
] as const
```

- [ ] **Step 3: Tambah fungsi client di `client.ts`**

Tambahkan di `frontend/src/lib/api/client.ts`:

```ts
import type {
  AdjustmentStatus,
  StockAdjustment,
  StockMovement,
  StockOverviewRow,
} from './types'

export interface StockOverviewFilters {
  search?: string
  category_id?: string
  stock_status?: 'low' | 'out' | 'unbalanced'
  is_active?: boolean
  sort?: string
}

export async function fetchStockOverview(
  page = 1,
  filters?: StockOverviewFilters,
): Promise<PaginatedEnvelope<StockOverviewRow>> {
  const { data } = await apiClient.get<PaginatedEnvelope<StockOverviewRow>>('/api/v1/inventory/stock', {
    params: { page, ...filters },
  })

  return data
}

export interface MovementFilters {
  product_id?: string
  movement_type?: string
  source_type?: string
  date_from?: string
  date_to?: string
  period?: string
  search?: string
  sort?: string
}

export async function fetchStockMovements(
  page = 1,
  filters?: MovementFilters,
): Promise<PaginatedEnvelope<StockMovement>> {
  const { data } = await apiClient.get<PaginatedEnvelope<StockMovement>>('/api/v1/inventory/movements', {
    params: { page, ...filters },
  })

  return data
}

export interface StockAdjustmentFilters {
  status?: AdjustmentStatus
  product_id?: string
  date_from?: string
  date_to?: string
  search?: string
  sort?: string
}

export async function fetchStockAdjustments(
  page = 1,
  filters?: StockAdjustmentFilters,
): Promise<PaginatedEnvelope<StockAdjustment>> {
  const { data } = await apiClient.get<PaginatedEnvelope<StockAdjustment>>('/api/v1/inventory/adjustments', {
    params: { page, ...filters },
  })

  return data
}

export async function fetchStockAdjustment(id: string): Promise<StockAdjustment> {
  const { data } = await apiClient.get<Envelope<StockAdjustment>>(`/api/v1/inventory/adjustments/${id}`)

  return data.data
}

export interface StockAdjustmentItemPayload {
  product_id: string
  stock_batch_id: string
  difference: string
  note?: string
}

export async function createStockAdjustment(payload: {
  adjustment_date: string
  reason: string
  items: StockAdjustmentItemPayload[]
}): Promise<StockAdjustment> {
  const { data } = await apiClient.post<Envelope<StockAdjustment>>('/api/v1/inventory/adjustments', payload)

  return data.data
}

export async function updateStockAdjustment(
  id: string,
  payload: {
    adjustment_date?: string
    reason?: string
    items?: StockAdjustmentItemPayload[]
  },
): Promise<StockAdjustment> {
  const { data } = await apiClient.patch<Envelope<StockAdjustment>>(
    `/api/v1/inventory/adjustments/${id}`,
    payload,
  )

  return data.data
}

export async function postStockAdjustment(
  id: string,
  idempotencyKey: string,
): Promise<StockAdjustment> {
  const { data } = await apiClient.post<Envelope<StockAdjustment>>(
    `/api/v1/inventory/adjustments/${id}/post`,
    {},
    { headers: { 'Idempotency-Key': idempotencyKey } },
  )

  return data.data
}

export async function cancelStockAdjustment(
  id: string,
  reason?: string,
): Promise<StockAdjustment> {
  const { data } = await apiClient.post<Envelope<StockAdjustment>>(
    `/api/v1/inventory/adjustments/${id}/cancel`,
    { reason },
  )

  return data.data
}
```

**Catatan.** `Idempotency-Key` dibuat sekali saat pengguna menekan tombol posting, bukan di dalam retry axios — sehingga klik ganda memakai key yang sama dan tidak menggandakan dampak.

- [ ] **Step 4: Jalankan pemeriksaan statis**

```bash
cd /d/DEV/0.AI/Cinnamon2/frontend && npx tsc -b && npm run lint
```

Expected: tanpa error.

- [ ] **Step 5: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add frontend/src/lib/api && git commit -m "frontend: add API client functions for stock overview, movements, and adjustments"
```

---

## Task 11: Komponen UI — `StatusBadge` untuk status Koreksi

**Files:**
- Modify: `frontend/src/components/ui/StatusBadge.tsx`
- Modify: `frontend/src/components/ui/StatusBadge.css`

**Interfaces:**
- Consumes: `StatusBadge` yang dibuat di Phase 2.2 Task 11.
- Produces:
  - `StatusBadge` menerima status dari dua enum: `ReceiptStatus | ReceiptItemStatus` (Phase 2.2) dan `AdjustmentStatus` (Phase 2.3). Signature jadi `{ status: ReceiptStatus | ReceiptItemStatus | AdjustmentStatus }`.
  - Label baru: `draft` → "Draft", `pending` → "Menunggu Verifikasi", `approved` → "Disetujui", `rejected` → "Ditolak", `cancelled` → "Dibatalkan", `posted` → "Diposting".

**Catatan.** `draft` sudah dipakai dokumen penerimaan dengan label "Draft"; koreksi memakai label yang sama karena maknanya sama — dokumen belum mengubah books. `posted` adalah label baru.

- [ ] **Step 1: Perbarui `StatusBadge.tsx`**

Ganti seluruh isi `frontend/src/components/ui/StatusBadge.tsx`:

```tsx
import type { AdjustmentStatus, ReceiptItemStatus, ReceiptStatus } from '../../lib/api/types'
import './StatusBadge.css'

const LABELS: Record<ReceiptStatus | ReceiptItemStatus | AdjustmentStatus, string> = {
  draft: 'Draft',
  pending: 'Menunggu Verifikasi',
  approved: 'Disetujui',
  rejected: 'Ditolak',
  cancelled: 'Dibatalkan',
  posted: 'Diposting',
}

export function StatusBadge({
  status,
}: {
  status: ReceiptStatus | ReceiptItemStatus | AdjustmentStatus
}) {
  return (
    <span className={`status-badge status-badge--${status}`}>
      <span className="status-badge__dot" aria-hidden="true" />
      {LABELS[status]}
    </span>
  )
}
```

- [ ] **Step 2: Tambahkan gaya `posted`**

Tambahkan di `frontend/src/components/ui/StatusBadge.css`:

```css
.status-badge--posted {
  color: var(--success-700);
  border-color: var(--success-700);
}
```

- [ ] **Step 3: Jalankan pemeriksaan statis**

```bash
cd /d/DEV/0.AI/Cinnamon2/frontend && npx tsc -b && npm run lint
```

Expected: tanpa error.

- [ ] **Step 4: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add frontend/src/components/ui && git commit -m "frontend: add posted status to the status badge"
```

---

## Task 12: Halaman Inventori (Ringkasan Stok, Movement, Koreksi)

**Files:**
- Create: `frontend/src/features/inventory/StockOverviewPage.tsx`
- Create: `frontend/src/features/inventory/MovementHistoryPage.tsx`
- Create: `frontend/src/features/inventory/AdjustmentListPage.tsx`
- Create: `frontend/src/features/inventory/AdjustmentFormPage.tsx`
- Create: `frontend/src/features/inventory/AdjustmentDetailPage.tsx`
- Create: `frontend/src/features/inventory/BatchPicker.tsx`
- Modify: `frontend/src/app/router.tsx`

**Interfaces:**
- Consumes: API client dari Task 10, `StatusBadge` dari Task 11, `ConfirmDialog` dan `Field` dari Phase 2.2 Task 11, `PageState` dari Phase 1, `formatQuantity`/`formatMoney` dari `frontend/src/lib/formatters/number.ts`, `Product` type dari Phase 2.1.
- Produces:
  - `StockOverviewPage` — tabel saldo stok. Kolom: Produk (nama + SKU), Kategori, Stok ready, Stok pending, Minimum, Sisa batch, Movement, Selisih, Status. Filter: pencarian, dropdown status (`low` / `out` / `unbalanced`), checkbox "hanya produk aktif". Baris dengan `is_balanced` = false diberi tanda "Selisih" — **label teks, bukan hanya warna** (`Docs/Design.md` §2).
  - `MovementHistoryPage` — tabel riwayat movement. Kolom: Waktu, Tipe, Produk, Batch, Masuk, Keluar, Ready sebelum → sesudah, Sumber, Aktor, Alasan. Filter: produk, tipe movement, sumber, rentang tanggal, pencarian.
  - `AdjustmentListPage` — daftar koreksi. Kolom: Nomor, Tanggal, Alasan, Status, Pembuat, Item, Aksi (link "Detail"). Filter: status, rentang tanggal, pencarian.
  - `AdjustmentFormPage` — form koreksi baru. Field: tanggal (default hari ini), alasan (wajib), baris item. Setiap baris: produk (dipilih lewat `ProductPicker` Phase 2.2), batch tujuan (dipilih lewat `BatchPicker`), selisih (boleh negatif), catatan. Subtotal tampilan memakai nilai server setelah disimpan.
  - `BatchPicker({ productId, onSelect })` — menampilkan batch `open` dari produk terpilih: kode batch, sisa, harga beli, tanggal masuk. Dipakai hanya setelah produk dipilih.
  - `AdjustmentDetailPage` — detail koreksi: header (nomor, status, tanggal, alasan, pembuat, poster + waktu), tabel item (produk, batch, sistem, fisik, selisih, catatan), dan panel aksi:
    - Draft → "Posting" (dengan `ConfirmDialog` yang menjelaskan dampaknya) dan "Batalkan".
    - Posted/Cancelled → read-only.

- [ ] **Step 1: Buat `BatchPicker`**

Buat `frontend/src/features/inventory/BatchPicker.tsx`:

```tsx
import { useQuery } from '@tanstack/react-query'
import { fetchProductBatches } from '../../lib/api/client'
import { formatMoney, formatQuantity } from '../../lib/formatters/number'
import './BatchPicker.css'

interface BatchOption {
  id: string
  batch_code: string
  quantity_remaining: string
  unit_cost: string
  received_at: string
}

interface BatchPickerProps {
  productId: string
  onSelect: (batch: BatchOption) => void
}

export function BatchPicker({ productId, onSelect }: BatchPickerProps) {
  const batchesQuery = useQuery({
    queryKey: ['product-batches', productId],
    queryFn: () => fetchProductBatches(productId),
    enabled: productId !== '',
  })

  if (productId === '') {
    return <p className="batch-picker__hint">Pilih produk lebih dulu.</p>
  }

  if (batchesQuery.isLoading) {
    return <p className="batch-picker__hint">Memuat batch…</p>
  }

  if (batchesQuery.isError) {
    return (
      <p className="batch-picker__hint" role="alert">
        Batch gagal dimuat. Silakan coba lagi.
      </p>
    )
  }

  const batches = batchesQuery.data

  if (!batches || batches.length === 0) {
    return <p className="batch-picker__hint">Produk ini tidak punya batch yang masih tersimpan.</p>
  }

  return (
    <ul className="batch-picker__list">
      {batches.map((batch) => (
        <li key={batch.id}>
          <button type="button" className="batch-picker__option" onClick={() => onSelect(batch)}>
            <span className="batch-picker__code">{batch.batch_code}</span>
            <span className="batch-picker__remaining">
              Sisa {formatQuantity(batch.quantity_remaining)}
            </span>
            <span className="batch-picker__cost">{formatMoney(batch.unit_cost)}</span>
            <span className="batch-picker__date">{batch.received_at}</span>
          </button>
        </li>
      ))}
    </ul>
  )
}
```

`BatchPicker` memakai `fetchProductBatches(productId)`, fungsi baru yang harus ditambahkan di Task 10. Tambahkan di `frontend/src/lib/api/client.ts`:

```ts
export interface ProductBatch {
  id: string
  product_id: string
  batch_code: string
  period_start: string
  period_end: string
  received_at: string
  quantity_received: string
  quantity_remaining: string
  unit_cost: string
  status: 'open' | 'depleted' | 'cancelled'
}

export async function fetchProductBatches(productId: string): Promise<ProductBatch[]> {
  const { data } = await apiClient.get<Envelope<ProductBatch[]>>(
    `/api/v1/products/${productId}/batches`,
  )

  return data.data
}
```

Backend-nya juga harus ada. Tambahkan ke `backend/routes/api.php`:

```php
Route::get('/products/{product}/batches', [ProductBatchController::class, 'index'])->middleware('auth:sanctum');
```

Dan buat `backend/app/Http/Controllers/Api/V1/ProductBatchController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Support\RequestId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductBatchController extends Controller
{
    /**
     * The batches of one product, for picking the batch a correction hits.
     */
    public function index(Request $request, Product $product): JsonResponse
    {
        $this->authorize('viewAny', StockAdjustment::class);

        $batches = $product->batches()
            ->where('status', 'open')
            ->orderBy('period_start')
            ->orderBy('batch_code')
            ->get();

        return response()->json([
            'data' => $batches->map(fn ($batch) => [
                'id' => $batch->id,
                'product_id' => $batch->product_id,
                'batch_code' => $batch->batch_code,
                'period_start' => $batch->period_start?->format('Y-m-d'),
                'period_end' => $batch->period_end?->format('Y-m-d'),
                'received_at' => $batch->received_at?->format('Y-m-d'),
                'quantity_received' => $batch->quantity_received,
                'quantity_remaining' => $batch->quantity_remaining,
                'unit_cost' => $batch->unit_cost,
                'status' => $batch->status,
            ]),
            'meta' => [
                'request_id' => RequestId::current(),
            ],
        ]);
    }
}
```

- [ ] **Step 2: Buat `StockOverviewPage`**

Buat `frontend/src/features/inventory/StockOverviewPage.tsx`:

```tsx
import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { PageState } from '../../components/feedback/PageState'
import { fetchStockOverview } from '../../lib/api/client'
import { formatQuantity } from '../../lib/formatters/number'
import './StockOverviewPage.css'

const STATUS_OPTIONS = [
  { value: '', label: 'Semua status' },
  { value: 'low', label: 'Stok menipis' },
  { value: 'out', label: 'Stok habis' },
  { value: 'unbalanced', label: 'Selisih' },
]

export function StockOverviewPage() {
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [stockStatus, setStockStatus] = useState('')

  const overviewQuery = useQuery({
    queryKey: ['stock-overview', page, search, stockStatus],
    queryFn: () =>
      fetchStockOverview(page, {
        search: search.trim() || undefined,
        stock_status: stockStatus === '' ? undefined : (stockStatus as 'low' | 'out' | 'unbalanced'),
      }),
  })

  const rows = overviewQuery.data?.data ?? []
  const meta = overviewQuery.data?.meta

  return (
    <section>
      <h1>Ringkasan Stok</h1>
      <p className="inventory__intro">
        Saldo stok siap jual dan menunggu verifikasi, dibandingkan dengan sisa batch dan riwayat movement.
        Selisih yang muncul di sini hanya dilaporkan — perbaikannya lewat koreksi stok.
      </p>

      <div className="inventory__filters">
        <label htmlFor="stock-search">Cari produk</label>
        <input
          id="stock-search"
          type="search"
          value={search}
          onChange={(event) => {
            setSearch(event.target.value)
            setPage(1)
          }}
        />

        <label htmlFor="stock-status">Status stok</label>
        <select
          id="stock-status"
          value={stockStatus}
          onChange={(event) => {
            setStockStatus(event.target.value)
            setPage(1)
          }}
        >
          {STATUS_OPTIONS.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>
      </div>

      {overviewQuery.isLoading && <PageState variant="loading" />}

      {overviewQuery.isError && (
        <PageState
          variant="error"
          message="Ringkasan stok gagal dimuat. Silakan coba lagi."
          onRetry={() => void overviewQuery.refetch()}
        />
      )}

      {overviewQuery.isSuccess && rows.length === 0 && (
        <PageState
          variant="empty"
          title="Belum ada produk"
          message="Tambahkan produk di Master Data lebih dulu sebelum melihat ringkasan stok."
        />
      )}

      {overviewQuery.isSuccess && rows.length > 0 && (
        <>
          <table className="inventory__table">
            <caption className="inventory__caption">Ringkasan stok per produk</caption>
            <thead>
              <tr>
                <th scope="col">Produk</th>
                <th scope="col">Stok ready</th>
                <th scope="col">Stok pending</th>
                <th scope="col">Minimum</th>
                <th scope="col">Sisa batch</th>
                <th scope="col">Selisih</th>
                <th scope="col">Status</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <tr key={row.id}>
                  <th scope="row">
                    {row.name}
                    <span className="inventory__sku">{row.sku}</span>
                  </th>
                  <td>
                    {formatQuantity(row.ready_stock)} {row.unit}
                  </td>
                  <td>
                    {formatQuantity(row.pending_stock)} {row.unit}
                  </td>
                  <td>
                    {formatQuantity(row.minimum_stock)} {row.unit}
                  </td>
                  <td>
                    {formatQuantity(row.batch_remaining)} {row.unit}
                  </td>
                  <td>
                    {row.is_balanced ? (
                      'Seimbang'
                    ) : (
                      <span className="inventory__mismatch">
                        Batch {formatQuantity(row.difference_batch)} · Movement{' '}
                        {formatQuantity(row.difference_movement)}
                      </span>
                    )}
                  </td>
                  <td>
                    {row.is_low_stock ? <span className="inventory__low">Stok menipis</span> : 'Normal'}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>

          {meta && meta.last_page > 1 && (
            <nav className="inventory__pagination" aria-label="Navigasi halaman">
              <button
                type="button"
                className="button button--secondary"
                disabled={meta.current_page <= 1}
                onClick={() => setPage((value) => value - 1)}
              >
                Sebelumnya
              </button>
              <span>
                Halaman {meta.current_page} dari {meta.last_page}
              </span>
              <button
                type="button"
                className="button button--secondary"
                disabled={meta.current_page >= meta.last_page}
                onClick={() => setPage((value) => value + 1)}
              >
                Berikutnya
              </button>
            </nav>
          )}
        </>
      )}
    </section>
  )
}
```

- [ ] **Step 3: Buat `MovementHistoryPage`**

Buat `frontend/src/features/inventory/MovementHistoryPage.tsx` dengan pola yang sama seperti `StockOverviewPage`. Tabel riwayat movement memakai kolom: Waktu (`occurred_at` diformat ke Asia/Jakarta lewat `Intl.DateTimeFormat`), Tipe (label Bahasa Indonesia: "Penerimaan", "Penjualan", "Koreksi Masuk", "Koreksi Keluar"), Produk, Batch, Masuk, Keluar, Ready sebelum → sesudah, Sumber, Aktor, Alasan. Filter: dropdown tipe movement, dropdown sumber, rentang tanggal, pencarian.

Label tipe movement:

```ts
const MOVEMENT_LABELS: Record<string, string> = {
  receipt: 'Penerimaan',
  sale: 'Penjualan',
  adjustment_in: 'Koreksi Masuk',
  adjustment_out: 'Koreksi Keluar',
}
```

- [ ] **Step 4: Buat `AdjustmentListPage`**

Buat `frontend/src/features/inventory/AdjustmentListPage.tsx` dengan pola yang sama seperti `StockOverviewPage`. Tabel: Nomor, Tanggal, Alasan, Item, Status (pakai `StatusBadge`), Pembuat, Aksi (link "Detail"). Filter: dropdown status, rentang tanggal, pencarian. Di atas tabel ada tombol "Koreksi Baru" yang menaut ke `/inventory/adjustments/new`.

- [ ] **Step 5: Buat `AdjustmentFormPage`**

Buat `frontend/src/features/inventory/AdjustmentFormPage.tsx`:

```tsx
import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { PageState } from '../../components/feedback/PageState'
import { Field } from '../../components/ui/Field'
import { createStockAdjustment, toApiError } from '../../lib/api/client'
import type { ApiError } from '../../lib/api/errors'
import { ProductPicker } from '../receiving/ProductPicker'
import { BatchPicker } from './BatchPicker'
import './AdjustmentFormPage.css'

interface DraftAdjustmentLine {
  key: string
  product_id: string
  product_name: string
  stock_batch_id: string
  batch_code: string
  difference: string
  note: string
}

export function AdjustmentFormPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [adjustmentDate, setAdjustmentDate] = useState(todayInJakarta())
  const [reason, setReason] = useState('')
  const [lines, setLines] = useState<DraftAdjustmentLine[]>([])
  const [failure, setFailure] = useState<string | null>(null)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({})

  const createMutation = useMutation({
    mutationFn: () =>
      createStockAdjustment({
        adjustment_date: adjustmentDate,
        reason: reason.trim(),
        items: lines.map((line) => ({
          product_id: line.product_id,
          stock_batch_id: line.stock_batch_id,
          difference: line.difference,
          note: line.note.trim() || undefined,
        })),
      }),
    onSuccess: async (adjustment) => {
      setFailure(null)
      setFieldErrors({})

      await queryClient.invalidateQueries({ queryKey: ['stock-adjustments'] })
      await queryClient.invalidateQueries({ queryKey: ['stock-overview'] })

      navigate(`/inventory/adjustments/${adjustment.id}`)
    },
    onError: (error) => {
      const apiError: ApiError = toApiError(error)
      setFailure(apiError.message)
      setFieldErrors(apiError.fields)
    },
  })

  const canSubmit = reason.trim() !== '' && lines.length > 0 && adjustmentDate !== ''

  return (
    <section>
      <h1>Koreksi Stok Baru</h1>
      <p className="inventory__intro">
        Koreksi dicatat untuk batch tertentu supaya sisa batch dan stok siap jual selalu bisa dibandingkan.
        Dokumen ini belum mengubah stok sampai diposting.
      </p>

      {failure && (
        <p className="inventory__error" role="alert">
          {failure}
        </p>
      )}

      <form
        className="inventory__form"
        onSubmit={(event) => {
          event.preventDefault()
          createMutation.mutate()
        }}
      >
        <Field label="Tanggal koreksi" htmlFor="adjustment-date" required error={fieldErrors.adjustment_date?.[0]}>
          <input
            id="adjustment-date"
            type="date"
            value={adjustmentDate}
            onChange={(event) => setAdjustmentDate(event.target.value)}
            required
          />
        </Field>

        <Field label="Alasan" htmlFor="adjustment-reason" required error={fieldErrors.reason?.[0]}>
          <textarea
            id="adjustment-reason"
            value={reason}
            onChange={(event) => setReason(event.target.value)}
            rows={3}
            maxLength={255}
            required
          />
        </Field>

        {lines.map((line, index) => (
          <fieldset key={line.key} className="inventory__line">
            <legend>Baris {index + 1}</legend>
            <p>
              {line.product_name} · {line.batch_code}
            </p>
            <Field label="Selisih (kg)" htmlFor={`difference-${line.key}`} required>
              <input
                id={`difference-${line.key}`}
                type="number"
                step="0.001"
                value={line.difference}
                onChange={(event) =>
                  setLines((current) =>
                    current.map((item) =>
                      item.key === line.key ? { ...item, difference: event.target.value } : item,
                    ),
                  )
                }
                required
              />
            </Field>
            <Field label="Catatan" htmlFor={`note-${line.key}`}>
              <input
                id={`note-${line.key}`}
                value={line.note}
                onChange={(event) =>
                  setLines((current) =>
                    current.map((item) =>
                      item.key === line.key ? { ...item, note: event.target.value } : item,
                    ),
                  )
                }
              />
            </Field>
            <button
              type="button"
              className="button button--ghost"
              onClick={() => setLines((current) => current.filter((item) => item.key !== line.key))}
            >
              Hapus baris
            </button>
          </fieldset>
        ))}

        <ProductPicker
          onSelect={(product) => {
            setLines((current) => [
              ...current,
              {
                key: `${product.id}-${current.length}-${Date.now()}`,
                product_id: product.id,
                product_name: product.name,
                stock_batch_id: '',
                batch_code: '',
                difference: '',
                note: '',
              },
            ])
          }}
        />

        {lines
          .filter((line) => line.stock_batch_id === '')
          .map((line) => (
            <div key={`batch-${line.key}`} className="inventory__batch">
              <p>Pilih batch untuk {line.product_name}:</p>
              <BatchPicker
                productId={line.product_id}
                onSelect={(batch) =>
                  setLines((current) =>
                    current.map((item) =>
                      item.key === line.key
                        ? { ...item, stock_batch_id: batch.id, batch_code: batch.batch_code }
                        : item,
                    ),
                  )
                }
              />
            </div>
          ))}

        {fieldErrors.items && (
          <p className="inventory__error" role="alert">
            {fieldErrors.items[0]}
          </p>
        )}

        <div className="inventory__actions">
          <button type="submit" className="button button--primary" disabled={!canSubmit || createMutation.isPending}>
            {createMutation.isPending ? 'Menyimpan…' : 'Simpan Draft'}
          </button>
        </div>
      </form>
    </section>
  )
}

function todayInJakarta(): string {
  return new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Jakarta',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(new Date())
}
```

**Catatan.** `BatchPicker` dipakai per baris; baris yang belum punya batch menampilkan pemilih batch di bawah daftar baris.

- [ ] **Step 6: Buat `AdjustmentDetailPage`**

Buat `frontend/src/features/inventory/AdjustmentDetailPage.tsx`. Ambil data dengan `useQuery({ queryKey: ['stock-adjustment', id], queryFn: () => fetchStockAdjustment(id) })`.

Panel aksi `posted` — dialog konfirmasi menjelaskan dampaknya:

> "Memposting koreksi ini akan mengubah stok siap jual, sisa batch, riwayat movement, dan buku ledger. Tindakan ini tidak dapat dibatalkan; jika ternyata salah, buat koreksi baru dengan selisih yang berlawanan."

Tombol "Posting" memakai `ConfirmDialog` dan mengirim `Idempotency-Key` yang dibuat saat dialog dibuka:

```tsx
const idempotencyKeyRef = useRef<string>('')

function openPost() {
  idempotencyKeyRef.current = crypto.randomUUID()
  setConfirmPost(true)
}

const postMutation = useMutation({
  mutationFn: () => postStockAdjustment(id, idempotencyKeyRef.current),
  onSuccess: async () => {
    setConfirmPost(false)
    await queryClient.invalidateQueries({ queryKey: ['stock-adjustment', id] })
    await queryClient.invalidateQueries({ queryKey: ['stock-adjustments'] })
    await queryClient.invalidateQueries({ queryKey: ['stock-overview'] })
    await queryClient.invalidateQueries({ queryKey: ['stock-movements'] })
  },
})
```

- [ ] **Step 7: Tambahkan route**

Di `frontend/src/app/router.tsx`, tambahkan import:

```tsx
import { AdjustmentDetailPage } from '../features/inventory/AdjustmentDetailPage'
import { AdjustmentFormPage } from '../features/inventory/AdjustmentFormPage'
import { AdjustmentListPage } from '../features/inventory/AdjustmentListPage'
import { MovementHistoryPage } from '../features/inventory/MovementHistoryPage'
import { StockOverviewPage } from '../features/inventory/StockOverviewPage'
```

Lalu di dalam `children` `AppShell`:

```tsx
{ path: 'inventory/stock', element: <StockOverviewPage /> },
{ path: 'inventory/movements', element: <MovementHistoryPage /> },
{ path: 'inventory/adjustments', element: <AdjustmentListPage /> },
{ path: 'inventory/adjustments/new', element: <AdjustmentFormPage /> },
{ path: 'inventory/adjustments/:id', element: <AdjustmentDetailPage /> },
```

Route `inventory/adjustments/new` harus **sebelum** `inventory/adjustments/:id` supaya path statis menang.

- [ ] **Step 8: Jalankan pemeriksaan statis**

```bash
cd /d/DEV/0.AI/Cinnamon2/frontend && npx tsc -b && npm run lint && npm run build
```

Expected: tanpa error, build sukses.

- [ ] **Step 9: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add frontend/src/features/inventory frontend/src/app frontend/src/lib/api && git commit -m "frontend: add inventory screens for stock overview, movements, and corrections"
```

---

## Task 13: Verifikasi Live End-to-End dan Dokumentasi

**Files:**
- Modify: `backend/database/seeders/DatabaseSeeder.php`
- Create: `Docs/phase-2-3-done.md`
- Modify: `Docs/roadmap.md`
- Modify: `Docs/Database.md`
- Modify: `Docs/decisions.md`

**Interfaces:**
- Consumes: hasil verifikasi Task 9 dan Task 12.
- Produces: seeder demo yang memuat satu draft koreksi (agar Phase 3.1 punya contoh adjustment), dokumen `Docs/phase-2-3-done.md`, dan pembaruan dokumen acuan.

- [ ] **Step 1: Tambahkan seeder demo koreksi**

Di `backend/database/seeders/DatabaseSeeder.php`, tambahkan setelah seeding penerimaan dari Phase 2.2 Task 13. Nama variabel mengikuti penamaan di Phase 2.1 (`$admin`, `$pala`, `$supplier1`) dan Phase 2.2 (`$receipt`, `$item`, `$batch`):

```php
use App\Enums\AdjustmentStatus;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;

        $adjustment = StockAdjustment::query()->create([
            'adjustment_number' => 'ADJ-2026-10-001',
            'status' => AdjustmentStatus::Draft,
            'adjustment_date' => '2026-10-20',
            'reason' => 'Selisih hasil stock opname akhir bulan',
            'created_by' => $admin->id,
        ]);

        StockAdjustmentItem::query()->create([
            'stock_adjustment_id' => $adjustment->id,
            'product_id' => $pala->id,
            'stock_batch_id' => $batch->id,
            'system_quantity' => '24.500',
            'physical_quantity' => '24.000',
            'difference' => '-0.500',
            'note' => 'Kurang 0,5 kg saat ditimbang ulang',
        ]);
```

Koreksi sengaja dibiarkan **draft** — dokumen draft tidak menyentuh stok, jadi data seed tetap konsisten dengan proyeksi.

- [ ] **Step 2: Jalankan seeder dan cek hasil**

```bash
cd /d/DEV/0.AI/Cinnamon2/backend && php artisan db:seed
```

Expected: 1 draft koreksi, `stock_adjustments` berisi 1 baris status `draft`, dan `products.ready_stock` tetap "24.500".

- [ ] **Step 3: Verifikasi browser — alur lengkap**

```bash
# Terminal 1
cd /d/DEV/0.AI/Cinnamon2/backend && php artisan serve

# Terminal 2
cd /d/DEV/0.AI/Cinnamon2/frontend && npm run dev
```

Buka `http://localhost:5173`, login sebagai Admin:

| # | Pemeriksaan | Harap |
|---|---|---|
| A | Buka "Inventori > Ringkasan Stok" | Tabel stok tampil, kolom Stok ready, Sisa batch, Selisih ada |
| B | Produk seed (Pala) | Stok ready 24,500, sisa batch 24,500, selisih "Seimbang" |
| C | Filter "Selisih" | Tidak ada baris (buku konsisten) |
| D | Filter "Stok menipis" | Hanya produk dengan minimum stock |
| E | Buka "Inventori > Movement" | Riwayat movement menampilkan baris seed Phase 2.2 tipe "Penerimaan" |
| F | Filter tipe "Koreksi Keluar" | Kosong |
| G | Buka "Inventori > Koreksi" | Daftar koreksi menampilkan ADJ-2026-10-001 status "Draft" |
| H | Klik "Koreksi Baru" | Form tanggal, alasan, dan pemilih produk tampil |
| I | Pilih produk tanpa batch | Pemilih batch muncul dengan sisa dan harga beli |
| J | Isi alasan, selisih, simpan | Draft dibuat, halaman detail terbuka, status "Draft" |
| K | Detail draft menampilkan tombol "Posting" | Dialog konfirmasi menjelaskan dampak stok |
| L | Klik "Ya, posting" | Status jadi "Diposting", stok ready berubah sesuai selisih |
| M | Buka "Ringkasan Stok" lagi | Stok ready dan sisa batch berubah **seiring** — selisih tetap "Seimbang" |
| N | Buka "Movement" | Baris baru tipe "Koreksi Keluar" muncul dengan sebelum → sesudah |
| O | Kembali ke detail koreksi | Tombol "Posting" hilang (status bukan draft) |
| P | Ganti ke akun Karyawan | Menu "Inventori" tidak tampil di sidebar |
| Q | Karyawan mengetik `/inventory/stock` | Halaman "Akses tidak diizinkan" |
| R | Jendela 480px | Tabel dapat di-scroll horizontal, tombol tetap terjangkau |
| S | Console browser | 0 error, 0 exception |

- [ ] **Step 4: Jalankan suite regresi**

```bash
cd /d/DEV/0.AI/Cinnamon2/backend && php artisan test
cd /d/DEV/0.AI/Cinnamon2/frontend && npm test
```

Expected: 47 backend tests passed, 22 frontend tests passed.

- [ ] **Step 5: Jalankan pemeriksaan statis lengkap**

```bash
cd /d/DEV/0.AI/Cinnamon2/backend && ./vendor/bin/pint --test
cd /d/DEV/0.AI/Cinnamon2/frontend && npx tsc -b && npm run lint && npx prettier --check src && npm run build
```

Expected: semua bersih.

- [ ] **Step 6: Verifikasi migration fresh/rollback**

```bash
cd /d/DEV/0.AI/Cinnamon2/backend && php artisan migrate:fresh --seed
```

Expected: 20 tabel dibuat, 2 akun seed, seeder berjalan tanpa error, dan `php artisan inventory:reconcile` keluar dengan exit code 0.

- [ ] **Step 7: Perbarui `Docs/Database.md`**

Pada §4:

- Tabel `stock_adjustments`: tambahkan `adjustment_date DATE` dan catatan "`status` ENUM(`draft`,`posted`,`cancelled`)".
- Tabel `stock_adjustment_items`: tambahkan `stock_batch_id` FK ke `stock_batches.id` (wajib), dan jelaskan bahwa setiap koreksi menunjuk satu batch.
- Tambahkan catatan pada §5:

> Setiap koreksi stok menunjuk satu batch dan mengubah `stock_batches.quantity_remaining` beserta `products.ready_stock` dalam satu transaksi, sehingga `SUM(stock_batches.quantity_remaining)` sama dengan `products.ready_stock` untuk setiap produk. Koreksi masuk juga menaikkan `quantity_received` batch tersebut agar invarian `quantity_remaining ≤ quantity_received` tetap berlaku.

Pada §10 (Backup and recovery), tambahkan rujukan:

> `php artisan inventory:reconcile` membandingkan proyeksi stok, sisa batch, dan riwayat movement, lalu melaporkan selisihnya. Perintah ini tidak memperbaiki apa pun; perbaikan dilakukan dengan membuat dokumen koreksi stok.

- [ ] **Step 8: Perbarui `Docs/decisions.md`**

Perbaiki §6 dan tambahkan **§20** setelah §19:

Pada §6, ganti bagian "Reconciliation" dengan:

```markdown
**Reconciliation:** `saldo_ledger + nilai_stok − 2 × nilai_koreksi = laba_kumulatif + kas_awal`, dengan `nilai_stok` = jumlah `stock_batches.quantity_remaining × unit_cost` dan `nilai_koreksi` = nilai absolut seluruh entri koreksi stok. Faktor 2 muncul karena setiap koreksi bergerak dua kali di ledger: satu di sisi kas dan satu di sisi nilai stok.

Rumus lama (`balance == batch_qty_remaining × unit_cost + opening_kas`) tidak bisa berlaku bersama definisi debit/kredit di atas: saldo ledger adalah posisi kas dan nilai stok adalah nilai gudang, sehingga keduanya tidak pernah saling menggantikan. Yang dibetulkan di sini adalah rumusnya, bukan definisi debit/kredit.
```

Tambahkan **§20**:

```markdown
## 20. Fase 2.3 — Koreksi stok, batch, dan rekonsiliasi

**Koreksi stok selalu menunjuk satu batch.** Setiap baris koreksi menyebut `stock_batch_id`; pengurangan memotong `stock_batches.quantity_remaining` batch itu, penambahan menaikkan `quantity_remaining` **dan** `quantity_received` batch itu. Karena itu `SUM(stock_batches.quantity_remaining)` selalu sama dengan `products.ready_stock` — invarian yang membuat rekonsiliasi mungkin.

**Hanya bucket `ready` yang boleh dikoreksi.** Stok pending adalah reservasi yang menempel pada item penerimaan (`§15`); mengoreksinya langsung membuat proyeksi pending tidak sinkron dengan dokumennya.

**Tanda entri ledger koreksi:** koreksi masuk = `debit`, koreksi keluar = `credit`. Penandaan ini dipilih pemilik produk, dan konsekuensinya — rumus rekonsiliasi §6 perlu suku koreksi — dicatat di sana.

**Admin membuat dan mem-posting koreksinya sendiri.** Tidak ada pemisahan two-eyes di MVP. Kolom `posted_by` tetap dipisah dari `created_by` supaya pemisahan itu bisa ditambahkan tanpa migrasi.

**Koreksi yang sudah diposting tidak dapat dibatalkan.** Pembalikannya dibuat dengan dokumen koreksi baru memakai selisih yang berlawanan, supaya `stock_movements` tetap append-only dan kedua jejaknya terbaca.

**Rekonsiliasi melaporkan, tidak memperbaiki.** `GET /inventory/stock` mengembalikan selisih antara proyeksi, sisa batch, dan movement; `php artisan inventory:reconcile` mencetak detailnya. Keduanya tidak menulis apa pun ke database.
```

- [ ] **Step 9: Perbarui `Docs/roadmap.md`**

Pada bagian "Phase 2.3 — Stock overview dan koreksi", tambahkan:

```markdown
**Status: selesai (2026-10-22).** Ringkasan hasil dan verifikasi: `Docs/phase-2-3-done.md`.
```

- [ ] **Step 10: Buat `Docs/phase-2-3-done.md`**

Struktur sama seperti `Docs/phase-1-done.md`:

- Status: complete
- Yang dibangun (tabel area | hasil)
- Perubahan yang terlihat pengguna
- Verifikasi yang dijalankan (tabel API + tabel browser)
- Keputusan yang diambil saat pengerjaan
- Perbaikan dari review (jika ada)
- Belum dikerjakan
- Cara menjalankan lokal

- [ ] **Step 11: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add backend/database frontend/src Docs && git commit -m "docs: record Phase 2.3 completion and update reference documents"
```

---

## Catatan Implementasi

**Urutan task:** Task 1–8 backend (schema, domain, query, policy, action, controller, command), Task 9 verifikasi API, Task 10–12 frontend, Task 13 verifikasi end-to-end dan dokumentasi.

**Ketergantungan antar task:**

- Task 2 (domain) butuh Task 1 (model).
- Task 3 (policy/request/resource) butuh Task 1.
- Task 4 (query) butuh Task 1 dan model Phase 2.2 (`StockBatch`, `StockMovement`, `IncomingReceiptItem`).
- Task 5 (post/cancel/update) butuh Task 1, 2, dan enum dari Task 1.
- Task 6 (create) butuh Task 2 dan 5.
- Task 7 (controller) butuh Task 3, 4, 5, 6.
- Task 8 (command) butuh Task 2 dan model Phase 2.2.
- Task 9 butuh Task 7 dan 8.
- Task 10 butuh Task 7.
- Task 11 dan 12 bisa dikerjakan paralel setelah Task 10.
- Task 13 butuh semua sebelumnya.

**Pola yang dipakai:**

- Backend mengikuti pola Phase 1, 2.1, dan 2.2: Form Request untuk validasi, Policy untuk otorisasi, Controller untuk orkestrasi, Resource untuk serialisasi, Query untuk filter, Action di `app/Actions/Inventory/` untuk use case.
- Semua efek stok berada di `app/Actions/Inventory/` dan ditulis dalam satu `DB::transaction`. Controller tidak pernah mengubah stok langsung.
- Hitungan uang dan berat memakai `bcmath`; tidak ada float.
- Frontend mengikuti pola Phase 1 dan 2.2: TanStack Query untuk cache, React Router untuk navigasi, `PageState` untuk loading/empty/error, `Field` untuk pesan error per-field, `ConfirmDialog` untuk aksi berdampak.

**Yang tidak dikerjakan di Phase 2.3:**

- `GET /inventory/stock` tidak punya endpoint ringkasan angka agregat lintas produk (total stok, total nilai gudang). Endpoint laporan ada di Phase 3.2.
- `stock_batches.status` hanya berubah ke `depleted` saat sisa batch nol. Status `cancelled` untuk batch belum dipakai — pembatalan batch adalah bagian dari reversal penjualan di Phase 3.1.
- Koreksi tidak menyentuh bucket `pending`, jadi `difference_pending` pada Stock Overview hanya membandingkan proyeksi pending dengan item penerimaan yang masih menunggu verifikasi, bukan koreksi.
- Rekonsiliasi tidak punya versi "perbaiki otomatis". Perbaikannya selalu lewat dokumen koreksi.
- `POST /products/{product}/batches` adalah endpoint baca tambahan yang dipakai form koreksi; tidak ada endpoint ringkasan batch global.
