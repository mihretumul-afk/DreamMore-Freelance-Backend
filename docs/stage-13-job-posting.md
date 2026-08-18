# Stage 13 — Job Posting & Management

## Objective

Implement a complete **Job Posting & Job Management** system for employers on the
DreamMore AppWorks freelance marketplace. Employers can create, browse, view, update,
close, reopen and delete their own jobs. Freelancers (and visitors) can browse published
(open) jobs and view job details, but can never modify employer-owned jobs.

The `marketplace_jobs` table and `Job` model already existed (built in earlier stages for
the proposal → contract flow). Stage 13 adds the API layer on top and extends the table
with the fields required for a full job-posting lifecycle.

## Database changes

New migration: `2026_08_18_000001_add_job_posting_fields_to_marketplace_jobs_table.php`

| Change | Details |
|--------|---------|
| `status` enum extended | `['draft', 'open', 'in_progress', 'completed', 'cancelled']` → adds `'closed'` (default stays `'open'`) |
| `currency` added | `string(10)` default `'ETB'` (Ethiopian Birr, consistent with the rest of the app) |
| `published_at` added | nullable timestamp — set when a job is first published and refreshed on reopen |

No existing migration was modified. The `down()` method removes the new columns and
restores the original enum.

## Job model (`app/Models/Job.php`)

- Added `currency` and `published_at` to `$fillable` / `$casts`.
- Added a model attribute default `'currency' => 'ETB'` so new jobs carry the currency
  in memory (and in the API response) even when the client omits it — the same pattern
  used for milestone `status` in Stage 12.
- Added `scopeOpen()` — filters to `status = 'open'` (published) jobs.
- Existing relationships reused: `employer()`, `category()`, `skills()`, `proposals()`,
  `contract()`, `savedByUsers()`.

## API endpoints

All job routes live under the existing `api/v1` prefix.

| Method | Endpoint | Access | Purpose |
|--------|----------|--------|---------|
| GET | `/api/v1/jobs` | public | browse **open** jobs, filtered + paginated |
| GET | `/api/v1/jobs/{job}` | public for open jobs; owner/admin for other statuses | job detail |
| GET | `/api/v1/employer/jobs` | employer/admin | own jobs, all statuses |
| POST | `/api/v1/jobs` | employer/admin | create job (published as `open`) |
| PUT | `/api/v1/jobs/{job}` | owner/admin | update draft/open/closed job |
| DELETE | `/api/v1/jobs/{job}` | owner/admin | delete draft/open/closed job without proposals |
| POST | `/api/v1/jobs/{job}/close` | owner/admin | `open` → `closed` |
| POST | `/api/v1/jobs/{job}/reopen` | owner/admin | `closed` → `open` (re-publishes) |

Responses follow the existing envelope: `{ success, message, data, meta? }`.
Browse and "my jobs" endpoints are paginated (15/page) with pagination info in `meta`.

## Validation (`app/Http/Requests/Api/V1/JobRequest.php`)

- **Create (POST):** `title`, `description`, `budget_type` required; everything else optional.
- **Update (PUT):** same rules but all `sometimes`.
- `budget_type` in `fixed,hourly`; `experience_level` in `entry,intermediate,expert`;
  `location_type` in `remote,onsite,hybrid`.
- `min_budget` / `max_budget` numeric (0 – 99,999,999.99); after-validation check rejects
  `max_budget < min_budget`.
- `currency` optional 3-letter code (defaults to `ETB`).
- `category_id` must exist in `categories`; `skills` is an array of existing skill ids.
- `deadline` optional date.
- `status` is **system-managed** — clients cannot set it directly (same pattern as
  milestone status).

## Authorization & security

- **401** — unauthenticated (all mutating endpoints).
- **403** — authenticated but wrong role (freelancer cannot manage jobs), or non-owner
  attempting update/close/reopen/delete (IDOR protection via `employer_id`).
