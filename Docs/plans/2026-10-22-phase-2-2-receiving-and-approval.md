# Phase 2.2 — Penerimaan dan Approval Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mencatat barang masuk sebagai dokumen penerimaan dan mengubahnya menjadi stok siap jual hanya melalui alur verifikasi yang diaudit: draft → pending → approved (stok ready naik) atau rejected (stok tidak naik).

**Architecture:** Backend Laravel 13 adalah sumber kebenaran. Satu `Action` per transisi status memegang batas transaksi (`SubmitReceipt`, `ApproveReceipt`, `RejectReceipt`, `CancelReceipt`), dan setiap efek stok (proyeksi `products.pending_stock`/`ready_stock`, baris `stock_batches`, `stock_movements`, `ledger_entries`, `audit_logs`) ditulis dalam transaksi yang sama. Penomoran dokumen dan perhitungan subtotal memakai `bcmath` di server; browser hanya mengirim intent. Endpoint `verify` dan `cancel` memakai header `Idempotency-Key` dengan tabel `idempotency_keys` agar retry tidak menggandakan dampak stok.

**Tech Stack:**
- Backend: Laravel 13, PHP 8.5.11, MySQL 8 (`cinnamon_v2`), UUID v7 primary keys, `bcmath`
- Frontend: Vite 8.3, React 19, TypeScript 6, React Router DOM 7, TanStack Query 5, Axios
- Testing: Suite yang ada (47 backend, 22 frontend) sebagai jaring regresi; fitur baru diverifikasi dengan menjalankan aplikasi (live API + browser)

**Spec:** `Docs/PRD.md` §4, §6.3, §8, §9, §10, §11; `Docs/API.md` §2, §3, §4.2, §5.1, §5.2, §6, §7, §8, §10; `Docs/Database.md` §4 (tabel `incoming_receipts`, `incoming_receipt_items`, `stock_batches`, `stock_movements`, `ledger_entries`, `audit_logs`), §5, §6, §7; `Docs/Architecture.md` §2, §4, §5, §6, §7, §8, §9, §10, §14; `Docs/Design.md` §3, §4, §5, §6, §9, §10; `Docs/roadmap.md` "Phase 2.2 — Penerimaan dan approval"; `Docs/AGENTS.md` §3, §4, §5, §6, §7, §8; `Docs/decisions.md` §1, §2, §3, §6, §7, §9, §10.

**Ketergantungan keras:** Plan ini mengasumsikan **Phase 2.1 sudah dieksekusi** (tabel `categories`, `products`, `suppliers`, `customers` dan modelnya sudah ada). Migration Phase 2.2 memakai `000005`–`000011` karena `000001`–`000004` dipakai Phase 2.1. Kalau Phase 2.1 belum dijalankan, kerjakan plan `Docs/plans/2026-10-22-phase-2-1-catalog-and-parties.md` lebih dulu.

## Global Constraints

- **Tidak ada file test baru.** Suite yang ada tetap jalan sebagai jaring regresi sebelum commit, tapi tidak diperluas — `Docs/decisions.md` §10. Setiap task diverifikasi dengan menjalankan aplikasinya.
- Prefix API `/api/v1`. Envelope sukses `{ "data": …, "meta": { "request_id": "…" } }`; envelope error `{ "error": { "code", "message", "fields", "details" }, "meta": { "request_id" } }`.
- Mapping status error (dipin dari `Docs/API.md` §7): `VALIDATION_ERROR` → 422, `UNAUTHENTICATED` → 401, `FORBIDDEN` → 403, `NOT_FOUND` → 404, `BAD_REQUEST` → 400, `RATE_LIMITED` → 429, `SERVER_ERROR` → 500. Kode khusus yang dipakai fase ini: `RECEIPT_ALREADY_APPROVED` → 409, `IDEMPOTENCY_CONFLICT` → 409.
- **Kode produksi backend ditulis langsung di tempatnya** — `backend/app/…`, `backend/routes/`, `backend/database/`, `backend/bootstrap/`. Kode test hanya di `backend/tests/`.
- UUID v7 via `HasUuids` untuk semua primary key **dan** untuk kolom polimorfik (`source_id`, `resource_id`, `receipts.product_id`, dsb.).
- Timestamp diserialisasi lewat `App\Support\Timestamp::iso8601()` (Asia/Jakarta, `+07:00`).
- `lang/id/validation.php` + `APP_LOCALE=id` — pesan validasi tampil apa adanya di UI.
- Money 2 desimal, quantity 3 desimal, keduanya diserialisasi sebagai **string**. Hitungannya memakai `bcmath`, bukan float.
- Dua role saja: `admin` dan `karyawan`.
- Bahasa antarmuka: Bahasa Indonesia.
- Extension PHP `intl` **tidak terpasang** — jangan pakai `Illuminate\Support\Number::format()`, pakai `number_format()`.
- Frontend harus lolos `tsc -b` dan ESLint flat config yang sudah ada **tanpa menambah rule**.
- Tidak ada dependensi baru di luar Tech Stack.
- Database `cinnamon` berisi fixture TA legacy. **Tidak boleh** dipakai dan tidak boleh dihapus.
- **Tidak ada hard delete pada dokumen penerimaan.** Status `cancelled` dan `rejected` adalah akhir alur; koreksi dilakukan lewat dokumen baru.
- `Idempotency-Key` **wajib** pada `verify` dan `cancel`. Tanpa header → `400 BAD_REQUEST`.
- Key yang sama dengan payload berbeda → `409 IDEMPOTENCY_CONFLICT`. Key yang sama dengan payload sama → response asli diputar ulang tanpa efek samping.

---

## Deviasi dari dokumen acuan (perlu dibaca sebelum mulai)

Empat titik di mana dokumen acuan tidak konsisten atau belum lengkap. Semuanya diselesaikan di dalam plan ini dengan alasan tertulis; tidak ada yang mengubah aturan stok atau hak akses tanpa persetujuan eksplisit (lihat blok "Keputusan yang sudah dikonfirmasi" di bawah).

| # | Konflik | Penyelesaian di plan ini | Alasan |
|---|---|---|---|
| 1 | `Docs/Database.md` §4 menetapkan `incoming_receipts.status` = `draft, pending, approved, cancelled` (tanpa `rejected`), sementara `Docs/API.md` §5.2 menyebut `decision` boleh `"approved"` atau `"rejected"` dan mewajibkan alasan saat penolakan. | Enum status `incoming_receipts` dibuat **`draft, pending, approved, rejected, cancelled`**. | Dua status berbeda perlu dibedakan: `rejected` = verifikasi gagal (barang kembali ke supplier, alasan wajib disimpan di `verification_note`), `cancelled` = dokumen dibatalkan Admin/pembuat. `Docs/Database.md` §4 diperbarui pada Task 13 untuk mencerminkan enum ini. |
| 2 | `Docs/Database.md` §4 memakai `source_id BIGINT` dan `resource_id BIGINT` untuk tabel polimorfik, sementara `Docs/decisions.md` §5 menetapkan UUID v7 untuk seluruh tabel. | Semua kolom polimorfik memakai `uuid` (`char(36)`). | `Docs/decisions.md` §5 sudah menetapkan UUID v7 untuk "every subsequent table's id"; kolom `source_id`/`resource_id` adalah foreign key polimorfik yang menunjuk id tabel lain, jadi harus mengikuti format yang sama. |
| 3 | `Docs/Database.md` §4 menyatakan batch memakai periode tanggal 1–15 dan 16–akhir bulan, sementara `Docs/decisions.md` §1 menetapkan satu batch per item penerimaan. | Setiap item penerimaan yang disetujui membuat **satu batch sendiri** dengan `batch_code` unik; `period_start`/`period_end` tetap diisi dari tanggal dokumen sebagai informasi periode bisnis. | `Docs/decisions.md` §1 sudah mengikat: `stock_batches.incoming_receipt_item_id` UNIQUE. Periode tetap dicatat agar laporan periode (Phase 3.2) punya dasar. |
| 4 | `Docs/API.md` §8 menyebut "periode retensi yang ditetapkan" untuk penyimpanan idempotensi, tanpa memberi angka. | Retensi **24 jam**,bage cleanup dilakukan lazy saat key baru disimpan. | Angka tetap perlu ada; 24 jam cukup untuk retry dan window idempotensi. Dicatat sebagai keputusan di `Docs/decisions.md` §11 pada Task 13. |

### Keputusan yang sudah dikonfirmasi (hasil brainstorming bersama pengguna)

1. **Alur berat.** Karyawan membuat draft dengan `weight_initial`. Selama status `pending`, Karyawan atau Admin boleh memperbarui `weight_final`. Admin menekan Approve untuk mengunci nilai itu menjadi batch dan stok ready.
2. **Nomor dokumen dibuat saat `POST` (draft).** Format `RC-2026-10-001` (receipt) dan `BATCH-202610-001` (batch). Nomor sudah ada saat dokumen masih draft supaya bisa dipakai komunikasi di lapangan.
3. **Stok pending naik saat `draft → pending`.** `products.pending_stock` bertambah sebesar berat efektif item. Berat efektif = `weight_final` bila ada, jika tidak `weight_initial`. Saat approval, `pending_stock` turun dan `ready_stock` naik sebesar `weight_final`.
4. **Supplier dan produk wajib dari master data yang sudah ada.** Tidak ada produk yang dibuat otomatis dari layar penerimaan. Supplier yang tidak aktif tidak boleh dipilih.
5. **Draft bisa diubah hanya selama status `draft`.** Setelah `pending`, Admin yang boleh Approve/Reject. Untuk merevisi, Admin menolak dokumen (`rejected`) lalu membukanya kembali ke `draft`, atau membatalkannya.

---

## Review Focus

Lima kondisi yang paling mungkin menggigit pengguna nyata. Masing-masing harus dibuktikan oleh langkah verifikasi di task yang memiliki kodenya.

1. **Approval dikirim dua kali (klik ganda atau retry jaringan)** → stok ready naik **satu kali**. Bukti: Task 7 (idempotency key sama) dan Task 6 (status dicek di dalam transaksi dengan `lockForUpdate`).
2. **Karyawan mencoba menyetujui penerimaan** → `403 FORBIDDEN` dengan `meta.request_id`, bukan 401 dan bukan 500. Bukti: Task 5.
3. **Berat final diubah saat status `pending`** → `products.pending_stock` ikut menyesuaikan (tidak sampai negatif, tidak dobel), dan tidak ada `stock_movement` baru: stok pending adalah proyeksi, bukan movement. Bukti: Task 9 skenario M.
4. **Penerimaan ditolak** → `ready_stock` tidak berubah, `pending_stock` kembali seperti semula, alasan tersimpan, dan tidak ada batch yang dibuat. Bukti: Task 7.
5. **Dua admin menyetujui dokumen yang sama bersamaan** → hanya satu berhasil; yang lain menerima `409 RECEIPT_ALREADY_APPROVED`, dan stok tidak naik dua kali. Bukti: Task 6.

---

## Peta File

```
backend/
  app/
    Actions/Receiving/
      SubmitReceipt.php          (BARU)  draft -> pending, naikkan pending_stock
      ApproveReceipt.php         (BARU)  pending -> approved, batch+movement+ledger+audit
      RejectReceipt.php          (BARU)  pending -> rejected, lepaskan pending_stock
      CancelReceipt.php          (BARU)  draft/pending -> cancelled
      UpdatePendingItem.php      (BARU)  ubah weight_final saat pending
      UpdateReceipt.php          (BARU)  ubah field & item draft
    Domain/Receiving/
      ReceiptCalculator.php      (BARU)  berat efektif, subtotal, total (bcmath)
      DocumentNumbers.php        (BARU)  receipt_number & batch_code
    Enums/
      ReceiptStatus.php          (BARU)
      ReceiptItemStatus.php      (BARU)
      MovementType.php           (BARU)
    Http/Controllers/Api/V1/
      IncomingReceiptController.php (BARU)
      PendingProductController.php  (BARU)
    Http/Requests/Receiving/
      StoreIncomingReceiptRequest.php  (BARU)
      UpdateIncomingReceiptRequest.php (BARU)
      VerifyReceiptRequest.php         (BARU)
      UpdatePendingItemRequest.php     (BARU)
    Http/Resources/
      IncomingReceiptResource.php      (BARU)
      IncomingReceiptItemResource.php  (BARU)
      PendingProductResource.php       (BARU)
    Models/
      IncomingReceipt.php        (BARU)
      IncomingReceiptItem.php    (BARU)
      StockBatch.php             (BARU)
      StockMovement.php          (BARU)
      LedgerEntry.php            (BARU)
      AuditLog.php               (BARU)
      IdempotencyKey.php         (BARU)
      Product.php                (MODIFIKASI) + relasi incomingReceiptItems()
      Supplier.php               (MODIFIKASI) + relasi incomingReceipts()
    Policies/
      IncomingReceiptPolicy.php  (BARU)
    Queries/
      IncomingReceiptQuery.php   (BARU)
      PendingProductQuery.php    (BARU)
    Support/
      Idempotency.php            (BARU)
  database/
    migrations/
      2026_10_22_000005_create_incoming_receipts_table.php        (BARU)
      2026_10_22_000006_create_incoming_receipt_items_table.php    (BARU)
      2026_10_22_000007_create_stock_batches_table.php             (BARU)
      2026_10_22_000008_create_stock_movements_table.php           (BARU)
      2026_10_22_000009_create_ledger_entries_table.php           (BARU)
      2026_10_22_000010_create_audit_logs_table.php               (BARU)
      2026_10_22_000011_create_idempotency_keys_table.php          (BARU)
    seeders/
      DatabaseSeeder.php         (MODIFIKASI) + saldo awal kas + demo penerimaan
  routes/
    api.php                      (MODIFIKASI)

frontend/
  src/
    app/
      router.tsx                 (MODIFIKASI)
    components/
      ui/
        StatusBadge.tsx          (BARU)
        ConfirmDialog.tsx        (BARU)
        Field.tsx                (BARU)
    features/
      receiving/
        ReceivingNewPage.tsx     (BARU)
        ReceivingPendingPage.tsx (BARU)
        ReceivingHistoryPage.tsx (BARU)
        ReceiptDetailPage.tsx    (BARU)
        LineItemEditor.tsx       (BARU)
        ProductPicker.tsx        (BARU)
    lib/
      api/
        client.ts                (MODIFIKASI)
        types.ts                 (MODIFIKASI)
```

---

## Task 1: Migration dan Model untuk Penerimaan

**Files:**
- Create: `backend/database/migrations/2026_10_22_000005_create_incoming_receipts_table.php`
- Create: `backend/database/migrations/2026_10_22_000006_create_incoming_receipt_items_table.php`
- Create: `backend/app/Enums/ReceiptStatus.php`
- Create: `backend/app/Enums/ReceiptItemStatus.php`
- Create: `backend/app/Models/IncomingReceipt.php`
- Create: `backend/app/Models/IncomingReceiptItem.php`
- Modify: `backend/app/Models/Product.php` — tambah relasi `incomingReceiptItems()`
- Modify: `backend/app/Models/Supplier.php` — tambah relasi `incomingReceipts()`

**Interfaces:**
- Consumes: `App\Models\Product`, `App\Models\Supplier`, `App\Models\User` (Phase 2.1 / Phase 0).
- Produces:
  - Tabel `incoming_receipts`: `id` (uuid PK), `receipt_number` (varchar 40 unique), `supplier_id` (uuid FK → `suppliers.id`), `receipt_date` (date), `status` (enum `draft,pending,approved,rejected,cancelled`, default `draft`), `total_weight` (decimal 15,3 default 0), `total_cost` (decimal 15,2 default 0), `notes` (text nullable), `verification_note` (text nullable), `created_by` (uuid FK → `users.id`), `approved_by` (uuid FK → `users.id` nullable), `approved_at` (datetime nullable), timestamps. Index `(status, receipt_date)` dan `(supplier_id, receipt_date)`.
  - Tabel `incoming_receipt_items`: `id` (uuid PK), `incoming_receipt_id` (uuid FK → `incoming_receipts.id`, cascade delete), `product_id` (uuid FK → `products.id`), `weight_initial` (decimal 15,3), `weight_final` (decimal 15,3 nullable), `unit_cost` (decimal 15,2), `subtotal` (decimal 15,2 default 0), `status` (enum `pending,approved,rejected`, default `pending`), `verification_note` (text nullable), timestamps. Index `(incoming_receipt_id)` dan `(product_id, status)`.
  - `App\Enums\ReceiptStatus`: backed string enum — `Draft='draft'`, `Pending='pending'`, `Approved='approved'`, `Rejected='rejected'`, `Cancelled='cancelled'`.
  - `App\Enums\ReceiptItemStatus`: backed string enum — `Pending='pending'`, `Approved='approved'`, `Rejected='rejected'`.
  - `App\Models\IncomingReceipt` — fillable `['receipt_number','supplier_id','receipt_date','status','total_weight','total_cost','notes','verification_note','created_by','approved_by','approved_at']`; relasi `supplier()`, `items()`, `creator()`, `approver()`; cast `status` → `ReceiptStatus`, `receipt_date` → `date`, `total_weight` → `decimal:3`, `total_cost` → `decimal:2`, `approved_at` → `datetime`.
  - `App\Models\IncomingReceiptItem` — fillable `['incoming_receipt_id','product_id','weight_initial','weight_final','unit_cost','subtotal','status','verification_note']`; relasi `receipt()`, `product()`; cast `status` → `ReceiptItemStatus`, `weight_initial`/`weight_final` → `decimal:3`, `unit_cost`/`subtotal` → `decimal:2`.

- [ ] **Step 1: Tuliskan acceptance check**

| # | Skenario | Harap |
|---|---|---|
| A | Migration dijalankan pada `cinnamon_v2` | 2 tabel baru dibuat, rollback mengembalikan ke 10 tabel |
| B | `IncomingReceipt` dibuat | `id` berupa UUID v7, `status` default `draft` |
| C | `status` diisi nilai di luar enum | error saat simpan, bukan diam-diam diterima |
| D | `decimal` cast pada `total_weight` | mengembalikan string, bukan float |
| E | `items()` milik `IncomingReceipt` | mengembalikan `HasMany` ke `IncomingReceiptItem` |
| F | `incomingReceiptItems()` milik `Product` | mengembalikan `HasMany` ke `IncomingReceiptItem` |

**Why:** tidak ada file test baru (Global Constraints), jadi daftar inilah acuannya. Diverifikasi live di Task 13.

- [ ] **Step 2: Buat enum `ReceiptStatus` dan `ReceiptItemStatus`**

Buat `backend/app/Enums/ReceiptStatus.php`:

```php
<?php

namespace App\Enums;

enum ReceiptStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
}
```

Buat `backend/app/Enums/ReceiptItemStatus.php`:

```php
<?php

namespace App\Enums;

enum ReceiptItemStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
```

- [ ] **Step 3: Buat migration `incoming_receipts`**

Buat `backend/database/migrations/2026_10_22_000005_create_incoming_receipts_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incoming_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('receipt_number', 40)->unique();
            $table->foreignUuid('supplier_id')->constrained('suppliers')->cascadeOnUpdate();
            $table->date('receipt_date');
            $table->enum('status', ['draft', 'pending', 'approved', 'rejected', 'cancelled'])
                ->default('draft');
            $table->decimal('total_weight', 15, 3)->default(0);
            $table->decimal('total_cost', 15, 2)->default(0);
            $table->text('notes')->nullable();
            // Holds the note given at verification time. On a rejected receipt
            // it is the mandatory reason.
            $table->text('verification_note')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'receipt_date']);
            $table->index(['supplier_id', 'receipt_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incoming_receipts');
    }
};
```

