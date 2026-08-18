# Stage 14 — Proposals & Bidding System

## Objective

Implement the freelancer **proposal / bidding workflow** on the DreamMore AppWorks
freelance marketplace:

- Freelancers browse open jobs, submit proposals, track, edit and withdraw them.
- Employers review proposals on their own jobs and shortlist / reject / accept them.
- Accepting a proposal creates a **Contract** (integrating with the Stage 12 contract
  system), rejects the remaining active proposals, and moves the job to `in_progress`.

The `proposals` table, `Proposal` model and `Job::proposals()` relationship already
existed (created in an earlier stage for the hiring flow). Stage 14 adds the API layer,
two small schema changes, and the accept → contract integration.

## Proposal lifecycle

```
pending ──► shortlisted ──► accepted ──► (Contract created)
   │            │
   │            └──► rejected
   └──► rejected
   └──► withdrawn
```

- **pending** — initial status after submission (equivalent to "submitted" in the spec;
  the existing schema uses `pending` as its default).
- **shortlisted** (employer) — only from `pending`.
- **rejected** (employer) — from `pending` or `shortlisted`.
- **withdrawn** (freelancer) — from `pending` or `shortlisted`.
- **accepted** (employer) — from `pending` or `shortlisted`; creates the contract.
- Freelancers may **edit** a proposal while it is `pending` or `shortlisted`.
- Terminal statuses (`accepted`, `rejected`, `withdrawn`) can no longer be edited or
  withdrawn by the freelancer.

## Database changes

New migration: `2026_08_18_000002_add_currency_and_relax_unique_to_proposals_table.php`

| Change | Details |
|--------|---------|
| `currency` added | `string(10)` default `'ETB'` (Ethiopian Birr, consistent with the app) |
| Unique `(job_id, freelancer_id)` constraint dropped | A freelancer whose proposal was **rejected or withdrawn** may submit again; duplicate **active** proposals are prevented at the application layer |

Duplicate-active rule (application layer): a freelancer may not have more than one
proposal in `pending` / `shortlisted` / `accepted` for the same job. `rejected` and
`withdrawn` proposals free the freelancer to resubmit.

No existing migration was modified; the `down()` method restores the constraint and
removes `currency`.

## Proposal model (`app/Models/Proposal.php`)

- Added `currency` to `$fillable`.
- Added model attribute defaults `'currency' => 'ETB'` and `'status' => 'pending'`
  (the same pattern used for milestone status / job currency in Stages 12–13) so new
  proposals carry both values in memory and in API responses.
- Added `scopeActive()` — filters to `pending` / `shortlisted` (used when accepting a
  proposal rejects the other active ones).
- Existing relationships reused: `job()`, `freelancer()` (User), `contract()` (HasOne).

## API endpoints

All Stage 14 routes require `auth:sanctum` and live under `api/v1`.

### Freelancer

| Method | Endpoint | Access | Purpose |
|--------|----------|--------|---------|
| GET | `/api/v1/proposals` | freelancer/admin | list own proposals (paginated) |
| GET | `/api/v1/proposals/{proposal}` | owner/admin | view own proposal |
| PUT | `/api/v1/proposals/{proposal}` | owner/admin | edit while pending/shortlisted |
| POST | `/api/v1/proposals/{proposal}/withdraw` | owner/admin | withdraw while pending/shortlisted |
| POST | `/api/v1/jobs/{job}/proposals` | freelancer/admin | submit proposal for an open job |

### Employer

| Method | Endpoint | Access | Purpose |
|--------|----------|--------|---------|
| GET | `/api/v1/jobs/{job}/proposals` | owner/admin | list proposals for own job |
| GET | `/api/v1/jobs/{job}/proposals/{proposal}` | owner/admin | view single proposal |
| POST | `/api/v1/jobs/{job}/proposals/{proposal}/shortlist` | owner/admin | pending → shortlisted |
| POST | `/api/v1/jobs/{job}/proposals/{proposal}/reject` | owner/admin | pending/shortlisted → rejected |
| POST | `/api/v1/jobs/{job}/proposals/{proposal}/accept` | owner/admin | pending/shortlisted → accepted + contract |

Responses follow the existing envelope `{ success, message, data, meta? }`; paginated
lists include pagination info in `meta` (15 per page).

## Validation (`app/Http/Requests/Api/V1/ProposalRequest.php`)

- **Submit (POST):** `cover_letter`, `bid_amount`, `estimated_duration` required.
- **Update (PUT):** same rules but `sometimes`.
- `cover_letter` string max 5000; `bid_amount` numeric (1 – 99,999,999.99);
  `currency` optional 3-letter code (default `ETB`); `estimated_duration` string max 255.
