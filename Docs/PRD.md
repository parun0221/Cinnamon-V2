# Product Requirements Document

## CINNAMON Inventory and Sales Management

**Status:** Revised implementation baseline  
**Version:** 2.0  
**Product owner:** Fachrul Ihsan  
**Business context:** Toko Wahana Eka Sentral  
**Primary clients:** React web application; Flutter remains optional for a later warehouse workflow

## 1. Product summary

CINNAMON is an inventory and sales system for a spice distribution store. It replaces manual records with a traceable workflow for products, suppliers, incoming goods, pending and ready stock, outgoing sales, FIFO batch allocation, stock correction, simple ledger records, and reports.

The application is a portfolio-quality modular monolith: Laravel provides the API and business rules, React with TypeScript provides the web interface, and MySQL stores the data. The MVP models one store and two user roles from the previous TA: **Admin** and **Karyawan**.

This document is the product source of truth. It deliberately keeps multi-warehouse operations, advanced accounting, external notifications, and mobile workflows outside the first release.

## 2. Evidence and decisions

The previous TA and the original project README establish these requirements:

- The case is Toko Wahana Eka Sentral and its spice products.
- The main problems are delayed stock updates, manual records, and slow report preparation.
- Incoming goods must be checked before they become ready stock.
- Stock has a pending state and a ready state.
- A receipt is grouped into a stock batch. The business period is day 1-15 and day 16 to the end of the month.
- Outgoing goods use FIFO by the oldest batch first.
- HPP is calculated from the actual batch allocations used by a sale.
- Reports cover incoming goods, outgoing goods, stock, stock movements, sales, HPP, profit, and a simple internal ledger.
- The original TA has two roles: Admin has full access and Karyawan has restricted operational access.

The README's Laravel 12 + Blade/Vite stack describes the earlier implementation. For this portfolio rebuild, the business requirements stay authoritative while the agreed delivery stack becomes Laravel 13 API + React TypeScript. This is a deliberate migration decision, not an attempt to run two frontends in parallel.

### Decisions requiring confirmation before production

1. The MVP serves one store and does not expose a warehouse selector.
2. Admin approves incoming goods; Karyawan may record incoming goods and manage pending products.
3. A pending receipt becomes ready stock only after approval. The exact physical checking rule for `berat_awal` and `berat_final` must be confirmed by the store.
4. A product and date period share one batch when the business considers them the same receipt period. If every receipt must remain a separate lot, the batch rule must be changed before implementation.
5. Customers are optional on a sale because the TA calls the party a client but does not require a complete customer master workflow.
6. The ledger is an internal operational summary, not a statutory accounting or tax system.

## 3. Problem and value proposition

### Problem

- Stock information is not updated consistently after incoming and outgoing transactions.
- Staff cannot quickly distinguish pending stock from ready-to-sell stock.
- Manual batch records make FIFO, HPP, and profit difficult to verify.
- Corrections can be made without a reliable history.
- Sales, purchase, stock, and simple financial reports are prepared separately.
- The store has limited role separation and auditability.

### Value proposition

CINNAMON gives the store one workflow in which every approved receipt, sale, FIFO allocation, correction, and report can be traced to an actor, date, quantity, batch, and source document.

## 4. Target users and roles

### Admin

The Admin manages categories, products, suppliers, customers, users, incoming approval, stock correction, sales, reports, and the simple ledger.

### Karyawan

The Karyawan records incoming goods and manages products that are still pending or not yet ready. Karyawan cannot manage users, change master data, record outgoing sales, approve receipts, correct posted stock, view administrative reports, or edit posted history.

The system does not introduce Manager, Cashier, Warehouse, or Accountant roles in the MVP. They can be added only when a real business rule requires them.

## 5. Goals and non-goals

### Goals

1. Keep ready and pending stock accurate after every accepted operation.
2. Apply FIFO consistently to outgoing sales.
3. Preserve batch-level cost so HPP and gross profit remain historically stable.
4. Provide searchable history and reports for the store operator.
5. Enforce Admin/Karyawan authorization on the server.
6. Build a maintainable Laravel API and React TypeScript client, verified against the running application.

### Non-goals for MVP