- [ ] **Step 4: Buat migration `incoming_receipt_items`**

Buat `backend/database/migrations/2026_10_22_000006_create_incoming_receipt_items_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incoming_receipt_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('incoming_receipt_id')
                ->constrained('incoming_receipts')
                ->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('weight_initial', 15, 3);
            $table->decimal('weight_final', 15, 3)->nullable();
            $table->decimal('unit_cost', 15, 2);
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('verification_note')->nullable();
            $table->timestamps();

            $table->index('incoming_receipt_id');
            $table->index(['product_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incoming_receipt_items');
    }
};
```

- [ ] **Step 5: Buat model `IncomingReceipt`**

Buat `backend/app/Models/IncomingReceipt.php`:

```php
<?php

namespace App\Models;

use App\Enums\ReceiptStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'receipt_number',
    'supplier_id',
    'receipt_date',
    'status',
    'total_weight',
    'total_cost',
    'notes',
    'verification_note',
    'created_by',
    'approved_by',
    'approved_at',
])]
class IncomingReceipt extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ReceiptStatus::class,
            'receipt_date' => 'date',
            'total_weight' => 'decimal:3',
            'total_cost' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(IncomingReceiptItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
```

- [ ] **Step 6: Buat model `IncomingReceiptItem`**

Buat `backend/app/Models/IncomingReceiptItem.php`:

```php
<?php

namespace App\Models;

use App\Enums\ReceiptItemStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'incoming_receipt_id',
    'product_id',
    'weight_initial',
    'weight_final',
    'unit_cost',
    'subtotal',
    'status',
    'verification_note',
])]
class IncomingReceiptItem extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ReceiptItemStatus::class,
            'weight_initial' => 'decimal:3',
            'weight_final' => 'decimal:3',
            'unit_cost' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(IncomingReceipt::class, 'incoming_receipt_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
```

- [ ] **Step 7: Tambahkan relasi di `Product` dan `Supplier`**

Di `backend/app/Models/Product.php`, tambahkan import `BelongsTo`/`HasMany` bila belum ada, lalu method:

```php
    public function incomingReceiptItems(): HasMany
    {
        return $this->hasMany(IncomingReceiptItem::class);
    }
```

Di `backend/app/Models/Supplier.php`, tambahkan:

```php
    public function incomingReceipts(): HasMany
    {
        return $this->hasMany(IncomingReceipt::class);
    }
```

- [ ] **Step 8: Jalankan migration**

```bash
cd /d/DEV/0.AI/Cinnamon2/backend && php artisan migrate
```

Expected: `incoming_receipts` dan `incoming_receipt_items` dibuat.

- [ ] **Step 9: Verifikasi rollback pada database pengujian terpisah**

```bash
cd /d/DEV/0.AI/Cinnamon2/backend && php artisan migrate:rollback --step=2
```

Expected: kedua tabel hilang, tabel lain utuh. Lalu `php artisan migrate` untuk mengembalikan.

- [ ] **Step 10: Jalankan pemeriksaan statis**

```bash
cd /d/DEV/0.AI/Cinnamon2/backend && ./vendor/bin/pint --test
```

Expected: exit 0 tanpa offense.

- [ ] **Step 11: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add backend/database backend/app && git commit -m "backend: add incoming receipts and receipt items"
```

---

## Task 2: Migration dan Model untuk Batch, Movement, Ledger, dan Audit

**Files:**
- Create: `backend/database/migrations/2026_10_22_000007_create_stock_batches_table.php`
- Create: `backend/database/migrations/2026_10_22_000008_create_stock_movements_table.php`
- Create: `backend/database/migrations/2026_10_22_000009_create_ledger_entries_table.php`
- Create: `backend/database/migrations/2026_10_22_000010_create_audit_logs_table.php`
- Create: `backend/app/Enums/MovementType.php`
- Create: `backend/app/Models/StockBatch.php`
- Create: `backend/app/Models/StockMovement.php`
- Create: `backend/app/Models/LedgerEntry.php`
- Create: `backend/app/Models/AuditLog.php`

**Interfaces:**
- Consumes: `App\Models\Product`, `App\Models\User`, `App\Models\IncomingReceiptItem` (Task 1).
- Produces:
  - Tabel `stock_batches`: `id` (uuid PK), `product_id` (uuid FK), `incoming_receipt_item_id` (uuid FK → `incoming_receipt_items.id`, **unique**, nullable), `batch_code` (varchar 50), `period_start` (date), `period_end` (date), `received_at` (date), `quantity_received` (decimal 15,3), `quantity_remaining` (decimal 15,3), `unit_cost` (decimal 15,2), `status` (enum `open,depleted,cancelled`, default `open`), timestamps. Unique `(product_id, batch_code)`. Index `(product_id, status, period_start, quantity_remaining)`.
  - Tabel `stock_movements`: `id` (uuid PK), `product_id` (uuid FK), `stock_batch_id` (uuid FK nullable), `movement_type` (enum `receipt,sale,adjustment_in,adjustment_out`), `quantity_in` (decimal 15,3 default 0), `quantity_out` (decimal 15,3 default 0), `ready_before` (decimal 15,3), `ready_after` (decimal 15,3), `pending_before` (decimal 15,3), `pending_after` (decimal 15,3), `source_type` (varchar 80 nullable), `source_id` (uuid nullable), `reason` (varchar 255 nullable), `recorded_by` (uuid FK → `users.id`), `occurred_at` (datetime), timestamps. Index `(product_id, occurred_at)`, `(movement_type, occurred_at)`, `(source_type, source_id)`.
  - Tabel `ledger_entries`: `id` (uuid PK), `entry_date` (date), `source_type` (varchar 80 nullable), `source_id` (uuid nullable), `description` (varchar 255), `debit` (decimal 15,2 default 0), `credit` (decimal 15,2 default 0), `balance` (decimal 15,2 nullable), `created_by` (uuid FK → `users.id`), timestamps.
  - Tabel `audit_logs`: `id` (uuid PK), `actor_id` (uuid FK → `users.id` nullable), `action` (varchar 80), `resource_type` (varchar 80), `resource_id` (uuid nullable), `before_json` (json nullable), `after_json` (json nullable), `request_id` (varchar 80 nullable), `occurred_at` (datetime), timestamps. Index `(resource_type, resource_id, occurred_at)`, `(actor_id, occurred_at)`.
  - `App\Enums\MovementType`: `Receipt='receipt'`, `Sale='sale'`, `AdjustmentIn='adjustment_in'`, `AdjustmentOut='adjustment_out'`.
  - Model dengan cast decimal/date/datetime/enum dan relasi yang dipakai Phase 2.2: `StockBatch` (→ `product()`, `receiptItem()`), `StockMovement` (→ `product()`, `batch()`, `recorder()`), `LedgerEntry` (→ `creator()`), `AuditLog` (→ `actor()`).

- [ ] **Step 1: Buat enum `MovementType`**

```php
<?php

namespace App\Enums;

enum MovementType: string
{
    case Receipt = 'receipt';
    case Sale = 'sale';
    case AdjustmentIn = 'adjustment_in';
    case AdjustmentOut = 'adjustment_out';
}
```

- [ ] **Step 2: Buat empat migration**

`2026_10_22_000007_create_stock_batches_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained('products')->restrictOnDelete();
            // One batch per receipt item, per Docs/decisions.md §1.
            $table->foreignUuid('incoming_receipt_item_id')
                ->nullable()
                ->unique()
                ->constrained('incoming_receipt_items')
                ->nullOnDelete();
            $table->string('batch_code', 50);
            $table->date('period_start');
            $table->date('period_end');
            $table->date('received_at');
            $table->decimal('quantity_received', 15, 3);
            $table->decimal('quantity_remaining', 15, 3);
            $table->decimal('unit_cost', 15, 2);
            $table->enum('status', ['open', 'depleted', 'cancelled'])->default('open');
            $table->timestamps();

            $table->unique(['product_id', 'batch_code']);
            $table->index(['product_id', 'status', 'period_start', 'quantity_remaining']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_batches');
    }
};
```

`2026_10_22_000008_create_stock_movements_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignUuid('stock_batch_id')->nullable()->constrained('stock_batches')->nullOnDelete();
            $table->enum('movement_type', ['receipt', 'sale', 'adjustment_in', 'adjustment_out']);
            $table->decimal('quantity_in', 15, 3)->default(0);
            $table->decimal('quantity_out', 15, 3)->default(0);
            $table->decimal('ready_before', 15, 3);
            $table->decimal('ready_after', 15, 3);
            $table->decimal('pending_before', 15, 3);
            $table->decimal('pending_after', 15, 3);
            $table->string('source_type', 80)->nullable();
            $table->uuid('source_id')->nullable();
            $table->string('reason', 255)->nullable();
            $table->foreignUuid('recorded_by')->constrained('users');
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['product_id', 'occurred_at']);
            $table->index(['movement_type', 'occurred_at']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
```

`2026_10_22_000009_create_ledger_entries_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->date('entry_date');
            $table->string('source_type', 80)->nullable();
            $table->uuid('source_id')->nullable();
            $table->string('description', 255);
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->decimal('balance', 15, 2)->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
```

`2026_10_22_000010_create_audit_logs_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 80);
            $table->string('resource_type', 80);
            $table->uuid('resource_id')->nullable();
            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            $table->string('request_id', 80)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['resource_type', 'resource_id', 'occurred_at']);
            $table->index(['actor_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
```

- [ ] **Step 3: Buat empat model**

`StockBatch`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'product_id',
    'incoming_receipt_item_id',
    'batch_code',
    'period_start',
    'period_end',
    'received_at',
    'quantity_received',
    'quantity_remaining',
    'unit_cost',
    'status',
])]
class StockBatch extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'received_at' => 'date',
            'quantity_received' => 'decimal:3',
            'quantity_remaining' => 'decimal:3',
            'unit_cost' => 'decimal:2',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function receiptItem(): BelongsTo
    {
        return $this->belongsTo(IncomingReceiptItem::class, 'incoming_receipt_item_id');
    }
}
```

`StockMovement`:

```php
<?php

namespace App\Models;

use App\Enums\MovementType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'product_id',
    'stock_batch_id',
    'movement_type',
    'quantity_in',
    'quantity_out',
    'ready_before',
    'ready_after',
    'pending_before',
    'pending_after',
    'source_type',
    'source_id',
    'reason',
    'recorded_by',
    'occurred_at',
])]
class StockMovement extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'movement_type' => MovementType::class,
            'quantity_in' => 'decimal:3',
            'quantity_out' => 'decimal:3',
            'ready_before' => 'decimal:3',
            'ready_after' => 'decimal:3',
            'pending_before' => 'decimal:3',
            'pending_after' => 'decimal:3',
            'occurred_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'stock_batch_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
```

`LedgerEntry`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'entry_date',
    'source_type',
    'source_id',
    'description',
    'debit',
    'credit',
    'balance',
    'created_by',
])]
class LedgerEntry extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
            'balance' => 'decimal:2',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
```

`AuditLog`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'actor_id',
    'action',
    'resource_type',
    'resource_id',
    'before_json',
    'after_json',
    'request_id',
    'occurred_at',
])]
class AuditLog extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before_json' => 'array',
            'after_json' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
```

- [ ] **Step 4: Jalankan migration dan pemeriksaan statis**

```bash
cd /d/DEV/0.AI/Cinnamon2/backend && php artisan migrate && ./vendor/bin/pint --test
```

Expected: 4 tabel baru dibuat; Pint exit 0.

- [ ] **Step 5: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add backend/database backend/app && git commit -m "backend: add stock batches, movements, ledger entries, and audit logs"
```

---

## Task 3: Tabel dan Support Idempotensi

**Files:**
- Create: `backend/database/migrations/2026_10_22_000011_create_idempotency_keys_table.php`
- Create: `backend/app/Models/IdempotencyKey.php`
- Create: `backend/app/Support/Idempotency.php`

**Interfaces:**
- Consumes: `App\Support\ApiResponse` (error envelope).
- Produces:
  - Tabel `idempotency_keys`: `id` (uuid PK), `key` (varchar 255 **unique**), `scope` (varchar 80), `request_hash` (char 64), `response_status` (smallint unsigned), `response_body` (json), `resource_type` (varchar 80 nullable), `resource_id` (uuid nullable), `expires_at` (datetime), timestamps. Index `expires_at`.
  - `App\Support\Idempotency::run(string $scope, string $fingerprint, Closure $callback, ?string $resourceType = null, ?string $resourceId = null): JsonResponse` — membungkus sebuah operasi stock-changing.
    - Header dibaca dari `Idempotency-Key`.
    - Tanpa header → `ApiResponse::error('BAD_REQUEST', 'Header Idempotency-Key wajib untuk operasi ini.', [], [], 400)`.
    - Key ada dengan hash berbeda → `ApiResponse::error('IDEMPOTENCY_CONFLICT', 'Idempotency-Key ini sudah dipakai untuk payload yang berbeda.', [], [], 409)`.
    - Key ada dengan hash sama dan belum kedaluwarsa → response tersimpan diputar ulang.
    - Key baru → callback dijalankan, response disimpan, dikembalikan.
    - Retensi 24 jam; baris kedaluwarsa dibersihkan lazy saat key baru disimpan.

- [ ] **Step 1: Buat migration `idempotency_keys`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 255)->unique();
            $table->string('scope', 80);
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('response_status');
            $table->json('response_body');
            $table->string('resource_type', 80)->nullable();
            $table->uuid('resource_id')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
```

- [ ] **Step 2: Buat model `IdempotencyKey`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'key',
    'scope',
    'request_hash',
    'response_status',
    'response_body',
    'resource_type',
    'resource_id',
    'expires_at',
])]
class IdempotencyKey extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'response_body' => 'array',
            'expires_at' => 'datetime',
        ];
    }
}
```

- [ ] **Step 3: Buat `App\Support\Idempotency`**

```php
<?php

namespace App\Support;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class Idempotency
{
    /**
     * How long a stored response stays replayable.
     */
    public const RETENTION_HOURS = 24;

    /**
     * Runs a stock-changing operation at most once per idempotency key.
     *
     * The same key with the same fingerprint replays the stored response
     * without touching the database again; the same key with a different
     * fingerprint is refused, so a retry that changed its payload cannot
     * silently do something else.
     *
     * Two genuinely concurrent requests with the same key can both reach the
     * callback before either has stored its response. That is not a
     * double-stock bug: the action itself locks the document row and refuses
     * to run twice, so the loser gets the domain's own conflict answer
     * (`RECEIPT_ALREADY_APPROVED`) instead of a silent second approval.
     */
    public static function run(
        string $scope,
        string $fingerprint,
        Closure $callback,
        ?string $resourceType = null,
        ?string $resourceId = null,
    ): JsonResponse {
        $request = request();
        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || $key === '') {
            return ApiResponse::error(
                'BAD_REQUEST',
                'Header Idempotency-Key wajib untuk operasi ini.',
                [],
                [],
                400,
            );
        }

        $hash = hash('sha256', $scope.'|'.$fingerprint);

        $existing = IdempotencyKey::query()->where('key', $key)->first();

        if ($existing && $existing->expires_at->isFuture()) {
            if (! hash_equals($existing->request_hash, $hash)) {
                return ApiResponse::error(
                    'IDEMPOTENCY_CONFLICT',
                    'Idempotency-Key ini sudah dipakai untuk payload yang berbeda.',
                    [],
                    [],
                    409,
                );
            }

            return self::replay($existing);
        }

        $response = $callback();

        return self::store($key, $scope, $hash, $response, $resourceType, $resourceId);
    }

    /**
     * Persists the response so a retry can be answered without repeating the
     * side effects.
     */
    private static function store(
        string $key,
        string $scope,
        string $hash,
        JsonResponse $response,
        ?string $resourceType,
        ?string $resourceId,
    ): JsonResponse {
        // Expired rows are only useful as history; clearing them lazily keeps
        // the table small without needing a scheduled job.
        IdempotencyKey::query()->where('expires_at', '<', now())->delete();

        try {
            IdempotencyKey::query()->create([
                'key' => $key,
                'scope' => $scope,
                'request_hash' => $hash,
                'response_status' => $response->getStatusCode(),
                'response_body' => $response->getData(true),
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'expires_at' => now()->addHours(self::RETENTION_HOURS),
            ]);
        } catch (QueryException $exception) {
            // Two concurrent requests can both miss the lookup above and both
            // try to insert. The unique index on `key` settles it: the loser
            // replays the winner's stored response instead of running the
            // operation a second time.
            Log::warning('Idempotency race on key', [
                'key' => $key,
                'scope' => $scope,
                'message' => $exception->getMessage(),
            ]);

            $stored = IdempotencyKey::query()->where('key', $key)->first();

            if ($stored) {
                return self::replay($stored);
            }

            throw $exception;
        }

        return $response;
    }

    /**
     * Rebuilds the stored response.
     */
    private static function replay(IdempotencyKey $stored): JsonResponse
    {
        return response()->json($stored->response_body, $stored->response_status);
    }
}
```

- [ ] **Step 4: Jalankan migration dan pemeriksaan statis**

```bash
cd /d/DEV/0.AI/Cinnamon2/backend && php artisan migrate && ./vendor/bin/pint --test
```

Expected: tabel `idempotency_keys` dibuat; Pint exit 0.

- [ ] **Step 5: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add backend/database backend/app && git commit -m "backend: add idempotency key store for stock-changing commands"
```

---

## Task 4: Domain — Perhitungan dan Penomoran Dokumen

**Files:**
- Create: `backend/app/Domain/Receiving/ReceiptCalculator.php`
- Create: `backend/app/Domain/Receiving/DocumentNumbers.php`

**Interfaces:**
- Consumes: `App\Models\IncomingReceiptItem` (Task 1).
- Produces:
  - `App\Domain\Receiving\ReceiptCalculator`:
    - `public static function effectiveWeight(IncomingReceiptItem $item): string` — `weight_final` bila tidak null, jika tidak `weight_initial`. Dikembalikan sebagai string desimal 3.
    - `public static function subtotal(IncomingReceiptItem $item): string` — `effectiveWeight × unit_cost`, 2 desimal.
    - `public static function totals(iterable $items): array{total_weight: string, total_cost: string}` — jumlah seluruh item.
  - `App\Domain\Receiving\DocumentNumbers`:
    - `public static function nextReceiptNumber(\DateTimeInterface $date): string` — `RC-YYYY-MM-NNN`, `NNN` mulai dari `001` pada bulan berjalan, nol-pad ke 3 digit.
    - `public static function nextBatchCode(\DateTimeInterface $date): string` — `BATCH-YYYYMM-NNN`, `NNN` mulai dari `001` pada bulan berjalan.
    - Keduanya Produced dengan mencari nomor terbesar yang sudah dipakai pada periode yang sama, lalu menambahkan satu. Nomor harus unik (ada unique index), sehingga pemanggil harus berada dalam transaksi atau menangani retry.

- [ ] **Step 1: Buat `ReceiptCalculator`**

Buat `backend/app/Domain/Receiving/ReceiptCalculator.php`:

