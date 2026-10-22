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

**Decision:** UUID v7 via Laravel's `HasUuids` trait.

**Details:** Primary keys are `char(36)` with the `HasUuids` trait, whose `newUniqueId()` returns `Str::uuid7()` (time-ordered, so index locality is preserved). Route model binding resolves by `id` because the trait's `resolveRouteBindingQuery` calls `isValidUniqueId()` via `Str::isUuid()` (which accepts any UUID version including v7). No custom resolver is needed.

**Affected:** Database.md row 65 (users.id becomes UUID v7 instead of BIGINT PK), plus every subsequent table's `id`.

**Migration note:** existing legacy tables in the `cinnamon` MySQL database use BIGINT PKs; they are not migrated. The new schema lives in a separate database, `cinnamon_v2`, which starts empty on a clean install. The `cinnamon` database keeps its legacy TA fixtures untouched as an acceptance example for Phase 2/3.

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

## 9. Backend file placement and test database

**Decision:** backend production code is written directly in its proper place — `backend/app/…`, `backend/routes/`, `backend/config/`, `backend/database/`, `backend/bootstrap/`. Backend test code goes in `backend/tests/`, the Laravel convention already wired into `composer.json` (`autoload-dev`) and `phpunit.xml`. Production code is never written under `backend/tests/`, and test code is never written under `backend/app/`. There is exactly one test folder; no duplicate test folder is created.

**Test database:** backend tests run against the same database the app uses (`cinnamon_v2`), wrapped in the `DatabaseTransactions` trait so every test rolls back. No separate test database is created. A separate database is used only for verifying `migrate:fresh` and rollback.

**Commits:** a change that touches both production and test code is committed in two commits — production first, test second — so neither diff is contaminated by the other.

**Affected:** `Docs/AGENTS.md` §5 and §8, `Docs/Architecture.md` §7, `Docs/plans/2026-10-21-phase-1-foundation-and-auth.md` Global Constraints and every task.

## 10. No new test files — verify against the running app

**Decision:** no new test file is written from Phase 1 onward. The existing test suite (47 backend tests, 22 frontend tests) is kept and still runs, but new features are verified by exercising the running application — the API directly and the browser — instead of by authoring new test files.

**Why:** the test suite already covers the Phase 0 surface. Adding a test file per feature costs more tokens and time than it returns on this project, and the checks that matter for a person using the software are end-to-end ones: does the screen work, does the endpoint return the right envelope, is the wrong role actually refused.

**How verification works instead:** every phase ends with a live acceptance pass — the real server on the real database, driven by `curl` for API contracts and by a browser for UI behaviour. The existing suite still runs as a regression net before commit; it is simply never extended.

**Consequence:** the TDD cycle in the phase plans changes shape. A task states the behaviour it must deliver, builds it, then proves it by driving the running app. The "write a failing test first" step is replaced by "write the acceptance check first" — the same discipline, against the real thing rather than a test double.

**Affected:** `Docs/AGENTS.md` §8, `Docs/PRD.md` §8 and §12, `Docs/Architecture.md` §14, `Docs/roadmap.md` Phase 4, `Docs/plans/2026-10-21-phase-1-foundation-and-auth.md` (all tasks).

## 11. Master data — hard delete hanya bila belum direferensikan

**Decision:** `DELETE` pada kategori, produk, supplier, dan customer hanya menghapus baris yang belum dipakai dokumen apa pun. Data yang sudah direferensikan Answer 409 `CONFLICT` dengan pesan Bahasa Indonesia yang menawarkan menonaktifkan sebagai gantinya; penonaktifan dilakukan lewat `PATCH` dengan `is_active=false`.

**Why:** `Docs/AGENTS.md` §4 melarang penghapusan destruktif atas master data yang sudah dipakai, dan `Docs/Database.md` §6 menyatakan produk, supplier, dan customer tidak bisa dihapus keras setelah ada dokumen yang merujuknya. Kode error 409 dipakai karena konflik ini bukan kesalahan validasi input — request-nya valid, sumber dayanya saja yang bentrok.

**Affected:** `Docs/API.md` §4.1, `Docs/Database.md` §5 and §6, plan Phase 2.1 Task 1–5.

## 12. Cakupan Phase 2.1 — customer dan gambar produk ikut masuk

**Decision:** Phase 2.1 mencakup customer (meski opsional di PRD) dan upload gambar produk. Gambar disimpan di `storage/app/public/products` dengan validasi MIME (jpg, png, webp) dan ukuran maksimum 2MB.

**Why:** customer dibutuhkan oleh penjualan di Phase 3.1, dan menambahkannya sekarang menghindari pekerjaan ulang pada schema. Gambar produk adalah bagian dari `Docs/PRD.md` §6.2 dan roadmap 2.1 menyebutnya secara eksplisit ("Gambar produk ditambahkan setelah storage dan validasi file siap").

**Affected:** `Docs/roadmap.md` Phase 2.1, plan Phase 2.1 Task 5 and Task 6.

## 13. Filter dan sort memakai allowlist server

**Decision:** setiap endpoint daftar punya allowlist field `sort`. Nilai di luar allowlist diabaikan dan query memakai urutan default — bukan `INVALID_FILTER`. Filter yang tidak dikenal juga diabaikan, bukan menolak request.

