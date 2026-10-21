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

**Affected:** `Docs/AGENTS.md` §5 and §8, `Docs/Architecture.md` §7, `Docs/plans/2026-10-21-phase-1-foundation-and-auth.md` Global Constraints and Tasks 3, 4, 6, 7, 8, 9, 12, 13.

---

**Open / blocked decisions:** none. All six roadmap open items are resolved here.
