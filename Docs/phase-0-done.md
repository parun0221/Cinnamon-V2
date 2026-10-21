# Phase 0 — Decisions and Foundation: Completion Notes

Status: **complete** (verified 2026-10-21).

## What was built

| Area | Result |
|---|---|
| Decisions | `Docs/decisions.md` records all eight open decisions (batch rule, supplier required, receipt lifecycle, adjustment contract, ID format, ledger semantics, pending→ready, reversal policy). No decision left open or blocked. |
| Backend | Laravel 13.37 + PHP 8.5.11, `laravel/sanctum` 4.3.3, API prefix `/api/v1`, MySQL schema in `cinnamon_v2`. |
| Frontend | Vite 8.3 + React 19 + TypeScript 6, TanStack Query 5, React Router 7, Vitest 5. |

## Decisions taken during execution

The plan and the code disagreed in several places. Each deviation is recorded in the
execution ledger; the ones that change what a user sees are:

1. **`cinnamon_v2`, not `cinnamon`.** The `cinnamon` database holds 19 legacy tables with
   real TA fixtures (`produks`, `produk_masuks`, `buku_besars`, `batch_stoks`). The new
   schema lives in `cinnamon_v2`; `cinnamon` is untouched and reserved as the acceptance
   example for Phase 2/3.
2. **`UNAUTHENTICATED` is 401, not 500.** The plan's status expression fell through to 500
   for unauthenticated requests. Guests now get 401 and policy denials get 403, per
   `Docs/API.md` §7.
3. **Session lives on the `web` guard.** `auth:sanctum` switches the default guard to
   Sanctum's `RequestGuard`, which has no `logout()`. Login and logout target
   `Auth::guard('web')` explicitly.
4. **CSRF mismatch is 419.** Laravel rewrites `TokenMismatchException` to
   `HttpException(419)` before render callbacks run, so the envelope keys off the status.
   The SPA can then tell "session expired" apart from "not logged in".
5. **Timestamps serialise in Asia/Jakarta (`+07:00`).** Storage stays in UTC; the
   conversion happens at the resource boundary (`App\Support\Timestamp`).
6. **Validation messages are in Bahasa Indonesia.** `lang/id/validation.php` plus
   `APP_LOCALE=id`, because validation text is shown directly in the interface.
7. **The frontend API client defaults to a relative base URL.** Dev traffic goes through
   the Vite proxy, keeping the Sanctum session cookie first-party. Deployments on another
   origin set `VITE_API_URL`.

## Verification actually run

Backend (`cd backend && php artisan test`) — **31 passed (138 assertions)**:

- `AuthenticationTest` — login, wrong password, unknown email, inactive user, missing
  fields, Indonesian validation messages, `/me`, logout, profile update.
- `AuthorizationTest` — Karyawan gets 403 on `GET /api/v1/users`; Admin lists users;
  guest gets 401 (not 403).
- `AuthEdgeCaseTest` — CSRF mismatch renders 419; inactive login creates no session row;
  `X-Request-Id` header matches `meta.request_id`; error bodies leak no internals; UUID v7
  resolves by route binding.
- `HealthTest` — health envelope, UUID v7 primary key, 404 envelope, `request_id` present
  on success and error, no stack trace in errors.
- `TimestampTest` — timestamps carry the `+07:00` offset.
- `ErrorEnvelopeTest` — envelope shape on validation/unauthenticated/404, no stack trace in
  the message, `request_id` unique per request.

Frontend (`cd frontend && npm test`) — **19 passed**:

- `number.test.ts` — money and quantity formatting.
- `client.test.ts` — 419 → "sesi berakhir", server error envelope parsing, network failure.
- `LoginPage.test.tsx` — labelled fields, redirect on success, server error shown, network
  error shown, input preserved after error.
- `AuthGuard.test.tsx` — guest redirected to login, authenticated user reaches the route.
- `AuthProvider.test.tsx` — the local session is cleared on logout, both when the server
  call succeeds and when it fails with a stale CSRF token.

Static checks: `npx tsc -b` clean, `npm run lint` clean, `npm run build` produces `dist/`.

Live acceptance against `php artisan serve` (8000) and `npm run dev` (5173), through the
Vite proxy:

| Check | Result |
|---|---|
| `GET /api/v1/health` | 200 `{ data: { status: "ok" }, meta: { request_id } }` |
| `GET /sanctum/csrf-cookie` | 204, sets `XSRF-TOKEN` |
| `POST /api/v1/auth/login` (admin) | 200, `role: admin`, `last_login_at: …+07:00` |
| `POST /api/v1/auth/login` (wrong password) | 401 `UNAUTHENTICATED` |
| `POST /api/v1/auth/login` (missing fields) | 422 `VALIDATION_ERROR`, fields in Indonesian |
| `GET /api/v1/me` after login | 200 with the authenticated user |
| `GET /api/v1/users` as Admin | 200 with pagination meta |
| `GET /api/v1/users` as Karyawan | 403 `FORBIDDEN` |
| `POST /api/v1/auth/logout` then `GET /api/v1/me` | 200 then 401 `UNAUTHENTICATED` |
| CSRF token mismatch | 419 `UNAUTHENTICATED` |
| Secrets scan of `frontend/src` and `frontend/public` | 0 matches |

## How to run locally

```bash
# backend
cd backend
composer install
cp .env.example .env && php artisan key:generate
# create the database once: CREATE DATABASE cinnamon_v2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
php artisan migrate --seed
php artisan serve                       # http://localhost:8000

# frontend
cd frontend
npm install
npm run dev                             # http://localhost:5173
```

Seeded accounts (password `password`): `admin@cinnamon.test` (Admin),
`karyawan@cinnamon.test` (Karyawan).

## Not done in Phase 0

- Frontend has no user-management screen, ledger, inventory, or reporting — those belong to
  Phase 1–3.
- No password change endpoint; `PATCH /api/v1/me` only updates the display name.
- `Docs/setup.md` is planned in Phase 1 Task 14; this file covers the essentials meanwhile.