**Why:** `Docs/API.md` §6 menyebut `INVALID_FILTER` sebagai jawaban ideal, tetapi `Docs/phase-1-done.md` mencatat belum adanya implementasi `INVALID_FILTER` dan Phase 1 memilih tetap tidak menerimanya. Menambahkannya sekarang berarti mengubah perilaku endpoint yang sudah dipakai frontend; allowlist sort adalah safeguard yang mencegah query ke kolom yang tidak diizinkan, dan mengabaikan filter yang tidak dikenal tidak merusak data.

**Affected:** `Docs/API.md` §6 (belum terpenuhi sepenuhnya), plan Phase 2.1 Task 5, plan Phase 2.2 Task 8.

## 14. Nomor dokumen dibuat saat draft

**Decision:** `receipt_number` (`RC-YYYY-MM-NNN`) dan `batch_code` (`BATCH-YYYYMM-NNN`) dibuat pada saat dokumen dibuat, bukan saat disetujui.

**Why:** staf perlu menyebut nomor dokumen saat barang masih di lapangan dan dokumen masih berstatus draft. Penomoran berdasarkan periode bulan sehingga tetap terbaca di satu toko tanpa tabel seri terpisah.

**Affected:** `Docs/API.md` §4.2, plan Phase 2.2 Task 4.

## 15. Stok pending naik saat `draft → pending`

**Decision:** saat draft dikirim untuk verifikasi, `products.pending_stock` bertambah sebesar berat efektif tiap item (berat final bila ada, jika tidak berat awal). Saat approval, `pending_stock` turun dan `ready_stock` naik sebesar `weight_final`. Menolak atau membatalkan dokumen melepas `pending_stock` dan tidak pernah menambah `ready_stock`.

**Why:** barang yang sedang diverifikasi sudah ada di gudang dan tidak boleh dijual dua kali; memisahkannya dari ready stock mencegah double selling. Proyeksi pending stock adalah reservation, bukan pergerakan stok — `stock_movements` ditulis saat approval, ketika barang benar-benar menjadi stok siap jual. Mengubah `weight_final` saat pending menyesuaikan proyeksi tanpa menulis movement baru.

**Affected:** `Docs/decisions.md` §7 (dalam konteks transisi pending → ready), plan Phase 2.2 Task 6 and Task 7.

## 16. Berat final boleh diubah selama status `pending`

**Decision:** Karyawan dan Admin boleh memperbarui `weight_final` selama penerimaan berstatus `pending` (endpoint `PATCH /inventory/pending-products/{item}`). `weight_final` wajib ada saat approval; tanpa itu approval ditolak dengan `VALIDATION_ERROR` dan `fields.weight_final`.

**Why:** penimbangan ulang terjadi setelah barang dicatat, dan masa itu dokumen masih menunggu verifikasi. Menunggu Admin mengedit berat akan menambah satu langkah pada alur kerja yang paling sering terjadi. `Docs/API.md` §4.2 sudah memberi kewenangan ini ke Admin dan Karyawan.

**Affected:** `Docs/API.md` §4.2, `Docs/decisions.md` §7, plan Phase 2.2 Task 7.

## 17. Status `rejected` dan `cancelled` dibedakan

**Decision:** enum `incoming_receipts.status` adalah `draft, pending, approved, rejected, cancelled`. `rejected` berarti verifikasi gagal (alasan wajib tersimpan di `verification_note`), `cancelled` berarti dokumen dibatalkan.

**Why:** `Docs/Database.md` §4 hanya menyebut empat status dan tidak punya `rejected`, sementara `Docs/API.md` §5.2 mewajibkan `decision` = `approved` atau `rejected` beserta alasan saat penolakan. Dua hal berbeda perlu dua status berbeda supaya audit distinguishing "barang ditolak saat cek" dari "dokumen dibatalkan".

**Affected:** `Docs/Database.md` §4 (tabel `incoming_receipts`), `Docs/API.md` §5.2, plan Phase 2.2 Task 1.

## 18. Idempotensi memakai tabel `idempotency_keys` dengan retensi 24 jam

**Decision:** tabel `idempotency_keys` menyimpan key, scope, hash payload, response, dan waktu kedaluwarsa (24 jam). `Idempotency-Key` wajib pada `verify` dan `cancel`. Tanpa header → 400 `BAD_REQUEST`. Key yang sama dengan payload sama memutar ulang response tersimpan tanpa efek samping; key yang sama dengan payload berbeda → 409 `IDEMPOTENCY_CONFLICT`.

**Why:** `Docs/API.md` §8 mewajibkan idempotensi pada `verify`, `post`, `cancel`, dan `adjustment/post`, tetapi belum menetapkan jangka retensi maupun bentuk implementasinya. 24 jam cukup untuk jendela retry dan tidak butuh scheduled job — baris kedaluwarsa dibersihkan secara lazy saat key baru disimpan.

**Affected:** `Docs/API.md` §8, plan Phase 2.2 Task 3 and Task 8.

## 19. Kolom polimorfik memakai UUID

**Decision:** `stock_movements.source_id`, `ledger_entries.source_id`, dan `audit_logs.resource_id` bertipe UUID, bukan BIGINT.

**Why:** `Docs/Database.md` §4 masih menyebut BIGINT untuk kolom ini, sementara `Docs/decisions.md` §5 menetapkan UUID v7 untuk seluruh tabel. Kolom `source_id`/`resource_id` adalah foreign key polimorfik yang menunjuk id tabel lain, jadi harus mengikuti format id yang sama supaya nilainya bisa disimpan tanpa konversi.

**Affected:** `Docs/Database.md` §4, plan Phase 2.2 Task 2.

---

**Open / blocked decisions:** none. All six roadmap open items are resolved here.