- **404** — job not found, or a non-open job requested by someone who is neither the
  owner nor an admin (does not leak the job's existence).
- **422** — invalid status transitions, editing/deleting a job that is
  `in_progress`/`completed`/`cancelled`, or deleting a job that has proposals.
- Admins bypass role and ownership checks, matching Stages 1–12 behavior.
- `JobResource` never exposes passwords, tokens, or `remember_token`; the employer
  payload is limited to `id`, `name`, `avatar` (no email on public listings).

## Job lifecycle

```
create ──► open ──close──► closed
             ▲                │
             └─────reopen─────┘
```

- **create**: new jobs are published immediately (`open`, `published_at = now()`).
- **close**: `open` → `closed` (hidden from public browse; owner/admin can still view/edit).
- **reopen**: `closed` → `open` (visible again, `published_at` refreshed).
- **update / delete**: allowed while `draft`, `open` or `closed`; `delete` additionally
  requires the job to have **no proposals** (prevents cascade-deleting live proposals/contracts).
- `in_progress`, `completed`, `cancelled` are reserved for the hiring/contract flow and
  are terminal for Stage 13 purposes — no transitions into or out of them from this stage.

## Filtering (`GET /api/v1/jobs`)

| Param | Behavior |
|-------|----------|
| `search` | `LIKE` match on title or description |
| `category_id` | exact match |
| `budget_type` | exact match (`fixed`/`hourly`) |
| `experience_level` | exact match |
| `location_type` | exact match |
| `location` | `LIKE` match |
| `min_budget` | jobs whose `max_budget >= value` |
| `max_budget` | jobs whose `min_budget <= value` |

Only `open` jobs are ever returned by the browse endpoint. Results are ordered newest
first and paginated (15/page). This is a clean foundation for the advanced search stage.

## Tests

New suite: `tests/Feature/Api/V1/JobTest.php` (32 tests, 88 assertions):

- **Creation** — employer can create (with/without category & skills), unauthenticated 401,
  freelancer 403, validation 422.
- **Reading** — public browse returns only open jobs, public show, closed jobs hidden
  (404 for non-owner), owner can view own closed job, employer job list, freelancer 403.
- **Updating** — owner update, cross-employer 403, freelancer 403, completed job 422.
- **Close/Reopen** — close, cross-employer 403, freelancer 403, reopen, invalid
  transitions 422 (reopen open, close closed, reopen completed, close draft), unauthenticated 401.
- **Deletion** — owner deletes proposal-free job, job with proposals 422, cross-employer 403,
  freelancer 403.
- **Filtering** — search, category, budget, pagination meta.
- **Security** — response omits password/token/email.

Reuses `ContractTestHelpers` (`createContractUser`, `actingAsSanctum`). No Stage 1–12
tests were modified or deleted.

## Verification results

- `php artisan test` → **104 passed, 1 failed** (the 1 failure is the pre-existing
  `ExampleTest` 500 on `GET /`, which fails identically before this stage).
- `php artisan route:list` → 8 new job routes registered under `api/v1`.
- Migration `up()` / `down()` / `up()` verified against a fresh SQLite database.
- `vendor/bin/pint --test` → fails on ~20 pre-existing files (the codebase has never
  been pint-clean; no `pint.json` exists). New files intentionally follow the existing
  project style (e.g. `!in_array`, spaces around concatenation, trait order) rather
  than Pint's Laravel preset.
- `php artisan migrate:status` (dev) → blocked: `database/database.sqlite` does not
  exist. Tests use `sqlite :memory:` (phpunit.xml) and are unaffected; `RefreshDatabase`
  runs every migration including the new one on every test run.

## Files changed

**New**
- `database/migrations/2026_08_18_000001_add_job_posting_fields_to_marketplace_jobs_table.php`
- `app/Http/Controllers/Api/V1/JobController.php`
- `app/Http/Requests/Api/V1/JobRequest.php`
- `app/Http/Resources/Api/V1/JobResource.php`
- `tests/Feature/Api/V1/JobTest.php`
- `docs/stage-13-job-posting.md`

**Modified**
- `app/Models/Job.php` (fillable, casts, currency default, `scopeOpen`)
- `routes/api.php` (Stage 13 routes)

## Known limitations

- No `draft` publishing flow: jobs are created directly as `open`. `draft` remains a
  reserved enum value for a future "save as draft → publish" step.
- Job stats are limited to `proposals_count` (existing column); no analytics endpoint yet.
- No skill suggestions / category auto-tagging; skills must reference existing rows.
- Advanced search (faceted, full-text) is intentionally left for a later stage.