```php
<?php

namespace App\Domain\Receiving;

use App\Models\IncomingReceiptItem;

/**
 * Weight and money calculations for a receipt.
 *
 * The server is the authority for these numbers: the browser never sends a
 * subtotal or a total, so nothing here trusts client input.
 */
class ReceiptCalculator
{
    /**
     * The weight that actually counts for stock.
     *
     * A verified weight replaces the initial one; until the goods are
     * weighed again the initial weight is what was recorded at the door.
     */
    public static function effectiveWeight(IncomingReceiptItem $item): string
    {
        $weight = $item->weight_final ?? $item->weight_initial;

        return bcadd((string) $weight, '0', 3);
    }

    /**
     * The cost of one receipt line.
     */
    public static function subtotal(IncomingReceiptItem $item): string
    {
        return bcmul(self::effectiveWeight($item), (string) $item->unit_cost, 2);
    }

    /**
     * Totals for a set of receipt lines.
     *
     * @param  iterable<IncomingReceiptItem>  $items
     * @return array{total_weight: string, total_cost: string}
     */
    public static function totals(iterable $items): array
    {
        $totalWeight = '0';
        $totalCost = '0';

        foreach ($items as $item) {
            $totalWeight = bcadd($totalWeight, self::effectiveWeight($item), 3);
            $totalCost = bcadd($totalCost, self::subtotal($item), 2);
        }

        return [
            'total_weight' => bcadd($totalWeight, '0', 3),
            'total_cost' => bcadd($totalCost, '0', 2),
        ];
    }
}
```

- [ ] **Step 2: Buat `DocumentNumbers`**

Buat `backend/app/Domain/Receiving/DocumentNumbers.php`:

```php
<?php

namespace App\Domain\Receiving;

use App\Models\IncomingReceipt;
use App\Models\StockBatch;
use DateTimeInterface;

/**
 * Human-readable document numbers.
 *
 * The number is assigned when the document is created so staff can quote it
 * while the receipt is still a draft. The number carries the period, which
 * keeps the numbering readable in a single store without needing a separate
 * series table.
 */
class DocumentNumbers
{
    /**
     * `RC-2026-10-001` — receipt number for the given month.
     */
    public static function nextReceiptNumber(DateTimeInterface $date): string
    {
        $year = $date->format('Y');
        $month = $date->format('m');
        $prefix = "RC-{$year}-{$month}-";

        $last = IncomingReceipt::query()
            ->where('receipt_number', 'like', $prefix.'%')
            ->orderByDesc('receipt_number')
            ->value('receipt_number');

        $sequence = $last ? ((int) substr((string) $last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);
    }

    /**
     * `BATCH-202610-001` — batch code for the given month.
     */
    public static function nextBatchCode(DateTimeInterface $date): string
    {
        $period = $date->format('Ym');
        $prefix = "BATCH-{$period}-";

        $last = StockBatch::query()
            ->where('batch_code', 'like', $prefix.'%')
            ->orderByDesc('batch_code')
            ->value('batch_code');

        $sequence = $last ? ((int) substr((string) $last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);
    }
}
```

- [ ] **Step 3: Jalankan pemeriksaan statis**

```bash
cd /d/DEV/0.AI/Cinnamon2/backend && ./vendor/bin/pint --test
```

Expected: exit 0 tanpa offense.

- [ ] **Step 4: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add backend/app && git commit -m "backend: add receipt weight calculator and document numbering"
```

---

## Task 5: Policy, Form Request, dan Resource Penerimaan

**Files:**
- Create: `backend/app/Policies/IncomingReceiptPolicy.php`
- Create: `backend/app/Http/Requests/Receiving/StoreIncomingReceiptRequest.php`
- Create: `backend/app/Http/Requests/Receiving/UpdateIncomingReceiptRequest.php`
- Create: `backend/app/Http/Requests/Receiving/VerifyReceiptRequest.php`
- Create: `backend/app/Http/Requests/Receiving/UpdatePendingItemRequest.php`
- Create: `backend/app/Http/Resources/IncomingReceiptResource.php`
- Create: `backend/app/Http/Resources/IncomingReceiptItemResource.php`

**Interfaces:**
- Consumes: `App\Models\IncomingReceipt`, `App\Models\IncomingReceiptItem`, `App\Models\User`.
- Produces:
  - `App\Policies\IncomingReceiptPolicy`:
    - `viewAny(User $actor): bool` → true (semua authenticated user).
    - `view(User $actor, IncomingReceipt $receipt): bool` → true.
    - `create(User $actor): bool` → true (Karyawan dan Admin boleh mencatat penerimaan).
    - `update(User $actor, IncomingReceipt $receipt): bool` → Admin **atau** Receipt belum `pending`/`approved` dan dibuat oleh actor. Ringkasnya: Admin bebas; Karyawan hanya boleh mengubah dokumen miliknya yang masih `draft`.
    - `submit(User $actor, IncomingReceipt $receipt): bool` → Admin atau pemilik yang statusnya `draft`.
    - `verify(User $actor, IncomingReceipt $receipt): bool` → `$actor->hasRole('admin')` saja.
    - `cancel(User $actor, IncomingReceipt $receipt): bool` → Admin, atau pemilik saat status `draft`.
    - `updatePendingItem(User $actor, IncomingReceiptItem $item): bool` → Admin atau Karyawan (sesuai `Docs/API.md` §4.2, keduanya boleh mengubah berat/status pending sebelum approval).
  - `StoreIncomingReceiptRequest` rules:
    - `supplier_id` → `required`, `uuid`, `exists:suppliers,id`, dan supplier harus `is_active` (dicek lewat `Rule::exists` + pemeriksaan di `withValidator`).
    - `receipt_date` → `required`, `date_format:Y-m-d`, tidak boleh lebih dari 1 hari ke depan.
    - `notes` → `nullable`, `string`, `max:2000`.
    - `items` → `required`, `array`, `min:1`.
    - `items.*.product_id` → `required`, `uuid`, `exists:products,id`, produk harus `is_active`.
    - `items.*.weight_initial` → `required`, `numeric`, `min:0`, `max:999999999`.
    - `items.*.weight_final` → `nullable`, `numeric`, `min:0`, `max:999999999`.
    - `items.*.unit_cost` → `required`, `numeric`, `min:0`, `max:9999999999`.
    - `authorize()` → true (policy dicek di controller/action).
  - `UpdateIncomingReceiptRequest` rules: sama seperti store, tapi semua field opsional (`sometimes`), `items` opsional. `status` **tidak** diterima di sini — transisi status lewat endpoint khusus.
  - `VerifyReceiptRequest` rules: `decision` → `required`, `Rule::in(['approved','rejected'])`; `reason` → `required_if:decision,rejected`, `nullable`, `string`, `max:1000`.
  - `UpdatePendingItemRequest` rules: `weight_final` → `required`, `numeric`, `min:0`, `max:999999999`; `note` → `nullable`, `string`, `max:1000`.
  - `IncomingReceiptResource` mengembalikan: `id`, `receipt_number`, `supplier_id`, `supplier_name`, `receipt_date` (`Y-m-d`), `status`, `total_weight`, `total_cost`, `notes`, `verification_note`, `created_by`, `created_by_name`, `approved_by`, `approved_by_name`, `approved_at`, `items` (collection `IncomingReceiptItemResource`), `created_at`, `updated_at`.
  - `IncomingReceiptItemResource` mengembalikan: `id`, `product_id`, `product_name`, `sku`, `unit`, `weight_initial`, `weight_final`, `effective_weight`, `unit_cost`, `subtotal`, `status`, `note`, `verification_note`, `created_at`, `updated_at`.

- [ ] **Step 1: Buat `IncomingReceiptPolicy`**

```php
<?php

namespace App\Policies;

use App\Enums\ReceiptStatus;
use App\Models\IncomingReceipt;
use App\Models\IncomingReceiptItem;
use App\Models\User;

class IncomingReceiptPolicy
{
    public function viewAny(User $actor): bool
    {
        return true;
    }

    public function view(User $actor, IncomingReceipt $receipt): bool
    {
        return true;
    }

    /**
     * Both roles record incoming goods, per Docs/PRD.md §4.
     */
    public function create(User $actor): bool
    {
        return true;
    }

    /**
     * An Admin may edit any receipt. A Karyawan may only edit the draft they
     * own — once a receipt is pending, only an Admin can act on it.
     */
    public function update(User $actor, IncomingReceipt $receipt): bool
    {
        if ($actor->hasRole('admin')) {
            return true;
        }

        return $receipt->status === ReceiptStatus::Draft
            && $receipt->created_by === $actor->id;
    }

    public function submit(User $actor, IncomingReceipt $receipt): bool
    {
        if ($actor->hasRole('admin')) {
            return true;
        }

        return $receipt->status === ReceiptStatus::Draft
            && $receipt->created_by === $actor->id;
    }

    /**
     * Only an Admin verifies a receipt, per Docs/PRD.md §4.
     */
    public function verify(User $actor, IncomingReceipt $receipt): bool
    {
        return $actor->hasRole('admin');
    }

    public function cancel(User $actor, IncomingReceipt $receipt): bool
    {
        if ($actor->hasRole('admin')) {
            return true;
        }

        return $receipt->status === ReceiptStatus::Draft
            && $receipt->created_by === $actor->id;
    }

    /**
     * Karyawan may adjust pending goods before approval, per Docs/API.md §4.2.
     */
    public function updatePendingItem(User $actor, IncomingReceiptItem $item): bool
    {
        return true;
    }
}
```

- [ ] **Step 2: Buat Form Request `StoreIncomingReceiptRequest`**

```php
<?php

namespace App\Http\Requests\Receiving;

use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreIncomingReceiptRequest extends FormRequest
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
            'supplier_id' => ['required', 'uuid', 'exists:suppliers,id'],
            'receipt_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'uuid', 'exists:products,id'],
            'items.*.weight_initial' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'items.*.weight_final' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:9999999999'],
        ];
    }

    /**
     * A receipt may only name a supplier and products the store still uses.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $supplier = Supplier::query()
                ->find($this->input('supplier_id'));

            if ($supplier && ! $supplier->is_active) {
                $validator->errors()->add(
                    'supplier_id',
                    'Supplier ini sudah dinonaktifkan dan tidak dapat dipilih.',
                );
            }

            foreach ((array) $this->input('items', []) as $index => $item) {
                $product = Product::query()->find($item['product_id'] ?? null);

                if ($product && ! $product->is_active) {
                    $validator->errors()->add(
                        "items.{$index}.product_id",
                        'Produk ini sudah dinonaktifkan dan tidak dapat dipilih.',
                    );
                }
            }
        });
    }
}
```

Rule `before_or_equal:today` memakai tanggal pada zona waktu aplikasi (Asia/Jakarta), jadi `receipt_date` boleh sama dengan hari ini dan tidak boleh di masa depan.

- [ ] **Step 3: Buat Form Request `UpdateIncomingReceiptRequest`**

```php
<?php

namespace App\Http\Requests\Receiving;

use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateIncomingReceiptRequest extends FormRequest
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
            'supplier_id' => ['sometimes', 'uuid', 'exists:suppliers,id'],
            'receipt_date' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'uuid', 'exists:products,id'],
            'items.*.weight_initial' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'items.*.weight_final' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:9999999999'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filled('supplier_id')) {
                $supplier = Supplier::query()->find($this->input('supplier_id'));

                if ($supplier && ! $supplier->is_active) {
                    $validator->errors()->add(
                        'supplier_id',
                        'Supplier ini sudah dinonaktifkan dan tidak dapat dipilih.',
                    );
                }
            }

            foreach ((array) $this->input('items', []) as $index => $item) {
                $product = Product::query()->find($item['product_id'] ?? null);

                if ($product && ! $product->is_active) {
                    $validator->errors()->add(
                        "items.{$index}.product_id",
                        'Produk ini sudah dinonaktifkan dan tidak dapat dipilih.',
                    );
                }
            }
        });
    }
}
```

- [ ] **Step 4: Buat Form Request `VerifyReceiptRequest` dan `UpdatePendingItemRequest`**

`VerifyReceiptRequest`:

```php
<?php

namespace App\Http\Requests\Receiving;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VerifyReceiptRequest extends FormRequest
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
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            // A rejection without a reason leaves the store unable to explain
            // why the goods went back.
            'reason' => ['required_if:decision,rejected', 'nullable', 'string', 'max:1000'],
        ];
    }
}
```

`UpdatePendingItemRequest`:

```php
<?php

namespace App\Http\Requests\Receiving;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePendingItemRequest extends FormRequest
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
            'weight_final' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
```

- [ ] **Step 5: Buat Resource `IncomingReceiptItemResource`**

```php
<?php

namespace App\Http\Resources;

use App\Domain\Receiving\ReceiptCalculator;
use App\Support\Timestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IncomingReceiptItemResource extends JsonResource
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
            'weight_initial' => $this->weight_initial,
            'weight_final' => $this->weight_final,
            'effective_weight' => ReceiptCalculator::effectiveWeight($this->resource),
            'unit_cost' => $this->unit_cost,
            'subtotal' => $this->subtotal,
            'status' => $this->status?->value,
            'verification_note' => $this->verification_note,
            'created_at' => Timestamp::iso8601($this->created_at),
            'updated_at' => Timestamp::iso8601($this->updated_at),
        ];
    }
}
```

Item tidak punya kolom `note` — tabel `incoming_receipt_items` hanya menyimpan `verification_note` (hasil verifikasi) dan `notes` sudah ada di level dokumen. Karena itu rule `'items.*.note'` tidak ada di kedua form request dan key `note` tidak ada di resource ini.

- [ ] **Step 6: Buat Resource `IncomingReceiptResource`**

```php
<?php

namespace App\Http\Resources;

use App\Support\Timestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IncomingReceiptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'receipt_number' => $this->receipt_number,
            'supplier_id' => $this->supplier_id,
            'supplier_name' => $this->supplier?->name,
            'receipt_date' => $this->receipt_date?->format('Y-m-d'),
            'status' => $this->status?->value,
            'total_weight' => $this->total_weight,
            'total_cost' => $this->total_cost,
            'notes' => $this->notes,
            'verification_note' => $this->verification_note,
            'created_by' => $this->created_by,
            'created_by_name' => $this->creator?->name,
            'approved_by' => $this->approved_by,
            'approved_by_name' => $this->approver?->name,
            'approved_at' => Timestamp::iso8601($this->approved_at),
            'items' => IncomingReceiptItemResource::collection($this->whenLoaded('items')),
            'created_at' => Timestamp::iso8601($this->created_at),
            'updated_at' => Timestamp::iso8601($this->updated_at),
        ];
    }
}
```

- [ ] **Step 7: Jalankan pemeriksaan statis**

```bash
cd /d/DEV/0.AI/Cinnamon2/backend && ./vendor/bin/pint --test
```

Expected: exit 0.

- [ ] **Step 8: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add backend/app && git commit -m "backend: add receipt policy, requests, and resources"
```

---

## Task 6: Action `ApproveReceipt` (membuat batch, movement, ledger, audit)

**Files:**
- Create: `backend/app/Actions/Receiving/ApproveReceipt.php`
- Create: `backend/app/Actions/Receiving/RejectReceipt.php`

**Interfaces:**
- Consumes: `ReceiptCalculator`, `DocumentNumbers`, `ReceiptStatus`, `ReceiptItemStatus`, `MovementType`, model `IncomingReceipt`, `IncomingReceiptItem`, `Product`, `StockBatch`, `StockMovement`, `LedgerEntry`, `AuditLog`.
- Produces:
  - `ApproveReceipt::handle(IncomingReceipt $receipt, User $actor, ?string $reason = null): IncomingReceipt`
    - Menjalankan seluruh berikut **dalam satu `DB::transaction`**:
      1. Kunci baris receipt: `IncomingReceipt::query()->lockForUpdate()->findOrFail($receipt->id)`.
      2. Bila status sudah bukan `pending` → lempar `BusinessRuleException` dengan kode `RECEIPT_ALREADY_APPROVED` (409).
      3. Kunci tiap produk yang disebut item (`Product::query()->lockForUpdate()->find($item->product_id)`).
      4. Untuk tiap item: `weight_final` wajib ada. Bila null → `BusinessRuleException` kode `VALIDATION_ERROR` dengan `fields.weight_final` = "Berat final belum diisi. Isi berat final item sebelum menyetujui penerimaan."
      5. Hitung `effectiveWeight` dan `subtotal` dengan `ReceiptCalculator`.
      6. Kurangi `products.pending_stock` sebesar `effectiveWeight`; tambah `products.ready_stock` sebesar `weight_final`. Bila `pending_stock` kurang → `BusinessRuleException` kode `VALIDATION_ERROR` dengan pesan "Stok pending produk tidak mencukupi. Periksa data penerimaan." (pola `BCMath`).
      7. Buat `stock_batches` satu per item: `batch_code` dari `DocumentNumbers::nextBatchCode($receipt->receipt_date)`, `period_start` = tanggal 1 bulan dokumen, `period_end` = tanggal akhir bulan dokumen, `received_at` = `receipt_date`, `quantity_received` = `quantity_remaining` = `weight_final`, `unit_cost` = `unit_cost` item, `status` = `open`.
      8. Buat `stock_movements` satu per item: `movement_type` = `receipt`, `quantity_in` = `weight_final`, `quantity_out` = 0, `ready_before`/`ready_after` dari produk **setelah** update, `pending_before`/`pending_after` sama, `source_type` = `incoming_receipt`, `source_id` = `$receipt->id`, `reason` = `$reason`, `recorded_by` = `$actor->id`, `occurred_at` = `now()`.
      9. Set status item `approved`.
      10. Set receipt `status` = `approved`, `approved_by` = `$actor->id`, `approved_at` = `now()`, `verification_note` = `$reason`.
      11. Hitung ulang `total_weight` dan `total_cost` receipt dari item yang di-approve dan simpan.
      12. Buat `ledger_entries` satu baris: `entry_date` = `receipt_date`, `source_type` = `incoming_receipt`, `source_id` = `$receipt->id`, `description` = "Penerimaan {$receipt->receipt_number}", `debit` = 0, `credit` = total cost yang di-approve, `balance` = running balance (lihat Task 6 catatan di bawah), `created_by` = `$actor->id`.
      13. Buat `audit_logs`: `actor_id` = `$actor->id`, `action` = `receipt.approved`, `resource_type` = `incoming_receipt`, `resource_id` = `$receipt->id`, `before_json` = status lama + stok lama per produk, `after_json` = status baru + stok baru per produk, `request_id` = `RequestId::current()`, `occurred_at` = `now()`.
    - Mengembalikan receipt yang sudah di-refresh dengan `items` dan `supplier` dimuat.
  - `RejectReceipt::handle(IncomingReceipt $receipt, User $actor, string $reason): IncomingReceipt`
    - Satu `DB::transaction`:
      1. Kunci receipt dengan `lockForUpdate`. Bila status bukan `pending` → `BusinessRuleException` `RECEIPT_ALREADY_APPROVED` (409).
      2. Kunci produk tiap item.
      3. Kurangi `products.pending_stock` sebesar `effectiveWeight` item (tidak menambah ready stock).
      4. Set status item `rejected`, `verification_note` = `$reason`.
      5. Set receipt `status` = `rejected`, `verification_note` = `$reason`.
      6. `audit_logs` dengan `action` = `receipt.rejected`, `before_json`/`after_json` berisi status dan stok.
    - Tidak membuat batch, movement, maupun ledger entry.
  - `App\Exceptions\BusinessRuleException` — exception dengan properti `errorCode` (string), `status` (int), `fields` (array). Di-render oleh `bootstrap/app.php` menjadi envelope error.

- [ ] **Step 1: Buat `BusinessRuleException` dan render-nya**

Buat `backend/app/Exceptions/BusinessRuleException.php`:

```php
<?php

namespace App\Exceptions;

use Exception;

/**
 * A rule the client cannot satisfy by changing its input shape: a document
 * already verified, a duplicate idempotency key, insufficient stock.
 *
 * These are answered with a stable error code and a status from
 * `Docs/API.md` §7 — never as a generic 500.
 */