- Multi-store or multi-warehouse inventory.
- Full accounting, tax, general ledger reconciliation, or balance sheet reporting.
- Payment gateway, online checkout, or customer credit management.
- WhatsApp, email, S3, or other external integrations.
- Offline-first mobile application.
- AI that changes stock, transactions, roles, or permissions.

## 6. Core user journeys

### 6.1 Login and dashboard

1. User opens the login page.
2. User submits credentials.
3. API authenticates and returns the current role.
4. Admin sees the full dashboard with product count, ready stock, pending stock, recent incoming/outgoing activity, low-stock warnings, and report shortcuts.
5. Karyawan sees an operational dashboard limited to incoming work and pending-product actions.

### 6.2 Product and master data setup

Admin creates categories, units when needed, products, suppliers, and optional customers. A product has a selling price, reference purchase price, unit, image path, active status, ready stock projection, and pending stock projection.

### 6.3 Record and approve incoming goods

1. Karyawan or Admin creates an incoming receipt with supplier, date, notes, and items.
2. Each item records product, initial weight, final weight when checked, unit cost, and subtotal.
3. The receipt remains pending until an authorized Admin approves it.
4. Approval creates or updates the correct period batch, moves the approved quantity into ready stock, records a receipt movement, and creates the related ledger entry.
5. Rejecting or cancelling a receipt leaves an explanatory audit record and does not increase ready stock.

### 6.4 Record and post a sale

1. Authorized user creates a sale with optional customer and one or more items.
2. The server recalculates quantities, prices, subtotal, and total.
3. On posting, the server checks available ready stock inside one database transaction.
4. FIFO allocation consumes the oldest non-empty batches first.
5. The server records sale lines, batch allocations, HPP, gross profit, stock movements, and the ledger entry.
6. If stock is insufficient, the whole operation is rejected and no partial stock change remains.

### 6.5 Correct stock

1. Admin opens the stock correction form and selects a product.
2. The form shows system quantity and physical quantity.
3. Admin enters the difference and a reason.
4. The server recalculates the difference, records an adjustment and movement, updates the projection, and keeps the before/after values.

### 6.6 Reports and export

Admin filters incoming, outgoing, stock, movement, HPP, and profit reports by date, product, category, or status. Karyawan does not access administrative reports in the MVP. Export is generated only after the on-screen report is correct; large exports may become a queued phase-two feature.

## 7. User stories and acceptance criteria

| ID | User story | Acceptance criteria |
|---|---|---|
| US-01 | As a user, I can log in according to my role. | Invalid credentials are rejected; role is returned; unauthorized screens and API actions are blocked. |
| US-02 | As an Admin, I can manage categories and products. | Required fields are validated; referenced products are deactivated instead of deleted. |
| US-03 | As a user, I can record incoming goods. | Receipt and detail items are validated and remain pending until approval. |
| US-04 | As an Admin, I can approve incoming goods once. | Approval is idempotent; ready stock, batch, movement, and ledger are updated exactly once. |
| US-05 | As an authorized user, I can post a sale using FIFO. | Oldest eligible batches are allocated; insufficient stock rolls back the transaction. |
| US-06 | As an Admin, I can correct stock with a reason. | Before/after quantities and actor are recorded; direct silent edits are impossible. |
| US-07 | As a user, I can inspect movement and profit reports. | Filters are applied server-side and totals use stored sale HPP, not current product cost. |

## 8. Functional requirements

### Identity and access

- Login, logout, current profile, and password change.
- Two MVP roles: Admin and Karyawan.
- Server-side policies for every mutating endpoint.
- Audit actor and request id for sensitive actions.

### Catalog and parties

- Category CRUD for Admin.
- Product CRUD/deactivation for Admin.
- Supplier CRUD for Admin and permitted staff.
- Optional customer records for sales history.
- Product image upload only after file validation and storage policy are defined.

### Incoming goods

- Draft/pending/approved/cancelled lifecycle.
- Initial and final weight capture.
- Admin approval and duplicate-approval guard.
- Period batch creation or update.
- Ready/pending stock and movement updates in one transaction.

### Inventory

- Product stock overview with ready, pending, and minimum-stock status.
- Immutable movement history for receipt, sale, adjustment in, and adjustment out.
- Admin-only stock correction with reason.
- FIFO batch balance and cost traceability.

