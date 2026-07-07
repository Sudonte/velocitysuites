# Velocity Suites — Hotel Booking & Reservation System

Laravel 12 app with four roles: Guest, Receptionist, Manager, Admin. Deployed via Git → GitHub → Hostinger (shared hosting, MySQL, no Redis/Supervisor).

## How to work on this project

- **Understand before changing.** Before touching a feature, check which other roles/modules read the same data or routes. A change in one role's workflow (e.g. Receptionist check-out) often needs to stay in sync with what Guest/Manager/Admin see (dashboards, reports, notifications).
- **Don't assume existing logic is correct.** This codebase has shipped with real bugs that were never exercised end-to-end (e.g. a DB enum column that didn't match the values the app wrote, a missing model relationship that crashed a page, a sign error in a date-diff calculation). When you touch code near a suspicious pattern, verify it actually runs correctly — don't just trust that it must have worked before.
- **Reuse before creating.** Before adding a new route, controller, or page, check whether an equivalent already exists (e.g. room browsing/booking has one canonical flow via the public `/rooms` routes — don't build a second one under `/guest`).
- **Verify by running the app**, not just by reading the diff — see the `verify` skill. For UI changes this means driving the actual page/route; for backend changes it means hitting the real endpoint and checking DB state, not just unit-level reasoning.
- **Keep sidebar links and route names in sync.** Sidebar hrefs have drifted from actual route names before (hardcoded `<a href="/guest/bookings">` while the real route was `guest.reservations.index`). Prefer `route()` helpers over hardcoded paths in Blade so this can't silently drift again.

## Deployment constraints (see DEPLOYMENT.md for the full guide)

- Target is Hostinger shared hosting: `QUEUE_CONNECTION=database`, no Redis, no Supervisor, no long-running workers assumed.
- No hardcoded local paths or `localhost`/`127.0.0.1` in application code — use `env()`, `config()`, `route()`, `url()`, `asset()`, `storage_path()`.
- All schema changes go through migrations — a fresh clone must become fully working via `composer install && php artisan migrate && php artisan db:seed`, nothing manual.
- File uploads go through `Storage::disk('public')` + `php artisan storage:link`, never a hand-rolled folder under `public/`.
- **When you add a package, env var, cron job, queue, or storage dependency, update DEPLOYMENT.md in the same change.** Don't let it drift out of date.
- `.env` must never be committed (already gitignored — verify before any `git add -A`).

## Review checklist before calling a feature done

- Existing functionality for other roles still works.
- Role-based authorization (route `role:` middleware) is correct for the new/changed routes.
- No duplicate logic — check for an existing controller/service method first.
- Validation exists on every new form/endpoint.
- The change was actually run (not just read) — see `verify` skill.