class BusinessRuleException extends Exception
{
    /**
     * @param  array<string, list<string>>  $fields
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 409,
        public readonly array $fields = [],
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
```

Di `backend/bootstrap/app.php`, tambahkan cabang baru di blok `$exceptions->render(...)` — sisipkan **sebelum** `$httpStatus !== null && $httpStatus >= 400 && $httpStatus < 500`:

```php
            $business = $e instanceof \App\Exceptions\BusinessRuleException ? $e : null;
```

Lalu ubah blok `$code = match (true)` menjadi:

```php
            $code = match (true) {
                $business !== null => $business->errorCode,
                $e instanceof ValidationException => 'VALIDATION_ERROR',
                $e instanceof AuthenticationException => 'UNAUTHENTICATED',
                $e instanceof AuthorizationException => 'FORBIDDEN',
                $e instanceof AccessDeniedHttpException => 'FORBIDDEN',
                $e instanceof ModelNotFoundException => 'NOT_FOUND',
                $e instanceof NotFoundHttpException => 'NOT_FOUND',
                $e instanceof MethodNotAllowedHttpException => 'BAD_REQUEST',
                $e instanceof ThrottleRequestsException => 'RATE_LIMITED',
                $httpStatus === 419 => 'UNAUTHENTICATED',
                $httpStatus === 403 => 'FORBIDDEN',
                $httpStatus === 404 => 'NOT_FOUND',
                $httpStatus === 405 => 'BAD_REQUEST',
                $httpStatus === 429 => 'RATE_LIMITED',
                $httpStatus !== null && $httpStatus >= 400 && $httpStatus < 500 => 'BAD_REQUEST',
                default => 'SERVER_ERROR',
            };
```

Dan blok `$status = match ($code)` menjadi:

```php
            $status = match ($code) {
                'VALIDATION_ERROR' => 422,
                'UNAUTHENTICATED' => $httpStatus === 419 ? 419 : 401,
                'FORBIDDEN' => 403,
                'NOT_FOUND' => 404,
                'BAD_REQUEST' => 400,
                'RATE_LIMITED' => 429,
                'RECEIPT_ALREADY_APPROVED' => 409,
                'IDEMPOTENCY_CONFLICT' => 409,
                default => $business?->status ?? 500,
            };
```

Dan blok `$message`:

```php
            $message = match (true) {
                $business !== null => $business->getMessage(),
                $code === 'VALIDATION_ERROR' => 'Validasi gagal.',
                $code === 'UNAUTHENTICATED' => $httpStatus === 419
                    ? 'Sesi Anda telah berakhir. Silakan muat ulang halaman.'
                    : 'Sesi tidak valid.',
                $code === 'FORBIDDEN' => 'Akses tidak diizinkan.',
                $code === 'NOT_FOUND' => 'Resource tidak ditemukan.',
                $code === 'BAD_REQUEST' => 'Permintaan tidak dapat diproses.',
                $code === 'RATE_LIMITED' => 'Terlalu banyak permintaan.',
                default => 'Terjadi kesalahan server.',
            };
```

Dan blok `$fields`:

```php
            $fields = match (true) {
                $business !== null => $business->fields,
                $e instanceof ValidationException => $e->errors(),
                default => [],
            };
```

Dan `$details`:

```php
            $details = $business?->details ?? [];
```

- [ ] **Step 2: Buat `ApproveReceipt`**

Buat `backend/app/Actions/Receiving/ApproveReceipt.php`:

```php
<?php

namespace App\Actions\Receiving;

use App\Domain\Receiving\DocumentNumbers;
use App\Domain\Receiving\ReceiptCalculator;
use App\Enums\MovementType;
use App\Enums\ReceiptItemStatus;
use App\Enums\ReceiptStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\IncomingReceipt;
use App\Models\LedgerEntry;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\RequestId;
use Illuminate\Support\Facades\DB;

class ApproveReceipt
{
    /**
     * Turns a pending receipt into ready stock.
     *
     * Everything below happens in one transaction: the stock projections, the
     * batches, the movements, the ledger entry, and the audit trail. A failure
     * anywhere leaves the store exactly as it was.
     */
    public function handle(IncomingReceipt $receipt, User $actor, ?string $reason = null): IncomingReceipt
    {
        return DB::transaction(function () use ($receipt, $actor, $reason): IncomingReceipt {
            $locked = IncomingReceipt::query()
                ->lockForUpdate()
                ->findOrFail($receipt->id);

            if ($locked->status !== ReceiptStatus::Pending) {
                throw new BusinessRuleException(
                    'RECEIPT_ALREADY_APPROVED',
                    'Penerimaan ini sudah diverifikasi dan tidak dapat disetujui lagi.',
                );
            }

            $items = $locked->items()->orderBy('id')->get();

            if ($items->isEmpty()) {
                throw new BusinessRuleException(
                    'VALIDATION_ERROR',
                    'Penerimaan ini tidak memiliki item.',
                    422,
                    ['items' => ['Penerimaan harus memiliki minimal satu item.']],
                );
            }

            // Lock the products before touching their projections so two
            // approvals running at the same time cannot interleave.
            $products = [];

            foreach ($items as $item) {
                $products[$item->product_id] = Product::query()
                    ->lockForUpdate()
                    ->findOrFail($item->product_id);
            }

            $before = [];

            foreach ($products as $product) {
                $before[$product->id] = [
                    'ready_stock' => $product->ready_stock,
                    'pending_stock' => $product->pending_stock,
                ];
            }

            $approvedWeight = '0';
            $approvedCost = '0';
            $now = now();

            foreach ($items as $item) {
                if ($item->weight_final === null) {
                    throw new BusinessRuleException(
                        'VALIDATION_ERROR',
                        'Berat final belum diisi. Isi berat final item sebelum menyetujui penerimaan.',
                        422,
                        ['weight_final' => ['Berat final wajib diisi sebelum penerimaan disetujui.']],
                    );
                }

                $product = $products[$item->product_id];
                $effectiveWeight = ReceiptCalculator::effectiveWeight($item);
                $finalWeight = (string) $item->weight_final;

                if (bccomp((string) $product->pending_stock, $effectiveWeight, 3) < 0) {
                    throw new BusinessRuleException(
                        'VALIDATION_ERROR',
                        'Stok pending produk tidak mencukupi. Periksa data penerimaan.',
                        422,
                        ['items' => ['Stok pending produk tidak mencukupi untuk item ini.']],
                    );
                }

                $product->pending_stock = bcsub((string) $product->pending_stock, $effectiveWeight, 3);
                $product->ready_stock = bcadd((string) $product->ready_stock, $finalWeight, 3);
                $product->save();

                $batch = StockBatch::query()->create([
                    'product_id' => $product->id,
                    'incoming_receipt_item_id' => $item->id,
                    'batch_code' => DocumentNumbers::nextBatchCode($locked->receipt_date),
                    'period_start' => $locked->receipt_date->copy()->startOfMonth()->toDateString(),
                    'period_end' => $locked->receipt_date->copy()->endOfMonth()->toDateString(),
                    'received_at' => $locked->receipt_date->toDateString(),
                    'quantity_received' => $finalWeight,
                    'quantity_remaining' => $finalWeight,
                    'unit_cost' => $item->unit_cost,
                    'status' => 'open',
                ]);

                StockMovement::query()->create([
                    'product_id' => $product->id,
                    'stock_batch_id' => $batch->id,
                    'movement_type' => MovementType::Receipt,
                    'quantity_in' => $finalWeight,
                    'quantity_out' => 0,
                    'ready_before' => $before[$product->id]['ready_stock'],
                    'ready_after' => $product->ready_stock,
                    'pending_before' => $before[$product->id]['pending_stock'],
                    'pending_after' => $product->pending_stock,
                    'source_type' => 'incoming_receipt',
                    'source_id' => $locked->id,
                    'reason' => $reason,
                    'recorded_by' => $actor->id,
                    'occurred_at' => $now,
                ]);

                $item->status = ReceiptItemStatus::Approved;
                $item->subtotal = ReceiptCalculator::subtotal($item);
                $item->verification_note = $reason;
                $item->save();

                $approvedWeight = bcadd($approvedWeight, $finalWeight, 3);
                $approvedCost = bcadd($approvedCost, $item->subtotal, 2);
            }

            $locked->status = ReceiptStatus::Approved;
            $locked->approved_by = $actor->id;
            $locked->approved_at = $now;
            $locked->verification_note = $reason;
            $locked->total_weight = $approvedWeight;
            $locked->total_cost = $approvedCost;
            $locked->save();

            $this->writeLedgerEntry($locked, $approvedCost, $actor);

            $after = [];

            foreach ($products as $product) {
                $after[$product->id] = [
                    'ready_stock' => $product->fresh()->ready_stock,
                    'pending_stock' => $product->fresh()->pending_stock,
                ];
            }

            AuditLog::query()->create([
                'actor_id' => $actor->id,
                'action' => 'receipt.approved',
                'resource_type' => 'incoming_receipt',
                'resource_id' => $locked->id,
                'before_json' => ['status' => 'pending', 'products' => $before],
                'after_json' => ['status' => 'approved', 'products' => $after],
                'request_id' => RequestId::current(),
                'occurred_at' => $now,
            ]);

            return $locked->refresh()->load(['items', 'supplier', 'creator', 'approver']);
        });
    }

    /**
     * Records the purchase as a credit in the operational ledger.
     *
     * The balance is the running sum of debits minus credits over all entries
     * ordered by `id`, per Docs/decisions.md §6.
     */
    private function writeLedgerEntry(IncomingReceipt $receipt, string $credit, User $actor): void
    {
        $running = LedgerEntry::query()
            ->orderByDesc('id')
            ->first()?->balance ?? '0';

        $balance = bcsub((string) $running, $credit, 2);

        LedgerEntry::query()->create([
            'entry_date' => $receipt->receipt_date->toDateString(),
            'source_type' => 'incoming_receipt',
            'source_id' => $receipt->id,
            'description' => "Penerimaan {$receipt->receipt_number}",
            'debit' => 0,
            'credit' => $credit,
            'balance' => $balance,
            'created_by' => $actor->id,
        ]);
    }
}
```

**Catatan running balance.** `Docs/decisions.md` §6 menetapkan `balance` = running cumulative `SUM(debit) - SUM(credit)` diurutkan `id`. Kode di atas mengambil `balance` baris terakhir lalu menguranginya. Karena `id` UUID v7 bersifat time-ordered, `orderByDesc('id')` memang mengambil baris terbaru. Karena ledger bersifat append-only (baris tidak pernah dihapus), cara ini setara dengan `SUM`.

- [ ] **Step 3: Buat `RejectReceipt`**

Buat `backend/app/Actions/Receiving/RejectReceipt.php`:

```php
<?php

namespace App\Actions\Receiving;

use App\Domain\Receiving\ReceiptCalculator;
use App\Enums\ReceiptItemStatus;
use App\Enums\ReceiptStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\IncomingReceipt;
use App\Models\Product;
use App\Models\User;
use App\Support\RequestId;
use Illuminate\Support\Facades\DB;