### Sales and reports

- Sale draft and posting.
- Optional customer reference.
- Server-side totals, FIFO allocation, HPP, and gross profit.
- Incoming, outgoing, stock, movement, HPP, profit, and simple ledger reports.
- CSV/XLSX/PDF export only after the corresponding screen and query pass acceptance tests.

## 9. Pages and screens

- Login.
- Dashboard.
- Product list, create, edit, deactivate.
- Category list and form.
- Supplier list and form.
- Optional customer list and history.
- Incoming goods list, create, detail, pending verification, approval, and history.
- Pending products.
- Outgoing sales list, create, detail, invoice view, and history.
- Stock overview.
- Stock correction.
- Stock movement history.
- Incoming, outgoing, stock, profit, and simple ledger reports.
- User management and profile.

## 10. Non-functional requirements

- Laravel API and React client use UTF-8 and Asia/Jakarta display timezone while persisting timestamps in UTC where practical.
- All stock-changing commands run in a database transaction.
- Money uses `DECIMAL(15,2)` and quantities use `DECIMAL(15,3)`; clients do not calculate authoritative totals.
- Server-side authorization is mandatory; hiding a button is not authorization.
- List screens paginate and avoid N+1 queries.
- Errors expose a stable code and request id but never secrets or stack traces in production.
- The project can be installed from a clean database using documented migrations and seeders.
- CI runs formatting, the existing test suite, and frontend build before merge.

## 11. Loading, empty, error, and confirmation states

- Loading: skeleton or disabled submit state with no duplicate submission.
- Empty: explain what is empty and provide the next permitted action.
- Validation error: field-level messages beside the input.
- Conflict: explain insufficient stock, already approved, or invalid status transition.
- Network error: retain safe form input and offer retry.
- Destructive action: require explicit confirmation and reason where applicable.
- Success: show document number and the resulting status; do not rely on a disappearing toast alone.

## 12. Integrations

### MVP

- MySQL 8+ or compatible MariaDB for local development.
- Laravel Sanctum for React authentication.
- Local public storage for product images and generated files.

### Future

- Flutter client for barcode and warehouse workflows.
- S3-compatible object storage.
- Email or WhatsApp notifications.
- Queue-backed exports.

## 13. Staged delivery

### Phase 0 - rules and design

Confirm roles, pending-to-ready approval, batch period rule, FIFO, HPP, correction policy, and report definitions. Produce fixtures and examples before coding.

### Phase 1 - foundation

Initialize Laravel API, React TypeScript app, Sanctum, database migrations, role seeders, error envelope, and CI checks. Verify login and health endpoint.

### Phase 2 - inventory core

Implement categories, products, suppliers, incoming receipts, approval, batches, stock projections, movements, and correction.

### Phase 3 - sales and reporting

Implement sales, FIFO allocations, HPP, gross profit, simple ledger, dashboards, and reports.

### Phase 4 - hardening and release

Add live end-to-end verification of every feature and role, concurrency/rollback checks, exports, security review, backup notes, and deployment.

### Phase 5 - optional mobile

Design Flutter only for workflows that benefit from camera scanning or warehouse mobility.

## 14. Success criteria

- A clean install can migrate and seed a demo database.
- Admin and Karyawan can log in and see only permitted actions.
- Approved incoming goods update pending/ready stock and batch exactly once.
- A sale allocates stock FIFO and stores immutable HPP and profit.
- Insufficient stock and duplicate posting produce no partial side effects.
- Corrections and movements are searchable with actor and reason.
- Reports reproduce the TA examples for incoming, outgoing, stock, HPP, and gross profit.
- A reviewer can understand the business rules from this PRD without guessing hidden behavior.

## 15. Vibe coding rules

- Treat this document as the product boundary; do not add features because they are common in other systems.
- Explain the concept and acceptance criteria before generating implementation code.
- Work in small vertical slices: migration, request, policy, action, endpoint, UI, then verify the slice against the running application.
- Never change stock rules, role permissions, or API contracts silently.
- When a decision is not in this document, mark it as an assumption and request confirmation before it affects data or workflows.