- `status` is **system-managed** — a client sending `status` on update has it ignored
  (verified by test).

`ProposalStatusRequest` is an empty-payload request for the status actions
(shortlist / accept / reject / withdraw), mirroring `MilestoneSubmissionRequest`.

## Authorization & security

- **401** — unauthenticated (every proposal endpoint).
- **403** — wrong role (employers cannot submit, freelancers cannot manage jobs'
  proposals), or non-owner (freelancer accessing another freelancer's proposal,
  employer accessing another employer's job's proposals) — IDOR protection via
  `freelancer_id` / `employer_id`.
- **404** — proposal not found, or a proposal scoped to the wrong job in the URL.
- **422** — submitting to a non-open job, duplicate active proposal, or invalid status
  transitions (e.g. editing/withdrawing accepted/rejected/withdrawn, accepting an
  already-accepted proposal).
- Admins bypass role and ownership checks, matching Stages 1–13.
- `ProposalResource` never exposes passwords, tokens, `remember_token`, or email; the
  freelancer payload is limited to `id`, `name`, `avatar`.

## Contract integration (accept)

Accepting a proposal runs a single DB transaction that:

1. Marks the proposal `accepted`.
2. Rejects the other `pending` / `shortlisted` proposals for the same job.
3. Creates one **Contract**: `job_id`, `proposal_id`, `employer_id`, `freelancer_id`,
   `title` (from the job), `budget_type` (from the job), `agreed_rate` and
   `total_amount` (both = proposal `bid_amount`), status `active`.
4. Moves the job to `in_progress` (it is no longer accepting proposals).

Duplicate contracts are prevented: accepting an already-accepted proposal (or one that
already has a contract) returns `422`.

## Tests

New suite: `tests/Feature/Api/V1/ProposalTest.php` (35 tests, 87 assertions):

- **Creation** — submit, unauthenticated 401, employer 403, invalid job 404, closed /
  in-progress job 422, validation errors, duplicate active proposal 422, resubmission
  after withdrawal allowed.
- **Reading** — freelancer views own, employer lists/view proposals for own job,
  cross-employer 403, cross-freelancer 403, invalid proposal 404, freelancer lists own.
- **Updating** — freelancer updates own, cross-freelancer 403, accepted/rejected/
  withdrawn cannot be edited (422), status cannot be changed directly via update.
- **Withdrawal** — freelancer withdraws own, cross-freelancer 403, accepted cannot be
  withdrawn (422).
- **Employer management** — shortlist, reject, accept, cross-employer 403 (accept +
  reject), re-shortlisting 422.
- **Contract integration** — exactly one contract with correct parties/amount, duplicate
  acceptance no duplicate contract, other proposals rejected, job → `in_progress`,
  no new proposals after acceptance.
- **Security** — response omits password/token/email.

No Stage 1–13 tests were modified or deleted.

## Verification results

- `php artisan test` → **139 passed, 1 failed** (the 1 failure is the pre-existing
  `ExampleTest` 500 on `GET /`, identical before this stage).
- `php artisan route:list` → 10 new proposal routes registered under `api/v1`.
- Migration verified as part of every test run (`RefreshDatabase` on sqlite `:memory:`),
  including the dropped unique index and resubmission behavior.
- `vendor/bin/pint --test` → the only flags on new files are the codebase's long-standing
  style differences (`!x` spacing, `.` concatenation, trait order) that affect ~20
  pre-existing files equally; new files intentionally follow the existing project style.
- `php artisan migrate:status` (dev) → blocked: `database/database.sqlite` does not
  exist (known, documented in Stages 12–13); tests use `:memory:`.

## Files changed

**New**
- `database/migrations/2026_08_18_000002_add_currency_and_relax_unique_to_proposals_table.php`
- `app/Http/Controllers/Api/V1/ProposalController.php`
- `app/Http/Requests/Api/V1/ProposalRequest.php`
- `app/Http/Requests/Api/V1/ProposalStatusRequest.php`
- `app/Http/Resources/Api/V1/ProposalResource.php`
- `tests/Feature/Api/V1/ProposalTest.php`
- `docs/stage-14-proposals.md`

**Modified**
- `app/Models/Proposal.php` (fillable, currency/status defaults, `scopeActive`)
- `routes/api.php` (Stage 14 routes)

## Known limitations

- Resubmission after rejection/withdrawal is allowed and enforced at the application
  layer (the DB unique constraint was removed); a race between two simultaneous submits
  is not guarded by a DB constraint.
- No proposal notifications/email yet.
- No proposal statistics endpoint (per-job counts are exposed via the existing
  `proposals_count` on jobs, incremented on each submission).
- Freelancer account status (`active`/suspended) is not checked before submitting.