class RejectReceipt
{
    /**
     * Rejects a pending receipt: the goods go back to the supplier and the
     * pending stock is released. No batch, no movement, no ledger entry.
     */
    public function handle(IncomingReceipt $receipt, User $actor, string $reason): IncomingReceipt
    {
        return DB::transaction(function () use ($receipt, $actor, $reason): IncomingReceipt {
            $locked = IncomingReceipt::query()
                ->lockForUpdate()
                ->findOrFail($receipt->id);

            if ($locked->status !== ReceiptStatus::Pending) {
                throw new BusinessRuleException(
                    'RECEIPT_ALREADY_APPROVED',
                    'Penerimaan ini sudah diverifikasi dan tidak dapat ditolak lagi.',
                );
            }

            $items = $locked->items()->orderBy('id')->get();
            $before = [];
            $products = [];

            foreach ($items as $item) {
                $product = Product::query()->lockForUpdate()->findOrFail($item->product_id);
                $products[$product->id] = $product;

                $before[$product->id] = [
                    'ready_stock' => $product->ready_stock,
                    'pending_stock' => $product->pending_stock,
                ];
            }

            $now = now();

            foreach ($items as $item) {
                $product = $products[$item->product_id];
                $effectiveWeight = ReceiptCalculator::effectiveWeight($item);

                if (bccomp((string) $product->pending_stock, $effectiveWeight, 3) < 0) {
                    throw new BusinessRuleException(
                        'VALIDATION_ERROR',
                        'Stok pending produk tidak mencukupi. Periksa data penerimaan.',
                        422,
                        ['items' => ['Stok pending produk tidak mencukupi untuk item ini.']],
                    );
                }

                $product->pending_stock = bcsub((string) $product->pending_stock, $effectiveWeight, 3);
                $product->save();

                $item->status = ReceiptItemStatus::Rejected;
                $item->verification_note = $reason;
                $item->save();
            }

            $locked->status = ReceiptStatus::Rejected;
            $locked->verification_note = $reason;
            $locked->save();

            $after = [];

            foreach ($products as $product) {
                $after[$product->id] = [
                    'ready_stock' => $product->fresh()->ready_stock,
                    'pending_stock' => $product->fresh()->pending_stock,
                ];
            }

            AuditLog::query()->create([
                'actor_id' => $actor->id,
                'action' => 'receipt.rejected',
                'resource_type' => 'incoming_receipt',
                'resource_id' => $locked->id,
                'before_json' => ['status' => 'pending', 'products' => $before],
                'after_json' => ['status' => 'rejected', 'products' => $after],
                'request_id' => RequestId::current(),
                'occurred_at' => $now,
            ]);

            return $locked->refresh()->load(['items', 'supplier', 'creator', 'approver']);
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
cd /d/DEV/0.AI/Cinnamon2 && git add backend/app backend/bootstrap && git commit -m "backend: approve and reject receipts in one transaction"
```

---

## Task 7: Action `SubmitReceipt`, `CancelReceipt`, dan `UpdatePendingItem`

**Files:**
- Create: `backend/app/Actions/Receiving/SubmitReceipt.php`
- Create: `backend/app/Actions/Receiving/CancelReceipt.php`
- Create: `backend/app/Actions/Receiving/UpdatePendingItem.php`
- Create: `backend/app/Actions/Receiving/UpdateReceipt.php`

**Interfaces:**
- Consumes: `ReceiptCalculator`, `ReceiptStatus`, `ReceiptItemStatus`, model `IncomingReceipt`, `IncomingReceiptItem`, `Product`, `User`, `AuditLog`.
- Produces:
  - `SubmitReceipt::handle(IncomingReceipt $receipt, User $actor): IncomingReceipt`
    - Satu `DB::transaction`:
      1. Kunci receipt. Bila status bukan `draft` → `BusinessRuleException` `BAD_REQUEST` (400) dengan pesan "Hanya draft yang bisa dikirim untuk verifikasi."
      2. Kunci produk tiap item.
      3. Tambah `products.pending_stock` sebesar `effectiveWeight` item (berat final bila ada, jika tidak berat awal).
      4. Set status item `pending`.
      5. Set status receipt `pending`.
      6. Hitung ulang `total_weight` dan `total_cost` dari seluruh item dan simpan.
      7. `audit_logs` dengan `action` = `receipt.submitted`, before/after berisi status dan stok.
    - Tidak membuat batch, movement, maupun ledger entry. Pending stock adalah proyeksi, bukan movement (movement ditulis saat approval, ketika stok benar-benar siap jual).
  - `CancelReceipt::handle(IncomingReceipt $receipt, User $actor, ?string $reason = null): IncomingReceipt`
    - Satu `DB::transaction`:
      1. Kunci receipt. Bila status `approved` → `BusinessRuleException` `BAD_REQUEST` (400) "Penerimaan yang sudah disetujui tidak dapat dibatalkan." Bila status `rejected` atau `cancelled` → `BAD_REQUEST` "Penerimaan ini sudah ditutup."
      2. Bila status `pending`, lepaskan `pending_stock` sebesar `effectiveWeight` tiap item.
      3. Set status `cancelled`, `verification_note` = `$reason`.
      4. `audit_logs` dengan `action` = `receipt.cancelled`.
  - `UpdatePendingItem::handle(IncomingReceiptItem $item, User $actor, string $weightFinal, ?string $note = null): IncomingReceiptItem`
    - Satu `DB::transaction`:
      1. Kunci item dan receipt-nya. Bila receipt bukan `pending` → `BusinessRuleException` `BAD_REQUEST` (400) "Item hanya bisa diubah saat penerimaan masih menunggu verifikasi."
      2. Hitung `selisih` = `weight_final` baru − `effectiveWeight` lama. Bila negatif → kurangi `pending_stock` sebesar besar selisih; bila positif → tambah.
      3. Simpan `weight_final` baru, `subtotal` dihitung ulang, `verification_note` = `$note`.
      4. Hitung ulang `total_weight`/`total_cost` receipt dan simpan.
      5. `audit_logs` dengan `action` = `receipt.item_weight_updated`.
    - Tidak menulis `stock_movements`: pending stock adalah proyeksi, movement ditulis saat approval.
  - `UpdateReceipt::handle(IncomingReceipt $receipt, User $actor, array $payload): IncomingReceipt`
    - Satu `DB::transaction`:
      1. Kunci receipt. Bila status bukan `draft` → `BusinessRuleException` `BAD_REQUEST` (400) "Penerimaan yang sudah dikirim tidak dapat diubah. Minta Admin untuk menolak atau membatalkan dokumen ini."
      2. Update kolom dokumen (`supplier_id`, `receipt_date`, `notes`).
      3. Bila `items` ada: hapus item lama dan buat ulang (foreign key `incoming_receipt_items` cascade delete; batch belum ada karena status masih draft).
      4. Hitung ulang `total_weight`/`total_cost` dan simpan.
      5. `audit_logs` dengan `action` = `receipt.updated`.

- [ ] **Step 1: Buat `SubmitReceipt`**

```php
<?php

namespace App\Actions\Receiving;

use App\Domain\Receiving\ReceiptCalculator;
use App\Enums\ReceiptItemStatus;
use App\Enums\ReceiptStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\IncomingReceipt;
use App\Models\Product;
use App\Models\User;
use App\Support\RequestId;
use Illuminate\Support\Facades\DB;

class SubmitReceipt
{
    /**
     * Moves a draft to pending and reserves the goods as pending stock.
     *
     * Pending stock is a projection, not a movement: a movement row is written
     * only when the goods actually become sellable stock at approval time.
     */
    public function handle(IncomingReceipt $receipt, User $actor): IncomingReceipt
    {
        return DB::transaction(function () use ($receipt, $actor): IncomingReceipt {
            $locked = IncomingReceipt::query()
                ->lockForUpdate()
                ->findOrFail($receipt->id);

            if ($locked->status !== ReceiptStatus::Draft) {
                throw new BusinessRuleException(
                    'BAD_REQUEST',
                    'Hanya draft yang bisa dikirim untuk verifikasi.',
                );
            }

            $items = $locked->items()->orderBy('id')->get();
            $products = [];
            $before = [];

            foreach ($items as $item) {
                $product = Product::query()->lockForUpdate()->findOrFail($item->product_id);
                $products[$product->id] = $product;
                $before[$product->id] = [
                    'ready_stock' => $product->ready_stock,
                    'pending_stock' => $product->pending_stock,
                ];
            }

            $now = now();
            $totalWeight = '0';
            $totalCost = '0';

            foreach ($items as $item) {
                $product = $products[$item->product_id];
                $effectiveWeight = ReceiptCalculator::effectiveWeight($item);

                $product->pending_stock = bcadd((string) $product->pending_stock, $effectiveWeight, 3);
                $product->save();

                $item->status = ReceiptItemStatus::Pending;
                $item->subtotal = ReceiptCalculator::subtotal($item);
                $item->save();

                $totalWeight = bcadd($totalWeight, $effectiveWeight, 3);
                $totalCost = bcadd($totalCost, $item->subtotal, 2);
            }

            $locked->status = ReceiptStatus::Pending;
            $locked->total_weight = $totalWeight;
            $locked->total_cost = $totalCost;
            $locked->save();

            $after = [];

            foreach ($products as $product) {
                $after[$product->id] = [
                    'ready_stock' => $product->fresh()->ready_stock,
                    'pending_stock' => $product->fresh()->pending_stock,
                ];
            }

            AuditLog::query()->create([
                'actor_id' => $actor->id,
                'action' => 'receipt.submitted',
                'resource_type' => 'incoming_receipt',
                'resource_id' => $locked->id,
                'before_json' => ['status' => 'draft', 'products' => $before],
                'after_json' => ['status' => 'pending', 'products' => $after],
                'request_id' => RequestId::current(),
                'occurred_at' => $now,
            ]);

            return $locked->refresh()->load(['items', 'supplier', 'creator', 'approver']);
        });
    }
}
```

- [ ] **Step 2: Buat `CancelReceipt`**

```php
<?php

namespace App\Actions\Receiving;

use App\Domain\Receiving\ReceiptCalculator;
use App\Enums\ReceiptStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\IncomingReceipt;
use App\Models\Product;
use App\Models\User;
use App\Support\RequestId;
use Illuminate\Support\Facades\DB;

class CancelReceipt
{
    /**
     * Cancels a draft or a pending receipt.
     *
     * An approved receipt is never cancelled: its stock is already sellable,
     * and reversing it needs the sale-side reversal rules of Phase 3.1.
     */
    public function handle(IncomingReceipt $receipt, User $actor, ?string $reason = null): IncomingReceipt
    {
        return DB::transaction(function () use ($receipt, $actor, $reason): IncomingReceipt {
            $locked = IncomingReceipt::query()
                ->lockForUpdate()
                ->findOrFail($receipt->id);

            if ($locked->status === ReceiptStatus::Approved) {
                throw new BusinessRuleException(
                    'BAD_REQUEST',
                    'Penerimaan yang sudah disetujui tidak dapat dibatalkan.',
                );
            }

            if (in_array($locked->status, [ReceiptStatus::Rejected, ReceiptStatus::Cancelled], true)) {
                throw new BusinessRuleException(
                    'BAD_REQUEST',
                    'Penerimaan ini sudah ditutup.',
                );
            }

            $items = $locked->items()->orderBy('id')->get();
            $products = [];
            $before = [];

            foreach ($items as $item) {
                $product = Product::query()->lockForUpdate()->findOrFail($item->product_id);
                $products[$product->id] = $product;
                $before[$product->id] = [
                    'ready_stock' => $product->ready_stock,
                    'pending_stock' => $product->pending_stock,
                ];
            }

            $now = now();
            $previousStatus = $locked->status->value;

            if ($locked->status === ReceiptStatus::Pending) {
                foreach ($items as $item) {
                    $product = $products[$item->product_id];
                    $effectiveWeight = ReceiptCalculator::effectiveWeight($item);

                    if (bccomp((string) $product->pending_stock, $effectiveWeight, 3) >= 0) {
                        $product->pending_stock = bcsub(
                            (string) $product->pending_stock,
                            $effectiveWeight,
                            3,
                        );
                        $product->save();
                    }
                }
            }

            $locked->status = ReceiptStatus::Cancelled;
            $locked->verification_note = $reason;
            $locked->save();

            $after = [];

            foreach ($products as $product) {
                $after[$product->id] = [
                    'ready_stock' => $product->fresh()->ready_stock,
                    'pending_stock' => $product->fresh()->pending_stock,
                ];
            }

            AuditLog::query()->create([
                'actor_id' => $actor->id,
                'action' => 'receipt.cancelled',
                'resource_type' => 'incoming_receipt',
                'resource_id' => $locked->id,
                'before_json' => ['status' => $previousStatus, 'products' => $before],
                'after_json' => ['status' => 'cancelled', 'products' => $after],
                'request_id' => RequestId::current(),
                'occurred_at' => $now,
            ]);

            return $locked->refresh()->load(['items', 'supplier', 'creator', 'approver']);
        });
    }
}
```

**Catatan:** `before_json` memakai `$locked->status->value` **setelah** status diubah menjadi `cancelled`, jadi nilainya salah. Perbaiki: simpan `$previousStatus = $locked->status->value;` **sebelum** mengubah status, lalu pakai `$previousStatus` di `before_json`.

- [ ] **Step 3: Buat `UpdatePendingItem`**

```php
<?php

namespace App\Actions\Receiving;

use App\Domain\Receiving\ReceiptCalculator;
use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\IncomingReceipt;
use App\Models\IncomingReceiptItem;
use App\Models\Product;
use App\Models\User;
use App\Support\RequestId;
use Illuminate\Support\Facades\DB;

class UpdatePendingItem
{
    /**
     * Adjusts the verified weight of a pending receipt line.
     *
     * The pending projection moves with the weight so the store never shows
     * more pending goods than it actually holds. No movement row is written:
     * pending stock is not sellable stock, and movements describe movements of
     * sellable stock.
     */
    public function handle(
        IncomingReceiptItem $item,
        User $actor,
        string $weightFinal,
        ?string $note = null,
    ): IncomingReceiptItem {
        return DB::transaction(function () use ($item, $actor, $weightFinal, $note): IncomingReceiptItem {
            $lockedItem = IncomingReceiptItem::query()
                ->lockForUpdate()
                ->findOrFail($item->id);

            $receipt = $lockedItem->receipt()->lockForUpdate()->firstOrFail();

            if ($receipt->status->value !== 'pending') {
                throw new BusinessRuleException(
                    'BAD_REQUEST',
                    'Item hanya bisa diubah saat penerimaan masih menunggu verifikasi.',
                );
            }

            $product = Product::query()->lockForUpdate()->findOrFail($lockedItem->product_id);

            $before = [
                'weight_final' => $lockedItem->weight_final,
                'effective_weight' => ReceiptCalculator::effectiveWeight($lockedItem),
                'pending_stock' => $product->pending_stock,
            ];

            $oldEffective = ReceiptCalculator::effectiveWeight($lockedItem);
            $newEffective = bcadd($weightFinal, '0', 3);
            $difference = bcsub($newEffective, $oldEffective, 3);

            if (bccomp($difference, '0', 3) >= 0) {
                $product->pending_stock = bcadd((string) $product->pending_stock, $difference, 3);
            } else {
                $reduction = bcsub('0', $difference, 3);

                if (bccomp((string) $product->pending_stock, $reduction, 3) < 0) {
                    throw new BusinessRuleException(
                        'VALIDATION_ERROR',
                        'Stok pending produk tidak mencukupi untuk pengurangan ini.',
                        422,
                        ['weight_final' => ['Berat final baru lebih kecil dari stok pending yang tercatat.']],
                    );
                }

                $product->pending_stock = bcsub((string) $product->pending_stock, $reduction, 3);
            }

            $product->save();

            $lockedItem->weight_final = $newEffective;
            $lockedItem->subtotal = ReceiptCalculator::subtotal($lockedItem);
            $lockedItem->verification_note = $note;
            $lockedItem->save();

            $this->refreshTotals($receipt);

            AuditLog::query()->create([
                'actor_id' => $actor->id,
                'action' => 'receipt.item_weight_updated',
                'resource_type' => 'incoming_receipt_item',
                'resource_id' => $lockedItem->id,
                'before_json' => $before,
                'after_json' => [
                    'weight_final' => $lockedItem->weight_final,
                    'effective_weight' => $newEffective,
                    'pending_stock' => $product->pending_stock,
                ],
                'request_id' => RequestId::current(),
                'occurred_at' => now(),
            ]);

            return $lockedItem->refresh();
        });
    }

    /**
     * Keeps the receipt totals in step with its lines.
     */
    private function refreshTotals(IncomingReceipt $receipt): void
    {
        $totals = ReceiptCalculator::totals($receipt->items()->get());

        $receipt->total_weight = $totals['total_weight'];
        $receipt->total_cost = $totals['total_cost'];
        $receipt->save();
    }
}
```

- [ ] **Step 4: Buat `UpdateReceipt`**

```php
<?php

namespace App\Actions\Receiving;

use App\Domain\Receiving\ReceiptCalculator;
use App\Enums\ReceiptItemStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\IncomingReceipt;
use App\Models\IncomingReceiptItem;
use App\Models\User;
use App\Support\RequestId;
use Illuminate\Support\Facades\DB;

class UpdateReceipt
{
    /**
     * Edits a draft receipt.
     *
     * Only a draft may be edited. Once the receipt is pending, the goods are
     * already counted as pending stock, so the change has to go through the
     * verification flow instead.
     */
    public function handle(IncomingReceipt $receipt, User $actor, array $payload): IncomingReceipt
    {
        return DB::transaction(function () use ($receipt, $actor, $payload): IncomingReceipt {
            $locked = IncomingReceipt::query()
                ->lockForUpdate()
                ->findOrFail($receipt->id);

            if ($locked->status->value !== 'draft') {
                throw new BusinessRuleException(
                    'BAD_REQUEST',
                    'Penerimaan yang sudah dikirim tidak dapat diubah. Minta Admin untuk menolak atau membatalkan dokumen ini.',
                );
            }

            $before = [
                'supplier_id' => $locked->supplier_id,
                'receipt_date' => $locked->receipt_date?->toDateString(),
                'notes' => $locked->notes,
                'items' => $locked->items()->get()->map(fn (IncomingReceiptItem $item) => [
                    'product_id' => $item->product_id,
                    'weight_initial' => $item->weight_initial,
                    'weight_final' => $item->weight_final,
                    'unit_cost' => $item->unit_cost,
                ])->all(),
            ];

            foreach (['supplier_id', 'receipt_date', 'notes'] as $field) {
                if (array_key_exists($field, $payload)) {
                    $locked->{$field} = $payload[$field];
                }
            }

            if (array_key_exists('items', $payload)) {
                $locked->items()->delete();

                foreach ($payload['items'] as $item) {
                    IncomingReceiptItem::query()->create([
                        'incoming_receipt_id' => $locked->id,
                        'product_id' => $item['product_id'],
                        'weight_initial' => $item['weight_initial'],
                        'weight_final' => $item['weight_final'] ?? null,
                        'unit_cost' => $item['unit_cost'],
                        'subtotal' => '0',
                        'status' => ReceiptItemStatus::Pending,
                    ]);
                }
            }

            $totals = ReceiptCalculator::totals($locked->items()->get());
            $locked->total_weight = $totals['total_weight'];
            $locked->total_cost = $totals['total_cost'];
            $locked->save();

            AuditLog::query()->create([
                'actor_id' => $actor->id,
                'action' => 'receipt.updated',
                'resource_type' => 'incoming_receipt',
                'resource_id' => $locked->id,
                'before_json' => $before,
                'after_json' => [
                    'supplier_id' => $locked->supplier_id,
                    'receipt_date' => $locked->receipt_date?->toDateString(),
                    'notes' => $locked->notes,
                    'items' => $locked->items()->get()->map(fn (IncomingReceiptItem $item) => [
                        'product_id' => $item->product_id,
                        'weight_initial' => $item->weight_initial,
                        'weight_final' => $item->weight_final,
                        'unit_cost' => $item->unit_cost,
                    ])->all(),
                ],
                'request_id' => RequestId::current(),
                'occurred_at' => now(),
            ]);

            return $locked->refresh()->load(['items', 'supplier', 'creator', 'approver']);
        });
    }
}
```

- [ ] **Step 5: Jalankan pemeriksaan statis**

```bash
cd /d/DEV/0.AI/Cinnamon2/backend && ./vendor/bin/pint --test
```

Expected: exit 0.

- [ ] **Step 6: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add backend/app && git commit -m "backend: add receipt submit, cancel, and pending item actions"
```

---

## Task 8: Controller dan Route Penerimaan

**Files:**
- Create: `backend/app/Http/Controllers/Api/V1/IncomingReceiptController.php`
- Create: `backend/app/Queries/IncomingReceiptQuery.php`
- Create: `backend/app/Http/Resources/PendingProductResource.php`
- Create: `backend/app/Http/Controllers/Api/V1/PendingProductController.php`
- Modify: `backend/routes/api.php`

**Interfaces:**
- Consumes: action dari Task 6–7, request dari Task 5, resource dari Task 5, `App\Support\Idempotency`.
- Produces:
  - `IncomingReceiptController`:
    - `index(Request $request): JsonResponse` — daftar penerimaan. Filter: `status` (enum valid), `supplier_id` (uuid), `search` (receipt_number / notes), `date_from`, `date_to` (`Y-m-d`), `sort` (allowlist: `receipt_date`, `receipt_number`, `created_at`, `status`, dan versi `-`), `page`, `per_page` (maks 100).
    - `store(StoreIncomingReceiptRequest $request): JsonResponse` — buat draft + item, nomor dibuat di sini, 201.
    - `show(IncomingReceipt $receipt): JsonResponse` — detail beserta item, supplier, pembuat, approver.
    - `update(UpdateIncomingReceiptRequest $request, IncomingReceipt $receipt): JsonResponse` — ubah draft.
    - `submit(IncomingReceipt $receipt): JsonResponse` — `POST /incoming-receipts/{receipt}/submit`, jalankan `SubmitReceipt`, 200.
    - `verify(VerifyReceiptRequest $request, IncomingReceipt $receipt): JsonResponse` — `POST /incoming-receipts/{receipt}/verify`, wajib `Idempotency-Key`, jalankan `ApproveReceipt` atau `RejectReceipt`, 200.
    - `cancel(Request $request, IncomingReceipt $receipt): JsonResponse` — `POST /incoming-receipts/{receipt}/cancel`, wajib `Idempotency-Key`, jalankan `CancelReceipt`, 200.
  - `PendingProductController`:
    - `index(Request $request): JsonResponse` — `GET /inventory/pending-products`, daftar item dengan status pending beserta nama produk dan nomor receipt. Filter: `product_id`, `search`, `page`, `per_page`.
    - `update(UpdatePendingItemRequest $request, IncomingReceiptItem $item): JsonResponse` — `PATCH /inventory/pending-products/{item}`, jalankan `UpdatePendingItem`, 200.
  - Route di `backend/routes/api.php`:
    ```php
    Route::get('/incoming-receipts', [IncomingReceiptController::class, 'index'])->middleware('auth:sanctum');
    Route::post('/incoming-receipts', [IncomingReceiptController::class, 'store'])->middleware('auth:sanctum');
    Route::get('/incoming-receipts/{receipt}', [IncomingReceiptController::class, 'show'])->middleware('auth:sanctum');
    Route::patch('/incoming-receipts/{receipt}', [IncomingReceiptController::class, 'update'])->middleware('auth:sanctum');
    Route::post('/incoming-receipts/{receipt}/submit', [IncomingReceiptController::class, 'submit'])->middleware('auth:sanctum');
    Route::post('/incoming-receipts/{receipt}/verify', [IncomingReceiptController::class, 'verify'])->middleware('auth:sanctum');
    Route::post('/incoming-receipts/{receipt}/cancel', [IncomingReceiptController::class, 'cancel'])->middleware('auth:sanctum');
    Route::get('/inventory/pending-products', [PendingProductController::class, 'index'])->middleware('auth:sanctum');
    Route::patch('/inventory/pending-products/{item}', [PendingProductController::class, 'update'])->middleware('auth:sanctum');
    ```
  - `IncomingReceiptQuery` — kelas statis dengan `build()`, `filter(array $params)`, `sort(?string $sort)`, `paginate(int $perPage)`, mengikuti pola `ProductQuery` di Phase 2.1.

- [ ] **Step 1: Buat `IncomingReceiptQuery`**

```php
<?php

namespace App\Queries;

use App\Models\IncomingReceipt;
use Illuminate\Database\Eloquent\Builder;

class IncomingReceiptQuery
{
    /**
     * @param  Builder<IncomingReceipt>  $query
     */
    public function __construct(private Builder $query)
    {
    }

    public static function build(): self
    {
        return new self(IncomingReceipt::query()->with(['supplier', 'creator']));
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function filter(array $params): self
    {
        if (! empty($params['status'])) {
            $this->query->where('status', $params['status']);
        }

        if (! empty($params['supplier_id'])) {
            $this->query->where('supplier_id', $params['supplier_id']);
        }

        if (! empty($params['search'])) {
            $search = $params['search'];

            $this->query->where(function (Builder $q) use ($search) {
                $q->where('receipt_number', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        if (! empty($params['date_from'])) {
            $this->query->whereDate('receipt_date', '>=', $params['date_from']);
        }

        if (! empty($params['date_to'])) {
            $this->query->whereDate('receipt_date', '<=', $params['date_to']);
        }

        return $this;
    }

    public function sort(?string $sort): self
    {
        $allowlist = [
            'receipt_date' => 'receipt_date',
            '-receipt_date' => 'receipt_date:desc',
            'receipt_number' => 'receipt_number',
            '-receipt_number' => 'receipt_number:desc',
            'created_at' => 'created_at',
            '-created_at' => 'created_at:desc',
            'status' => 'status',
            '-status' => 'status:desc',
        ];

        if ($sort && isset($allowlist[$sort])) {
            $parts = explode(':', $allowlist[$sort]);
            $this->query->orderBy($parts[0], $parts[1] ?? 'asc');
        } else {
            $this->query->orderByDesc('receipt_date')->orderByDesc('id');
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

- [ ] **Step 2: Buat `IncomingReceiptController`**

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Receiving\ApproveReceipt;
use App\Actions\Receiving\CancelReceipt;
use App\Actions\Receiving\RejectReceipt;
use App\Actions\Receiving\SubmitReceipt;
use App\Actions\Receiving\UpdateReceipt;
use App\Domain\Receiving\DocumentNumbers;
use App\Domain\Receiving\ReceiptCalculator;
use App\Enums\ReceiptItemStatus;
use App\Enums\ReceiptStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Receiving\StoreIncomingReceiptRequest;
use App\Http\Requests\Receiving\UpdateIncomingReceiptRequest;
use App\Http\Requests\Receiving\VerifyReceiptRequest;
use App\Http\Resources\IncomingReceiptResource;
use App\Models\IncomingReceipt;
use App\Models\IncomingReceiptItem;
use App\Queries\IncomingReceiptQuery;
use App\Support\ApiResponse;
use App\Support\Idempotency;
use App\Support\RequestId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class IncomingReceiptController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', IncomingReceipt::class);

        $perPage = max(1, min(100, (int) $request->integer('per_page', 20)));

        $receipts = IncomingReceiptQuery::build()
            ->filter($request->only([
                'status',
                'supplier_id',
                'search',
                'date_from',
                'date_to',
            ]))
            ->sort($request->input('sort'))
            ->paginate($perPage);

        return response()->json([
            'data' => IncomingReceiptResource::collection($receipts->items()),
            'meta' => [
                'request_id' => RequestId::current(),
                'current_page' => $receipts->currentPage(),
                'per_page' => $receipts->perPage(),
                'last_page' => $receipts->lastPage(),
                'total' => $receipts->total(),
            ],
        ]);
    }

    public function store(StoreIncomingReceiptRequest $request): JsonResponse
    {
        $this->authorize('create', IncomingReceipt::class);

        $receipt = DB::transaction(function () use ($request): IncomingReceipt {
            $validated = $request->validated();
            $receiptDate = Carbon::parse($validated['receipt_date']);

            $receipt = IncomingReceipt::query()->create([
                'receipt_number' => DocumentNumbers::nextReceiptNumber($receiptDate),
                'supplier_id' => $validated['supplier_id'],
                'receipt_date' => $receiptDate->toDateString(),
                'status' => ReceiptStatus::Draft,
                'notes' => $validated['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            foreach ($validated['items'] as $item) {
                $line = IncomingReceiptItem::query()->create([
                    'incoming_receipt_id' => $receipt->id,
                    'product_id' => $item['product_id'],
                    'weight_initial' => $item['weight_initial'],
                    'weight_final' => $item['weight_final'] ?? null,
                    'unit_cost' => $item['unit_cost'],
                    'subtotal' => '0',
                    'status' => ReceiptItemStatus::Pending,
                ]);

                $line->subtotal = ReceiptCalculator::subtotal($line);
                $line->save();
            }

            $totals = ReceiptCalculator::totals($receipt->items()->get());
            $receipt->total_weight = $totals['total_weight'];
            $receipt->total_cost = $totals['total_cost'];
            $receipt->save();

            return $receipt;
        });

        return ApiResponse::success(
            new IncomingReceiptResource($receipt->load(['items.product', 'supplier', 'creator'])),
            201,
        );
    }

    public function show(IncomingReceipt $receipt): JsonResponse
    {
        $this->authorize('view', $receipt);

        return ApiResponse::success(
            new IncomingReceiptResource($receipt->load(['items.product', 'supplier', 'creator', 'approver'])),
        );
    }

    public function update(UpdateIncomingReceiptRequest $request, IncomingReceipt $receipt): JsonResponse
    {
        $this->authorize('update', $receipt);

        $receipt = app(UpdateReceipt::class)->handle($receipt, $request->user(), $request->validated());

        return ApiResponse::success(
            new IncomingReceiptResource($receipt->load(['items.product', 'supplier', 'creator', 'approver'])),
        );
    }

    /**
     * Sends a draft to verification. Pending stock is reserved here; the
     * goods become sellable stock only at approval.
     */
    public function submit(Request $request, IncomingReceipt $receipt): JsonResponse
    {
        $this->authorize('submit', $receipt);

        $result = app(SubmitReceipt::class)->handle($receipt, $request->user());

        return ApiResponse::success(
            new IncomingReceiptResource($result->load(['items.product', 'supplier', 'creator', 'approver'])),
        );
    }

    /**
     * Verifies a pending receipt.
     *
     * The same key with the same payload replays the stored response, so a
     * double click or a retry after a network hiccup cannot approve twice.
     */
    public function verify(VerifyReceiptRequest $request, IncomingReceipt $receipt): JsonResponse
    {
        $this->authorize('verify', $receipt);

        $decision = $request->validated('decision');
        $reason = $request->validated('reason');

        return Idempotency::run(
            'incoming-receipt.verify',
            $receipt->id.'|'.$decision.'|'.($reason ?? ''),
            function () use ($receipt, $decision, $reason, $request): JsonResponse {
                $result = $decision === 'approved'
                    ? app(ApproveReceipt::class)->handle($receipt, $request->user(), $reason)
                    : app(RejectReceipt::class)->handle($receipt, $request->user(), $reason);

                return ApiResponse::success(
                    new IncomingReceiptResource($result->load(['items.product', 'supplier', 'creator', 'approver'])),
                );
            },
            'incoming_receipt',
            $receipt->id,
        );
    }

    /**
     * Cancels a draft or pending receipt.
     */
    public function cancel(Request $request, IncomingReceipt $receipt): JsonResponse
    {
        $this->authorize('cancel', $receipt);

        $reason = $request->input('reason');

        return Idempotency::run(
            'incoming-receipt.cancel',
            $receipt->id.'|'.($reason ?? ''),
            function () use ($receipt, $reason, $request): JsonResponse {
                $result = app(CancelReceipt::class)->handle($receipt, $request->user(), $reason);

                return ApiResponse::success(
                    new IncomingReceiptResource($result->load(['items.product', 'supplier', 'creator', 'approver'])),
                );
            },
            'incoming_receipt',
            $receipt->id,
        );
    }
}
```

- [ ] **Step 3: Buat `PendingProductResource`**

```php
<?php

namespace App\Http\Resources;

use App\Domain\Receiving\ReceiptCalculator;
use App\Support\Timestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PendingProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'incoming_receipt_id' => $this->incoming_receipt_id,
            'receipt_number' => $this->receipt?->receipt_number,
            'product_id' => $this->product_id,
            'product_name' => $this->product?->name,
            'sku' => $this->product?->sku,
            'unit' => $this->product?->unit,
            'weight_initial' => $this->weight_initial,
            'weight_final' => $this->weight_final,
            'effective_weight' => ReceiptCalculator::effectiveWeight($this->resource),
            'unit_cost' => $this->unit_cost,
            'subtotal' => $this->subtotal,
            'status' => $this->status?->value,
            'verification_note' => $this->verification_note,
            'updated_at' => Timestamp::iso8601($this->updated_at),
        ];
    }
}
```

- [ ] **Step 4: Buat `PendingProductController`**

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Receiving\UpdatePendingItem;
use App\Http\Controllers\Controller;
use App\Http\Requests\Receiving\UpdatePendingItemRequest;
use App\Http\Resources\PendingProductResource;
use App\Models\IncomingReceipt;
use App\Models\IncomingReceiptItem;
use App\Support\ApiResponse;
use App\Support\RequestId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PendingProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', IncomingReceipt::class);

        $perPage = max(1, min(100, (int) $request->integer('per_page', 20)));

        $query = IncomingReceiptItem::query()
            ->where('status', 'pending')
            ->with(['product', 'receipt']);

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->input('product_id'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');

            $query->whereHas('product', function (Builder $q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        $items = $query->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'data' => PendingProductResource::collection($items->items()),
            'meta' => [
                'request_id' => RequestId::current(),
                'current_page' => $items->currentPage(),
                'per_page' => $items->perPage(),
                'last_page' => $items->lastPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    public function update(UpdatePendingItemRequest $request, IncomingReceiptItem $item): JsonResponse
    {
        $this->authorize('updatePendingItem', $item);

        $validated = $request->validated();

        $updated = app(UpdatePendingItem::class)->handle(
            $item,
            $request->user(),
            (string) $validated['weight_final'],
            $validated['note'] ?? null,
        );

        return ApiResponse::success(new PendingProductResource($updated->load(['product', 'receipt'])));
    }
}
```

- [ ] **Step 5: Daftarkan route**

Di `backend/routes/api.php`, tambahkan import dan baris route yang tercantum di blok **Interfaces** task ini.

- [ ] **Step 6: Jalankan pemeriksaan statis**

```bash
cd /d/DEV/0.AI/Cinnamon2/backend && ./vendor/bin/pint --test
```

Expected: exit 0.

- [ ] **Step 7: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add backend/app backend/routes && git commit -m "backend: expose incoming receipt and pending product endpoints"
```

---

## Task 9: Verifikasi Live API Penerimaan

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

- [ ] **Step 3: Verifikasi skenario A — Admin membuat draft**

Ambil `product_id` dari `GET /api/v1/products` (butuh Phase 2.1), lalu:

```bash
curl -X POST http://127.0.0.1:8000/api/v1/incoming-receipts \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -b cookies.txt -H "X-XSRF-TOKEN: <TOKEN>" \
  -d '{"supplier_id":"<SUPPLIER_ID>","receipt_date":"2026-10-22","notes":"Penerimaan periode akhir bulan","items":[{"product_id":"<PRODUCT_ID>","weight_initial":"25.000","weight_final":"24.500","unit_cost":"42000.00"}]}'
```

Expected: 201, `data.receipt_number` = `RC-2026-10-001`, `data.status` = `draft`, `data.total_weight` = "24.500", `data.total_cost` = "1029000.00".

**Catatan CSRF:** axios mengirim header `X-XSRF-TOKEN` dari cookie `XSRF-TOKEN`. Dengan curl, ambil nilainya dari cookie jar (`grep XSRF cookies.txt`) dan kirim sebagai header. Jika 419 muncul, ambil ulang cookie CSRF lewat `curl -c cookies.txt http://127.0.0.1:8000/sanctum/csrf-cookie`.

- [ ] **Step 4: Verifikasi skenario B — Draft tidak menambah stok**

```bash
curl -X GET http://127.0.0.1:8000/api/v1/products/<PRODUCT_ID> -H "Accept: application/json" -b cookies.txt
```

Expected: `data.pending_stock` = "0.000", `data.ready_stock` = "0.000".

- [ ] **Step 5: Verifikasi skenario C — Submit menambah pending stock**

```bash
curl -X POST http://127.0.0.1:8000/api/v1/incoming-receipts/<RECEIPT_ID>/submit \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -b cookies.txt -H "X-XSRF-TOKEN: <TOKEN>"
```

Lalu `GET /api/v1/products/<PRODUCT_ID>`.

Expected: `data.pending_stock` = "24.500", `data.ready_stock` = "0.000".

- [ ] **Step 6: Verifikasi skenario D — Karyawan tidak bisa menyetujui**

Login sebagai karyawan, lalu `POST /incoming-receipts/<RECEIPT_ID>/verify` dengan `Idempotency-Key: test-1`.

Expected: 403 `FORBIDDEN`, `meta.request_id` ada, stok tidak berubah.

- [ ] **Step 7: Verifikasi skenario E — Verify tanpa Idempotency-Key ditolak**

```bash
curl -X POST http://127.0.0.1:8000/api/v1/incoming-receipts/<RECEIPT_ID>/verify \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -b cookies.txt -H "X-XSRF-TOKEN: <TOKEN>" \
  -d '{"decision":"approved"}'
```

Expected: 400 `BAD_REQUEST`, pesan "Header Idempotency-Key wajib untuk operasi ini."

- [ ] **Step 8: Verifikasi skenario F — Approval menaikkan ready stock**

```bash
curl -X POST http://127.0.0.1:8000/api/v1/incoming-receipts/<RECEIPT_ID>/verify \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -b cookies.txt -H "X-XSRF-TOKEN: <TOKEN>" -H "Idempotency-Key: verify-001" \
  -d '{"decision":"approved","reason":"Sudah ditimbang dan dicek."}'
```

Lalu `GET /api/v1/products/<PRODUCT_ID>`.

Expected: 200, `data.status` = "approved", `data.pending_stock` = "0.000", `data.ready_stock` = "24.500".

- [ ] **Step 9: Verifikasi skenario G — Verify dengan key yang sama tidak menggandakan**

Ulangi persis perintah di Step 8 dengan `Idempotency-Key: verify-001` yang sama.

Expected: 200 dengan response identik, dan `data.ready_stock` **tetap** "24.500".

- [ ] **Step 10: Verifikasi skenario H — Verify dengan key sama tapi payload berbeda**

Ulangi Step 8 dengan `Idempotency-Key: verify-001` tapi `decision` = "rejected".

Expected: 409 `IDEMPOTENCY_CONFLICT`, pesan "Idempotency-Key ini sudah dipakai untuk payload yang berbeda."

- [ ] **Step 11: Verifikasi skenario I — Approval kedua pada dokumen yang sama**

Buat draft baru, submit, lalu approve dengan key berbeda (`verify-002`).

Expected: 409 `RECEIPT_ALREADY_APPROVED`, pesan "Penerimaan ini sudah diverifikasi dan tidak dapat disetujui lagi."

- [ ] **Step 12: Verifikasi skenario J — Batch, movement, ledger, audit terbentuk**

```bash
cd /d/DEV/0.AI/Cinnamon2/backend && php artisan tinker --execute="
echo 'batches: '.App\Models\StockBatch::count().PHP_EOL;
echo 'movements: '.App\Models\StockMovement::count().PHP_EOL;
echo 'ledger: '.App\Models\LedgerEntry::count().PHP_EOL;
echo 'audits: '.App\Models\AuditLog::count().PHP_EOL;
echo App\Models\StockBatch::first()?->batch_code.PHP_EOL;
echo App\Models\StockMovement::first()?->movement_type->value.PHP_EOL;
"
```

Expected: `batches: 1`, `movements: 1`, `ledger: 1`, `audits: ≥ 3` (submitted, approved, item update bila ada), `batch_code` = `BATCH-202610-001`, `movement_type` = `receipt`.

- [ ] **Step 13: Verifikasi skenario K — Rejection tidak menambah ready stock**

Buat draft baru, submit, approve dengan `decision: "rejected"` dan `reason` wajib.

Expected: 200, `data.status` = "rejected", `pending_stock` kembali "0.000", `ready_stock` = "0.000", jumlah batch tidak bertambah.

- [ ] **Step 14: Verifikasi skenario L — Rejection tanpa alasan ditolak**

```bash
curl -X POST ... -H "Idempotency-Key: verify-003" -d '{"decision":"rejected"}'
```

Expected: 422 `VALIDATION_ERROR`, `fields.reason` ada.

- [ ] **Step 15: Verifikasi skenario M — Ubah berat final saat pending**

Buat draft baru, submit, lalu `PATCH /api/v1/inventory/pending-products/<ITEM_ID>` dengan `weight_final` = "20.000".

Expected: 200, `data.effective_weight` = "20.000", dan `GET /products/<PRODUCT_ID>` menunjukkan `pending_stock` = "20.000" (bukan 24.500).

- [ ] **Step 16: Verifikasi skenario N — Draft tidak bisa diubah setelah submit**

`PATCH /api/v1/incoming-receipts/<RECEIPT_ID>` dengan body apa pun.

Expected: 400 `BAD_REQUEST`, pesan "Penerimaan yang sudah dikirim tidak dapat diubah. Minta Admin untuk menolak atau membatalkan dokumen ini."

- [ ] **Step 17: Verifikasi skenario O — Cancel mengembalikan pending stock**

Buat draft, submit, lalu `POST /incoming-receipts/<RECEIPT_ID>/cancel` dengan `Idempotency-Key: cancel-001`.

Expected: 200, `data.status` = "cancelled", `pending_stock` kembali "0.000".

- [ ] **Step 18: Verifikasi skenario P — Approve tanpa berat final ditolak**

Buat draft dengan `weight_final` null, submit, lalu approve.

Expected: 422 `VALIDATION_ERROR`, `fields.weight_final` ada, pesan "Berat final wajib diisi sebelum penerimaan disetujui."

- [ ] **Step 19: Verifikasi skenario Q — Karyawan membuat draft**

Login karyawan, `POST /incoming-receipts`.

Expected: 201 (Karyawan boleh mencatat penerimaan).

- [ ] **Step 20: Verifikasi skenario R — Supplier nonaktif ditolak**

Set `is_active` false pada supplier, lalu `POST /incoming-receipts` dengan supplier itu.

Expected: 422, `fields.supplier_id` = "Supplier ini sudah dinonaktifkan dan tidak dapat dipilih."

- [ ] **Step 21: Matikan server**

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
- Consumes: kontrak API dari Task 8.
- Produces:
  - Types: `ReceiptStatus` (`'draft' | 'pending' | 'approved' | 'rejected' | 'cancelled'`), `ReceiptItemStatus` (`'pending' | 'approved' | 'rejected'`), `IncomingReceiptItem`, `IncomingReceipt`, `PendingProduct`.
  - Fungsi client:
    - `fetchIncomingReceipts(page, filters?): Promise<PaginatedEnvelope<IncomingReceipt>>`
    - `fetchIncomingReceipt(id): Promise<IncomingReceipt>`
    - `createIncomingReceipt(payload): Promise<IncomingReceipt>`
    - `updateIncomingReceipt(id, payload): Promise<IncomingReceipt>`
    - `submitIncomingReceipt(id): Promise<IncomingReceipt>`
    - `verifyIncomingReceipt(id, payload: { decision: 'approved' | 'rejected'; reason?: string }, idempotencyKey: string): Promise<IncomingReceipt>`
    - `cancelIncomingReceipt(id, reason: string | undefined, idempotencyKey: string): Promise<IncomingReceipt>`
    - `fetchPendingProducts(page, filters?): Promise<PaginatedEnvelope<PendingProduct>>`
    - `updatePendingProduct(id, payload: { weight_final: string; note?: string }): Promise<PendingProduct>`
  - `API_ERROR_CODES` ditambah: `'RECEIPT_ALREADY_APPROVED'`, `'IDEMPOTENCY_CONFLICT'`, `'CONFLICT'`.

- [ ] **Step 1: Tambah types di `types.ts`**

```ts
export type ReceiptStatus = 'draft' | 'pending' | 'approved' | 'rejected' | 'cancelled'

export type ReceiptItemStatus = 'pending' | 'approved' | 'rejected'

export interface IncomingReceiptItem {
  id: string
  product_id: string
  product_name: string | null
  sku: string | null
  unit: string | null
  weight_initial: string
  weight_final: string | null
  effective_weight: string
  unit_cost: string
  subtotal: string
  status: ReceiptItemStatus
  verification_note: string | null
  created_at: string
  updated_at: string
}

export interface IncomingReceipt {
  id: string
  receipt_number: string
  supplier_id: string
  supplier_name: string | null
  receipt_date: string
  status: ReceiptStatus
  total_weight: string
  total_cost: string
  notes: string | null
  verification_note: string | null
  created_by: string
  created_by_name: string | null
  approved_by: string | null
  approved_by_name: string | null
  approved_at: string | null
  items: IncomingReceiptItem[]
  created_at: string
  updated_at: string
}

export interface PendingProduct {
  id: string
  incoming_receipt_id: string
  receipt_number: string | null
  product_id: string
  product_name: string | null
  sku: string | null
  unit: string | null
  weight_initial: string
  weight_final: string | null
  effective_weight: string
  unit_cost: string
  subtotal: string
  status: ReceiptItemStatus
  verification_note: string | null
  updated_at: string
}
```

- [ ] **Step 2: Tambah kode error di `errors.ts`**

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
  'IDEMPOTENCY_CONFLICT',
] as const
```

- [ ] **Step 3: Tambah fungsi client di `client.ts`**

```ts
import type {
  IncomingReceipt,
  PendingProduct,
  ReceiptStatus,
} from './types'

export interface ReceiptFilters {
  status?: ReceiptStatus
  supplier_id?: string
  search?: string
  date_from?: string
  date_to?: string
  sort?: string
}

export async function fetchIncomingReceipts(
  page = 1,
  filters?: ReceiptFilters,
): Promise<PaginatedEnvelope<IncomingReceipt>> {
  const { data } = await apiClient.get<PaginatedEnvelope<IncomingReceipt>>('/api/v1/incoming-receipts', {
    params: { page, ...filters },
  })

  return data
}

export async function fetchIncomingReceipt(id: string): Promise<IncomingReceipt> {
  const { data } = await apiClient.get<Envelope<IncomingReceipt>>(`/api/v1/incoming-receipts/${id}`)

  return data.data
}

export interface ReceiptItemPayload {
  product_id: string
  weight_initial: string
  weight_final?: string | null
  unit_cost: string
}

export async function createIncomingReceipt(payload: {
  supplier_id: string
  receipt_date: string
  notes?: string
  items: ReceiptItemPayload[]
}): Promise<IncomingReceipt> {
  const { data } = await apiClient.post<Envelope<IncomingReceipt>>('/api/v1/incoming-receipts', payload)

  return data.data
}

export async function updateIncomingReceipt(
  id: string,
  payload: {
    supplier_id?: string
    receipt_date?: string
    notes?: string
    items?: ReceiptItemPayload[]
  },
): Promise<IncomingReceipt> {
  const { data } = await apiClient.patch<Envelope<IncomingReceipt>>(
    `/api/v1/incoming-receipts/${id}`,
    payload,
  )

  return data.data
}

export async function submitIncomingReceipt(id: string): Promise<IncomingReceipt> {
  const { data } = await apiClient.post<Envelope<IncomingReceipt>>(
    `/api/v1/incoming-receipts/${id}/submit`,
  )

  return data.data
}

export async function verifyIncomingReceipt(
  id: string,
  payload: { decision: 'approved' | 'rejected'; reason?: string },
  idempotencyKey: string,
): Promise<IncomingReceipt> {
  const { data } = await apiClient.post<Envelope<IncomingReceipt>>(
    `/api/v1/incoming-receipts/${id}/verify`,
    payload,
    { headers: { 'Idempotency-Key': idempotencyKey } },
  )

  return data.data
}

export async function cancelIncomingReceipt(
  id: string,
  reason: string | undefined,
  idempotencyKey: string,
): Promise<IncomingReceipt> {
  const { data } = await apiClient.post<Envelope<IncomingReceipt>>(
    `/api/v1/incoming-receipts/${id}/cancel`,
    { reason },
    { headers: { 'Idempotency-Key': idempotencyKey } },
  )

  return data.data
}

export async function fetchPendingProducts(
  page = 1,
  filters?: { product_id?: string; search?: string },
): Promise<PaginatedEnvelope<PendingProduct>> {
  const { data } = await apiClient.get<PaginatedEnvelope<PendingProduct>>(
    '/api/v1/inventory/pending-products',
    { params: { page, ...filters } },
  )

  return data
}

export async function updatePendingProduct(
  id: string,
  payload: { weight_final: string; note?: string },
): Promise<PendingProduct> {
  const { data } = await apiClient.patch<Envelope<PendingProduct>>(
    `/api/v1/inventory/pending-products/${id}`,
    payload,
  )

  return data.data
}
```

**Catatan:** `Idempotency-Key` harus dibuat baru untuk setiap attempt yang berbeda secara makna, tetapi **sama** untuk retry atas attempt yang sama. Di frontend, key dibuat sekali saat pengguna menekan tombol (bukan di dalam retry otomatis axios), sehingga klik ganda di-cegah oleh `disabled` tombol dan retry yang sama memakai key yang sama.

- [ ] **Step 4: Jalankan pemeriksaan statis**

```bash
cd /d/DEV/0.AI/Cinnamon2/frontend && npx tsc -b && npm run lint
```

Expected: tanpa error.

- [ ] **Step 5: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add frontend/src/lib/api && git commit -m "frontend: add API client functions for incoming receipts"
```

---

## Task 11: Komponen UI Bersama

**Files:**
- Create: `frontend/src/components/ui/StatusBadge.tsx`
- Create: `frontend/src/components/ui/ConfirmDialog.tsx`
- Create: `frontend/src/components/ui/Field.tsx`

**Interfaces:**
- Consumes: token CSS yang sudah ada (`frontend/src/styles/tokens.css`).
- Produces:
  - `StatusBadge({ status }: { status: ReceiptStatus | ReceiptItemStatus })` — label Bahasa Indonesia + kelas warna. Peta: `draft` → "Draft", `pending` → "Menunggu Verifikasi", `approved` → "Disetujui", `rejected` → "Ditolak", `cancelled` → "Dibatalkan". Warna tidak pernah jadi satu-satunya penanda — label teks selalu ada.
  - `ConfirmDialog({ open, title, description, confirmLabel, cancelLabel, destructive, pending, onConfirm, onCancel })` — dialog konfirmasi yang menutup dengan tombol "Batal" atau "Ya, lanjutkan". Fokus berpindah ke dialog saat dibuka dan kembali ke pemicu saat ditutup.
  - `Field({ label, htmlFor, error, hint, required, children })` — pembungkus label + input + pesan error, mengaitkan pesan error dengan input lewat `aria-describedby`.

- [ ] **Step 1: Buat `StatusBadge`**

```tsx
import type { ReceiptItemStatus, ReceiptStatus } from '../../lib/api/types'
import './StatusBadge.css'

const LABELS: Record<ReceiptStatus | ReceiptItemStatus, string> = {
  draft: 'Draft',
  pending: 'Menunggu Verifikasi',
  approved: 'Disetujui',
  rejected: 'Ditolak',
  cancelled: 'Dibatalkan',
}

export function StatusBadge({ status }: { status: ReceiptStatus | ReceiptItemStatus }) {
  return (
    <span className={`status-badge status-badge--${status}`}>
      <span className="status-badge__dot" aria-hidden="true" />
      {LABELS[status]}
    </span>
  )
}
```

`StatusBadge.css`:

```css
.status-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 2px 10px;
  border-radius: 999px;
  border: 1px solid var(--line);
  background: var(--surface);
  font-size: 12px;
  font-weight: 600;
  color: var(--ink-600);
}

.status-badge__dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: currentColor;
}

.status-badge--pending {
  color: var(--accent-600);
  border-color: var(--accent-600);
}

.status-badge--approved {
  color: var(--success-700);
  border-color: var(--success-700);
}

.status-badge--rejected {
  color: var(--danger-700);
  border-color: var(--danger-700);
}

.status-badge--cancelled {
  color: var(--ink-600);
}
```

- [ ] **Step 2: Buat `ConfirmDialog`**

```tsx
import { useEffect, useRef } from 'react'
import './ConfirmDialog.css'

interface ConfirmDialogProps {
  open: boolean
  title: string
  description: string
  confirmLabel: string
  cancelLabel?: string
  destructive?: boolean
  pending?: boolean
  onConfirm: () => void
  onCancel: () => void
}

export function ConfirmDialog({
  open,
  title,
  description,
  confirmLabel,
  cancelLabel = 'Batal',
  destructive = false,
  pending = false,
  onConfirm,
  onCancel,
}: ConfirmDialogProps) {
  const confirmRef = useRef<HTMLButtonElement>(null)

  // The approval is the consequential action in this screen, so focus starts
  // there and returns to the trigger when the dialog closes.
  useEffect(() => {
    if (open) {
      confirmRef.current?.focus()
    }
  }, [open])

  if (!open) {
    return null
  }

  return (
    <div className="confirm-dialog__backdrop" role="presentation">
      <div
        className="confirm-dialog"
        role="alertdialog"
        aria-modal="true"
        aria-labelledby="confirm-dialog-title"
        aria-describedby="confirm-dialog-description"
      >
        <h2 id="confirm-dialog-title" className="confirm-dialog__title">
          {title}
        </h2>
        <p id="confirm-dialog-description" className="confirm-dialog__description">
          {description}
        </p>
        <div className="confirm-dialog__actions">
          <button type="button" className="button button--secondary" onClick={onCancel} disabled={pending}>
            {cancelLabel}
          </button>
          <button
            type="button"
            ref={confirmRef}
            className={`button ${destructive ? 'button--danger' : 'button--primary'}`}
            onClick={onConfirm}
            disabled={pending}
          >
            {pending ? 'Memproses…' : confirmLabel}
          </button>
        </div>
      </div>
    </div>
  )
}
```

- [ ] **Step 3: Buat `Field`**

```tsx
import type { ReactNode } from 'react'
import './Field.css'

interface FieldProps {
  label: string
  htmlFor: string
  error?: string
  hint?: string
  required?: boolean
  children: ReactNode
}

export function Field({ label, htmlFor, error, hint, required, children }: FieldProps) {
  const errorId = `${htmlFor}-error`
  const hintId = `${htmlFor}-hint`

  return (
    <div className={`field${error ? ' field--invalid' : ''}`}>
      <label className="field__label" htmlFor={htmlFor}>
        {label}
        {required && (
          <span className="field__required" aria-hidden="true">
            {' '}
            *
          </span>
        )}
      </label>
      {children}
      {hint && (
        <p className="field__hint" id={hintId}>
          {hint}
        </p>
      )}
      {error && (
        <p className="field__error" id={errorId} role="alert">
          {error}
        </p>
      )}
    </div>
  )
}
```

Input di dalam `Field` harus diberi `aria-describedby` yang menunjuk `errorId` bila ada error dan `hintId` bila ada hint. Untuk sederhana, jangan render hint dan error bersamaan — hint hanya muncul bila tidak ada error.

- [ ] **Step 4: Jalankan pemeriksaan statis**

```bash
cd /d/DEV/0.AI/Cinnamon2/frontend && npx tsc -b && npm run lint
```

Expected: tanpa error.

- [ ] **Step 5: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add frontend/src/components/ui && git commit -m "frontend: add status badge, confirm dialog, and field components"
```

---

## Task 12: Halaman Penerimaan (Baru, Pending, Riwayat, Detail)

**Files:**
- Create: `frontend/src/features/receiving/ProductPicker.tsx`
- Create: `frontend/src/features/receiving/LineItemEditor.tsx`
- Create: `frontend/src/features/receiving/ReceivingNewPage.tsx`
- Create: `frontend/src/features/receiving/ReceivingPendingPage.tsx`
- Create: `frontend/src/features/receiving/ReceivingHistoryPage.tsx`
- Create: `frontend/src/features/receiving/ReceiptDetailPage.tsx`
- Modify: `frontend/src/app/router.tsx`

**Interfaces:**
- Consumes: API client dari Task 10, komponen UI dari Task 11, `PageState` dari Phase 1, `formatQuantity`/`formatMoney` dari `frontend/src/lib/formatters/`.
- Produces:
  - `ProductPicker({ onSelect }: { onSelect: (product: Product) => void })` — input pencarian produk (SKU/nama) dengan hasil dari `GET /api/v1/products?search=…`. Menampilkan SKU, nama, satuan, dan stok ready. Hanya produk aktif yang bisa dipilih.
  - `LineItemEditor({ items, onChange }: { items: DraftLine[]; onChange: (items: DraftLine[]) => void })` — editor baris item: produk, berat awal, berat final, unit cost, subtotal (dihitung sebagai tampilan saja; server yang berwenang).
  - `ReceivingNewPage` — form penerimaan baru. Field: supplier (dropdown dari `GET /suppliers`), tanggal (default hari ini), catatan, baris item. Tombol "Simpan Draft" dan "Simpan & Kirim untuk Verifikasi".
  - `ReceivingPendingPage` — daftar item menunggu verifikasi (`GET /inventory/pending-products`) dengan kolom: nomor receipt, produk, berat awal, berat final, unit cost, status. Tombol "Verifikasi" membuka form berat final; ubah berat → simpan (PATCH pending item).
  - `ReceivingHistoryPage` — daftar penerimaan dengan filter status, pencarian nomor receipt, rentang tanggal, pagination, dan link ke detail.
  - `ReceiptDetailPage` — detail satu penerimaan: header (nomor, supplier, tanggal, status, pembuat, approver), tabel item, total, dan panel aksi:
    - Draft → "Kirim untuk Verifikasi" (Admin & pemilik) dan "Ubah".
    - Pending → "Setujui" (Admin), "Tolak" (Admin, wajib alasan), "Batalkan" (Admin).
    - Approved/Rejected/Cancelled → read-only.
    - Setujui/Tolak/Batalkan memakai `ConfirmDialog` yang menjelaskan dampak stok, dan mengirim `Idempotency-Key`.

- [ ] **Step 1: Buat `ProductPicker`**

```tsx
import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { fetchProducts } from '../../lib/api/client'
import type { Product } from '../../lib/api/types'
import { formatQuantity } from '../../lib/formatters/number'
import './ProductPicker.css'

interface ProductPickerProps {
  onSelect: (product: Product) => void
}

export function ProductPicker({ onSelect }: ProductPickerProps) {
  const [term, setTerm] = useState('')

  const productsQuery = useQuery({
    queryKey: ['products', 'picker', term],
    queryFn: () => fetchProducts(1, { search: term, is_active: true }),
    enabled: term.trim().length > 0,
  })

  const results = (productsQuery.data?.data ?? []).filter((product) => product.is_active)

  return (
    <div className="product-picker">
      <label className="product-picker__label" htmlFor="product-picker-search">
        Cari produk
      </label>
      <input
        id="product-picker-search"
        className="product-picker__input"
        type="search"
        value={term}
        onChange={(event) => setTerm(event.target.value)}
        placeholder="Ketik SKU atau nama produk"
        autoComplete="off"
      />

      {term.trim().length > 0 && productsQuery.isFetching && (
        <p className="product-picker__hint">Mencari…</p>
      )}

      {term.trim().length > 0 && productsQuery.isSuccess && results.length === 0 && (
        <p className="product-picker__hint">Produk tidak ditemukan.</p>
      )}

      {results.length > 0 && (
        <ul className="product-picker__results">
          {results.map((product) => (
            <li key={product.id}>
              <button type="button" className="product-picker__result" onClick={() => onSelect(product)}>
                <span className="product-picker__sku">{product.sku}</span>
                <span className="product-picker__name">{product.name}</span>
                <span className="product-picker__stock">
                  Stok ready {formatQuantity(product.ready_stock)} {product.unit}
                </span>
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
```

**Catatan:** `formatQuantity(value)` menerima satu argumen dan mengembalikan 3 desimal dengan koma pemisah; `formatMoney(value)` mengembalikan `Rp 42.000`. Keduanya sudah ada di `frontend/src/lib/formatters/number.ts`. `formatQuantity` tidak menerima satuan — satuan ditambahkan terpisah.

- [ ] **Step 2: Buat `LineItemEditor`**

```tsx
import { formatMoney } from '../../lib/formatters/number'
import { ProductPicker } from './ProductPicker'
import './LineItemEditor.css'

export interface DraftLine {
  key: string
  product_id: string
  product_name: string
  sku: string
  unit: string
  weight_initial: string
  weight_final: string
  unit_cost: string
}

interface LineItemEditorProps {
  items: DraftLine[]
  onChange: (items: DraftLine[]) => void
  readOnly?: boolean
}

export function LineItemEditor({ items, onChange, readOnly = false }: LineItemEditorProps) {
  function update(key: string, patch: Partial<DraftLine>) {
    onChange(items.map((item) => (item.key === key ? { ...item, ...patch } : item)))
  }

  function remove(key: string) {
    onChange(items.filter((item) => item.key !== key))
  }

  return (
    <div className="line-items">
      <table className="line-items__table">
        <caption className="line-items__caption">Barang yang diterima</caption>
        <thead>
          <tr>
            <th scope="col">Produk</th>
            <th scope="col">Berat awal (kg)</th>
            <th scope="col">Berat final (kg)</th>
            <th scope="col">Harga beli (Rp)</th>
            <th scope="col">Subtotal</th>
            {!readOnly && <th scope="col">Aksi</th>}
          </tr>
        </thead>
        <tbody>
          {items.map((item) => (
            <tr key={item.key}>
              <th scope="row" className="line-items__product">
                {item.product_name}
                <span className="line-items__sku">{item.sku}</span>
              </th>
              <td>
                <input
                  type="number"
                  step="0.001"
                  min="0"
                  className="line-items__input"
                  value={item.weight_initial}
                  onChange={(event) => update(item.key, { weight_initial: event.target.value })}
                  disabled={readOnly}
                  aria-label={`Berat awal ${item.product_name}`}
                />
              </td>
              <td>
                <input
                  type="number"
                  step="0.001"
                  min="0"
                  className="line-items__input"
                  value={item.weight_final}
                  onChange={(event) => update(item.key, { weight_final: event.target.value })}
                  disabled={readOnly}
                  aria-label={`Berat final ${item.product_name}`}
                />
              </td>
              <td>
                <input
                  type="number"
                  step="0.01"
                  min="0"
                  className="line-items__input"
                  value={item.unit_cost}
                  onChange={(event) => update(item.key, { unit_cost: event.target.value })}
                  disabled={readOnly}
                  aria-label={`Harga beli ${item.product_name}`}
                />
              </td>
              <td className="line-items__subtotal">
                {formatMoney(
                  Number(item.weight_final || item.weight_initial) * Number(item.unit_cost || 0),
                )}
              </td>
              {!readOnly && (
                <td>
                  <button type="button" className="button button--ghost" onClick={() => remove(item.key)}>
                    Hapus
                  </button>
                </td>
              )}
            </tr>
          ))}
        </tbody>
      </table>

      {!readOnly && (
        <ProductPicker
          onSelect={(product) => {
            onChange([
              ...items,
              {
                key: `${product.id}-${items.length}-${Date.now()}`,
                product_id: product.id,
                product_name: product.name,
                sku: product.sku,
                unit: product.unit,
                weight_initial: '',
                weight_final: '',
                unit_cost: product.purchase_price ?? '',
              },
            ])
          }}
        />
      )}
    </div>
  )
}
```

Subtotal di tabel adalah tampilan membantu; angka otoritatif datang dari server setelah disimpan (`total_cost` pada response).

- [ ] **Step 3: Buat `ReceivingNewPage`**

```tsx
import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { PageState } from '../../components/feedback/PageState'
import { Field } from '../../components/ui/Field'
import {
  createIncomingReceipt,
  fetchSuppliers,
  submitIncomingReceipt,
  toApiError,
} from '../../lib/api/client'
import type { ApiError } from '../../lib/api/errors'
import { LineItemEditor, type DraftLine } from './LineItemEditor'
import './ReceivingNewPage.css'

export function ReceivingNewPage() {
  const queryClient = useQueryClient()
  const [supplierId, setSupplierId] = useState('')
  const [receiptDate, setReceiptDate] = useState(new Date().toISOString().slice(0, 10))
  const [notes, setNotes] = useState('')
  const [items, setItems] = useState<DraftLine[]>([])
  const [failure, setFailure] = useState<string | null>(null)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({})

  const suppliersQuery = useQuery({
    queryKey: ['suppliers', 'picker'],
    queryFn: () => fetchSuppliers(1),
  })

  // `sendNow` decides whether the document stops at draft or goes straight to
  // verification. The draft is created first either way, so the receipt
  // number exists before anything is submitted.
  const createMutation = useMutation({
    mutationFn: async (sendNow: boolean) => {
      const receipt = await createIncomingReceipt({
        supplier_id: supplierId,
        receipt_date: receiptDate,
        notes: notes.trim() || undefined,
        items: items.map((item) => ({
          product_id: item.product_id,
          weight_initial: item.weight_initial,
          weight_final: item.weight_final || null,
          unit_cost: item.unit_cost,
        })),
      })

      if (sendNow) {
        await submitIncomingReceipt(receipt.id)
      }

      return receipt
    },
    onSuccess: async () => {
      setFailure(null)
      setFieldErrors({})

      await queryClient.invalidateQueries({ queryKey: ['incoming-receipts'] })
      await queryClient.invalidateQueries({ queryKey: ['pending-products'] })
      await queryClient.invalidateQueries({ queryKey: ['products'] })
    },
    onError: (error) => {
      const apiError: ApiError = toApiError(error)
      setFailure(apiError.message)
      setFieldErrors(apiError.fields)
    },
  })

  const suppliers = (suppliersQuery.data?.data ?? []).filter((supplier) => supplier.is_active)
  const canSubmit = supplierId !== '' && receiptDate !== '' && items.length > 0

  return (
    <section>
      <h1>Penerimaan Baru</h1>
      <p className="receiving__intro">
        Catat barang yang masuk. Nomor dokumen dibuat otomatis saat disimpan. Barang baru menjadi stok
        siap jual setelah Admin menyetujuinya.
      </p>

      {failure && (
        <p className="receiving__error" role="alert">
          {failure}
        </p>
      )}

      {suppliersQuery.isLoading && <PageState variant="loading" />}

      {suppliersQuery.isError && (
        <PageState
          variant="error"
          message="Daftar supplier gagal dimuat. Silakan coba lagi."
          onRetry={() => void suppliersQuery.refetch()}
        />
      )}

      {suppliersQuery.isSuccess && suppliers.length === 0 && (
        <PageState
          variant="empty"
          title="Belum ada supplier aktif"
          message="Tambahkan supplier di Master Data lebih dulu sebelum mencatat penerimaan."
        />
      )}

      {suppliersQuery.isSuccess && suppliers.length > 0 && (
        <form
          className="receiving__form"
          onSubmit={(event) => {
            event.preventDefault()
            createMutation.mutate(false)
          }}
        >
          <Field label="Supplier" htmlFor="receipt-supplier" required error={fieldErrors.supplier_id?.[0]}>
            <select
              id="receipt-supplier"
              className="receiving__select"
              value={supplierId}
              onChange={(event) => setSupplierId(event.target.value)}
              required
            >
              <option value="">Pilih supplier…</option>
              {suppliers.map((supplier) => (
                <option key={supplier.id} value={supplier.id}>
                  {supplier.name}
                </option>
              ))}
            </select>
          </Field>

          <Field label="Tanggal penerimaan" htmlFor="receipt-date" required error={fieldErrors.receipt_date?.[0]}>
            <input
              id="receipt-date"
              className="receiving__input"
              type="date"
              value={receiptDate}
              onChange={(event) => setReceiptDate(event.target.value)}
              required
            />
          </Field>

          <Field label="Catatan" htmlFor="receipt-notes" error={fieldErrors.notes?.[0]}>
            <textarea
              id="receipt-notes"
              className="receiving__textarea"
              value={notes}
              onChange={(event) => setNotes(event.target.value)}
              rows={3}
            />
          </Field>

          <LineItemEditor items={items} onChange={setItems} />

          {fieldErrors.items && (
            <p className="receiving__error" role="alert">
              {fieldErrors.items[0]}
            </p>
          )}

          <div className="receiving__actions">
            <button type="submit" className="button button--secondary" disabled={!canSubmit || createMutation.isPending}>
              {createMutation.isPending ? 'Menyimpan…' : 'Simpan Draft'}
            </button>
            <button
              type="button"
              className="button button--primary"
              disabled={!canSubmit || createMutation.isPending}
              onClick={() => createMutation.mutate(true)}
            >
              {createMutation.isPending ? 'Mengirim…' : 'Simpan & Kirim untuk Verifikasi'}
            </button>
          </div>
        </form>
      )}
    </section>
  )
}
```

Catatan:

- Dua tombol memakai `disabled` yang sama, jadi klik ganda tidak mengirim dua dokumen.
- Error per-field dibaca dari `fieldErrors.supplier_id?.[0]`, `fieldErrors.receipt_date?.[0]`, `fieldErrors.notes?.[0]`, dan `fieldErrors.items?.[0]`.
- `receiptDate` di-set dari `new Date().toISOString().slice(0, 10)` — UTC. Untuk zona Asia/Jakarta, gunakan helper lokal:

```ts
function todayInJakarta(): string {
  return new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Jakarta',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(new Date())
}
```

Gunakan helper ini sebagai nilai awal `receiptDate`.

- [ ] **Step 4: Buat `ReceivingPendingPage`**

Halaman menampilkan tabel item pending:

| Receipt | Produk | Berat awal | Berat final | Harga beli | Efektif | Aksi |

Kolom "Efektif" memakai `effective_weight` dari server. Kolom "Aksi" berisi tombol "Verifikasi" yang membuka inline form berisi input `weight_final` (default: `weight_final` lama atau `weight_initial`) dan tombol "Simpan". Simpan memanggil `updatePendingProduct`.

Query:

```tsx
const pendingQuery = useQuery({
  queryKey: ['pending-products'],
  queryFn: () => fetchPendingProducts(1),
})
```

Setelah mutasi sukses: `invalidateQueries({ queryKey: ['pending-products'] })` dan `invalidateQueries({ queryKey: ['products'] })` (karena pending_stock berubah).

- [ ] **Step 5: Buat `ReceivingHistoryPage`**

Tabel penerimaan dengan kolom: Nomor, Supplier, Tanggal, Status, Total berat, Total biaya, Pembuat, Aksi (link "Detail").

Filter di atas tabel: dropdown status (semua, draft, pending, approved, rejected, cancelled), input pencarian nomor receipt, input tanggal dari, input tanggal sampai. Pagination memakai `meta.current_page`, `meta.last_page`, `meta.total`.

Query:

```tsx
const receiptsQuery = useQuery({
  queryKey: ['incoming-receipts', page, status, search, dateFrom, dateTo],
  queryFn: () => fetchIncomingReceipts(page, { status, search, date_from: dateFrom, date_to: dateTo }),
})
```

- [ ] **Step 6: Buat `ReceiptDetailPage`**

Ambil data dengan `useQuery({ queryKey: ['incoming-receipt', id], queryFn: () => fetchIncomingReceipt(id) })`.

Tampilan:
- Header: nomor receipt (h1), badge status, supplier, tanggal, pembuat, approver + waktu approval, catatan, alasan verifikasi.
- Tabel item: produk, SKU, berat awal, berat final, berat efektif, harga beli, subtotal, status.
- Total: total berat dan total biaya dari server.
- Panel aksi sesuai status.

Panel aksi "Setujui":

```tsx
const [confirmApprove, setConfirmApprove] = useState(false)
const [rejectReason, setRejectReason] = useState('')
const [confirmReject, setConfirmReject] = useState(false)

const verifyMutation = useMutation({
  mutationFn: (payload: { decision: 'approved' | 'rejected'; reason?: string }) =>
    verifyIncomingReceipt(id, payload, idempotencyKeyRef.current),
  onSuccess: () => {
    setConfirmApprove(false)
    setConfirmReject(false)
    void queryClient.invalidateQueries({ queryKey: ['incoming-receipt', id] })
    void queryClient.invalidateQueries({ queryKey: ['incoming-receipts'] })
    void queryClient.invalidateQueries({ queryKey: ['pending-products'] })
    void queryClient.invalidateQueries({ queryKey: ['products'] })
  },
})
```

`idempotencyKeyRef` adalah `useRef(crypto.randomUUID())` yang **di-regenerate setiap kali dialog dibuka** (bukan setiap retry), sehingga klik ganda memakai key yang sama dan tidak menggandakan dampak:

```tsx
const idempotencyKeyRef = useRef<string>('')

function openApprove() {
  idempotencyKeyRef.current = crypto.randomUUID()
  setConfirmApprove(true)
}
```

Dialog persetujuan menjelaskan dampak:

> "Menyetujui penerimaan ini akan memindahkan 24,500 kg dari stok menunggu verifikasi menjadi stok siap jual, membuat batch baru, dan mencatat movements, ledger, dan audit. Tindakan ini tidak dapat dibatalkan."

Klik "Ya, setujui" → `verifyMutation.mutate({ decision: 'approved' })`.

Dialog penolakan mewajibkan alasan (input teks minimal 5 karakter; validasi server tetap yang berwenang):

> "Menolak penerimaan ini akan mengembalikan barang ke supplier dan melepas stok menunggu verifikasi. Stok siap jual tidak bertambah."

- [ ] **Step 7: Tambahkan route**

Di `frontend/src/app/router.tsx`:

```tsx
import { ReceivingNewPage } from '../features/receiving/ReceivingNewPage'
import { ReceivingPendingPage } from '../features/receiving/ReceivingPendingPage'
import { ReceivingHistoryPage } from '../features/receiving/ReceivingHistoryPage'
import { ReceiptDetailPage } from '../features/receiving/ReceiptDetailPage'
```

```tsx
{ path: 'receiving/new', element: <ReceivingNewPage /> },
{ path: 'receiving/pending', element: <ReceivingPendingPage /> },
{ path: 'receiving/history', element: <ReceivingHistoryPage /> },
{ path: 'receiving/:id', element: <ReceiptDetailPage /> },
```

Tautan dari `ReceivingHistoryPage` ke detail memakai `/receiving/${id}`. Route `receiving/:id` harus diletakkan **setelah** `receiving/new`, `receiving/pending`, `receiving/history` supaya path statis menang.

- [ ] **Step 8: Jalankan pemeriksaan statis**

```bash
cd /d/DEV/0.AI/Cinnamon2/frontend && npx tsc -b && npm run lint && npm run build
```

Expected: tanpa error, build sukses.

- [ ] **Step 9: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add frontend/src/features/receiving frontend/src/app && git commit -m "frontend: add receiving screens for new receipts, pending, history, and detail"
```

---

## Task 13: Verifikasi Live End-to-End dan Dokumentasi

**Files:**
- Modify: `backend/database/seeders/DatabaseSeeder.php`
- Create: `Docs/phase-2-2-done.md`
- Modify: `Docs/roadmap.md`
- Modify: `Docs/Database.md`
- Modify: `Docs/decisions.md`

**Interfaces:**
- Consumes: hasil verifikasi Task 9 dan Task 12.
- Produces: seeder demo yang memuat satu penerimaan sudah disetujui (agar Phase 2.3 dan Phase 3.1 punya data awal), dokumen `Docs/phase-2-2-done.md`, dan pembaruan dokumen acuan.

- [ ] **Step 1: Tambahkan seeder demo penerimaan**

Di `backend/database/seeders/DatabaseSeeder.php`, simpan hasil pembuatan user dan produk demo dalam variabel agar bisa dipakai seeder penerimaan. Bentuk `run()` setelah Task 2.1 selesai (nama variabel `$admin`, `$pala`, `$supplier1` mengikuti penamaan di seeder Phase 2.1):

```php
use App\Enums\MovementType;
use App\Enums\ReceiptItemStatus;
use App\Enums\ReceiptStatus;
use App\Models\IncomingReceipt;
use App\Models\IncomingReceiptItem;
use App\Models\LedgerEntry;
use App\Models\StockBatch;
use App\Models\StockMovement;

        $receipt = IncomingReceipt::query()->create([
            'receipt_number' => 'RC-2026-10-001',
            'supplier_id' => $supplier1->id,
            'receipt_date' => '2026-10-15',
            'status' => ReceiptStatus::Approved,
            'total_weight' => '24.500',
            'total_cost' => '1029000.00',
            'notes' => 'Penerimaan contoh untuk pengembangan',
            'created_by' => $admin->id,
            'approved_by' => $admin->id,
            'approved_at' => now(),
        ]);

        $item = IncomingReceiptItem::query()->create([
            'incoming_receipt_id' => $receipt->id,
            'product_id' => $pala->id,
            'weight_initial' => '25.000',
            'weight_final' => '24.500',
            'unit_cost' => '42000.00',
            'subtotal' => '1029000.00',
            'status' => ReceiptItemStatus::Approved,
        ]);

        $batch = StockBatch::query()->create([
            'product_id' => $pala->id,
            'incoming_receipt_item_id' => $item->id,
            'batch_code' => 'BATCH-202610-001',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'received_at' => '2026-10-15',
            'quantity_received' => '24.500',
            'quantity_remaining' => '24.500',
            'unit_cost' => '42000.00',
            'status' => 'open',
        ]);

        StockMovement::query()->create([
            'product_id' => $pala->id,
            'stock_batch_id' => $batch->id,
            'movement_type' => MovementType::Receipt,
            'quantity_in' => '24.500',
            'quantity_out' => 0,
            'ready_before' => 0,
            'ready_after' => '24.500',
            'pending_before' => 0,
            'pending_after' => 0,
            'source_type' => 'incoming_receipt',
            'source_id' => $receipt->id,
            'reason' => 'Penerimaan contoh',
            'recorded_by' => $admin->id,
            'occurred_at' => now(),
        ]);

        LedgerEntry::query()->create([
            'entry_date' => '2026-10-15',
            'source_type' => 'incoming_receipt',
            'source_id' => $receipt->id,
            'description' => 'Penerimaan RC-2026-10-001',
            'debit' => 0,
            'credit' => '1029000.00',
            'balance' => '-1029000.00',
            'created_by' => $admin->id,
        ]);

        $pala->ready_stock = '24.500';
        $pala->save();
```

Baris `AuditLog` juga ditambahkan dengan `action` = `'receipt.approved'` supaya jejaknya konsisten dengan dokumen yang dibuat lewat API.

- [ ] **Step 2: Jalankan seeder dan cek hasil**

```bash
cd /d/DEV/0.AI/Cinnamon2/backend && php artisan db:seed
```

Expected: 1 penerimaan approved, 1 batch, 1 movement, 1 ledger entry, 1 produk dengan ready_stock "24.500".

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
| A | Buka "Penerimaan > Penerimaan Baru" | Form supplier, tanggal, item tampil |
| B | Tambahkan item tanpa memilih produk | Tombol simpan nonaktif atau pesan error tampil |
| C | Isi form lengkap, klik "Simpan Draft" | Kembali ke daftar, dokumen muncul dengan status Draft |
| D | Buka detail dokumen draft | Tombol "Kirim untuk Verifikasi" ada |
| E | Klik "Kirim untuk Verifikasi" | Status jadi "Menunggu Verifikasi", badge berubah |
| F | Buka "Penerimaan > Verifikasi Pending" | Item masuk daftar, kolom Berat final dapat diisi |
| G | Ubah berat final, klik "Simpan" | Nilai baru tersimpan, subtotal ikut berubah |
| H | Buka detail, klik "Setujui" | Dialog konfirmasi menjelaskan dampak stok |
| I | Klik "Ya, setujui" | Status "Disetujui", stok ready naik |
| J | Klik "Setujui" lagi | Tombol tidak ada (status bukan pending) |
| K | Buka "Penerimaan > Riwayat" | Daftar menampilkan semua dokumen dengan filter status |
| L | Filter status "Ditolak" | Hanya dokumen yang ditolak |
| M | Ganti ke akun Karyawan | Tombol "Setujui"/"Tolak" tidak ada; tombol "Penerimaan Baru" ada |
| N | Jendela 480px | Tabel dapat di-scroll horizontal, tombol tetap terjangkau |
| O | Console browser | 0 error, 0 exception |

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

- [ ] **Step 6: Perbarui `Docs/Database.md`**

Pada §4, ubah:

- `incoming_receipts.status` → `` `status` ENUM(`draft`,`pending`,`approved`,`rejected`,`cancelled`) ``.
- `source_id BIGINT` → `source_id UUID` pada `stock_movements` dan `ledger_entries`.
- `resource_id BIGINT` → `resource_id UUID` pada `audit_logs`.
- Tambahkan tabel `idempotency_keys` di §4 dengan keterangan singkat.

Tambahkan catatan pada §5:

> Setiap item penerimaan yang disetujui membuat satu batch sendiri (keputusan §1). Batch `period_start`/`period_end` mencatat periode bisnis 1–15 dan 16–akhir bulan, bukan aturan penggabungan.

- [ ] **Step 7: Perbarui `Docs/decisions.md`**

Tambahkan bagian **§11** setelah §10:

```markdown
## 11. Fase 2.2 — Penerimaan, idempotensi, dan status dokumen

**Nomor dokumen dibuat saat draft.** `RC-2026-10-001` (penerimaan) dan `BATCH-202610-001` (batch) dibuat pada saat dokumen dibuat, bukan saat disetujui, supaya staf bisa menyebut nomornya saat dokumen masih draft.

**Stok pending naik saat `draft → pending`.** Pending stock adalah proyeksi; `stock_movements` ditulis saat approval, ketika barang benar-benar menjadi stok siap jual. Mengubah `weight_final` saat pending menyesuaikan proyeksi tanpa menulis movement baru.

**Berat final boleh diubah selama status `pending`.** Karyawan dan Admin boleh memperbarui `weight_final` sebelum approval. `weight_final` wajib ada saat approval; tanpa itu approval ditolak dengan `VALIDATION_ERROR.fields.weight_final`.

**Status `rejected` dan `cancelled` dibedakan.** `rejected` berarti verifikasi gagal dan alasan wajib tersimpan; `cancelled` berarti dokumen dibatalkan. Keduanya melepas `pending_stock` dan tidak pernah menambah `ready_stock`.

**Idempotensi memakai tabel `idempotency_keys` dengan retensi 24 jam.** `Idempotency-Key` wajib pada `verify` dan `cancel`. Key yang sama dengan payload sama memutar ulang response tersimpan; key yang sama dengan payload berbeda dijawab `409 IDEMPOTENCY_CONFLICT`.

**Kolom polimorfik memakai UUID.** `source_id` dan `resource_id` mengikuti format UUID v7 yang ditetapkan pada §5, bukan BIGINT.

**Batch satu per item penerimaan, periode tetap dicatat.** `period_start`/`period_end` berisi periode bisnis (1–15 dan 16–akhir bulan) sebagai informasi, bukan aturan penggabungan batch.
```

- [ ] **Step 8: Perbarui `Docs/roadmap.md`**

Pada bagian "Phase 2.2 — Penerimaan dan approval", tambahkan:

```markdown
**Status: selesai (2026-10-22).** Ringkasan hasil dan verifikasi: `Docs/phase-2-2-done.md`.
```

- [ ] **Step 9: Buat `Docs/phase-2-2-done.md`**

Struktur sama seperti `Docs/phase-1-done.md`:

- Status: complete
- Yang dibangun (tabel area | hasil)
- Perubahan yang terlihat pengguna
- Verifikasi yang dijalankan (tabel API + tabel browser)
- Keputusan yang diambil saat pengerjaan
- Perbaikan dari review (jika ada)
- Belum dikerjakan
- Cara menjalankan lokal

- [ ] **Step 10: Commit**

```bash
cd /d/DEV/0.AI/Cinnamon2 && git add backend/database frontend/src Docs && git commit -m "docs: record Phase 2.2 completion and update reference documents"
```

---

## Catatan Implementasi

**Urutan task:** Task 1–8 backend (schema, domain, policy, action, controller), Task 9 verifikasi API, Task 10–12 frontend, Task 13 verifikasi end-to-end dan dokumentasi.

**Ketergantungan antar task:**

- Task 6 (Approve/Reject) butuh Task 1, 2, dan 4.
- Task 7 (Submit/Cancel/UpdatePendingItem) butuh Task 1, 2, dan 4.
- Task 8 (Controller) butuh Task 3 (Idempotency), Task 5 (Request/Resource), Task 6, dan Task 7.
- Task 9 butuh Task 8.
- Task 10 butuh Task 8.
- Task 11 dan 12 bisa dikerjakan paralel setelah Task 10.
- Task 13 butuh semua sebelumnya.

**Pola yang dipakai:**

- Backend mengikuti pola Phase 1 dan 2.1: Form Request untuk validasi, Policy untuk otorisasi, Controller untuk orkestrasi, Resource untuk serialisasi, Query untuk filter.
- Semua efek stok berada di `app/Actions/Receiving/` dan ditulis dalam satu `DB::transaction`. Controller tidak pernah mengubah stok langsung.
- Hitungan uang dan berat memakai `bcmath`; tidak ada float.
- Frontend mengikuti pola Phase 1: TanStack Query untuk cache, React Router untuk navigasi, `PageState` untuk loading/empty/error, `Field` untuk pesan error per-field.

**Yang tidak dikerjakan di Phase 2.2:**

- `POST /incoming-receipts/{receipt}/cancel` mengembalikan dokumen batal, bukan reversal — reversal penjualan ada di Phase 3.1.
- Ringkasan stok (`GET /inventory/stock`), riwayat movement, dan koreksi stok ada di Phase 2.3.
- Penjualan, FIFO, HPP, dan laporan ada di Phase 3.1 dan 3.2.
- `stock_batches.status` tidak pernah berubah ke `depleted` di fase ini — stok batch baru saja dibuat dan belum pernah terjual.
- Verifikasi batch terhadap projection (`reconciliation`) ada di Phase 2.3.
