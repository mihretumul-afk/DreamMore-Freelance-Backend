# Stage 12 — Contracts & Milestones

## Objective

Implement a complete **Contract & Milestone Management** system for the DreamMore AppWorks
freelance marketplace. Stage 12 begins after a proposal has been accepted and a contract
has been created. It covers:

- Viewing own contracts (employer & freelancer)
- Contract status management (pause / resume / complete / cancel)
- Milestone creation, editing, submission, approval and revision
- Contract completion rules (all milestones must be approved)
- Ethiopian Birr (ETB / ብር) currency handling

Out of scope (not implemented): payment gateway, escrow, real money transfer, disputes,
proposals, hiring. The proposal → contract creation flow belongs to earlier stages and is
left untouched.

## Architecture

```
routes/api.php
  └─ GET|POST /api/v1/contracts[...]          → ContractController
  └─ /api/v1/contracts/{contract}/milestones  → MilestoneController

app/Http/Controllers/Api/V1/
  ├─ ContractController.php     status transitions, completion rules, ownership
  └─ MilestoneController.php    milestone CRUD + lifecycle, role gates

app/Http/Requests/Api/V1/
  ├─ MilestoneRequest.php           create/update validation (ETB amounts)
  └─ MilestoneSubmissionRequest.php submit/revision actions (no payload)

app/Http/Resources/Api/V1/
  ├─ ContractResource.php           contract + parties + server-side totals
  └─ MilestoneResource.php          milestone payload

database/migrations/
  └─ 2026_08_17_000001_add_submission_timestamps_to_milestones_table.php
```

All authorization (role + ownership) is enforced inside the controllers. Form requests
delegate to the controllers to keep a single source of truth.

## Contract lifecycle

| From    | To          | Allowed | Notes                        |
|---------|-------------|---------|------------------------------|
| active  | paused      | employer/admin |                            |
| active  | completed   | employer/admin | requires ALL milestones approved/paid |
| active  | cancelled   | employer/admin |                            |
| paused  | active      | employer/admin | resume                      |
| paused  | cancelled   | employer/admin |                            |
| completed | —         | no      | terminal state              |
| cancelled | —         | no      | terminal state              |

Invalid transitions return `422` with a clear message.

## Milestone lifecycle

```
pending ──► in_progress ──► submitted ──► approved ──► paid (business state only)
                 ▲              │
                 └── revision ──┘
```

- **submit** (freelancer/admin): allowed from `pending` or `in_progress` → `submitted` (sets `submitted_at`).
- **approve** (employer/admin): only from `submitted` → `approved` (sets `approved_at`).
- **revision** (employer/admin): only from `submitted` → `in_progress` (clears `submitted_at`).
- **update** (employer/admin): only while status is `pending` or `in_progress`.
- **delete** (employer/admin): only while status is `pending`.

All milestone actions (create/update/submit/approve/revise/delete) require the parent
contract to be `active`; paused/cancelled/completed contracts reject them with `422`.

## API endpoints

All routes require `auth:sanctum`.

| Method | Endpoint | Role | Purpose |
|--------|----------|------|---------|
| GET | `/api/v1/contracts` | owner/admin | list own contracts |
| GET | `/api/v1/contracts/{contract}` | owner/admin | contract detail + totals |
| POST | `/api/v1/contracts/{contract}/pause` | employer/admin | active → paused |
| POST | `/api/v1/contracts/{contract}/resume` | employer/admin | paused → active |
| POST | `/api/v1/contracts/{contract}/complete` | employer/admin | complete (all milestones approved) |
| POST | `/api/v1/contracts/{contract}/cancel` | employer/admin | active/paused → cancelled |
| GET | `/api/v1/contracts/{contract}/milestones` | owner/admin | list milestones |
| POST | `/api/v1/contracts/{contract}/milestones` | employer/admin | create milestone |
| GET | `/api/v1/contracts/{contract}/milestones/{milestone}` | owner/admin | milestone detail |
| PUT | `/api/v1/contracts/{contract}/milestones/{milestone}` | employer/admin | update pending/in-progress |
| POST | `/api/v1/contracts/{contract}/milestones/{milestone}/submit` | freelancer/admin | submit for review |
| POST | `/api/v1/contracts/{contract}/milestones/{milestone}/approve` | employer/admin | approve submission |
| POST | `/api/v1/contracts/{contract}/milestones/{milestone}/revision` | employer/admin | request revision |
| DELETE | `/api/v1/contracts/{contract}/milestones/{milestone}` | employer/admin | delete pending milestone |

A `ContractRequest` class was intentionally **not** created: contracts are created by the
hiring flow (earlier stage), and Stage 12 exposes no contract-creation endpoint.

## Authorization & security

- **401** — unauthenticated.
- **403** — authenticated but wrong role, or not a party to the contract (IDOR).
- **404** — contract/milestone not found, or milestone scoped to the wrong contract.
- Ownership is always checked through relationships (`employer_id` / `freelancer_id`),
  never trusted from the URL id alone.
- API Resources only — raw models are never returned. Passwords, tokens and other
  sensitive fields are never serialized.

## Database relationships

```
users (1) ──< marketplace_jobs (employer_id)
users (1) ──< proposals (freelancer_id)
marketplace_jobs (1) ──< contracts (job_id)
proposals (1) ──< contracts (proposal_id)
contracts (1) ──< milestones (contract_id)
```

The new migration only **adds** `submitted_at` and `approved_at` (nullable timestamps) to
`milestones`. No existing migration was modified or destroyed.

## Contract totals (computed on the backend)

`ContractResource` computes a `summary` object from the loaded milestones:

- `total_milestone_amount` — sum of all milestone amounts
- `completed_milestone_amount` — sum of `approved`/`paid` milestones
- `remaining_milestone_amount` — difference
- `milestones_count`, `completed_milestones_count`, `pending_milestones_count`
- `progress_percent` — completed / total milestones

## Ethiopian ETB context

- All amounts are in **ETB / ብር** (e.g. `1,500 ETB/hr`, `45,000 ETB fixed`, `10,000 ETB` per milestone).
- The API resources expose `currency: 'ETB'`; the frontend formats amounts as `ETB 45,000.00`.
- Validation rejects zero and negative milestone amounts (`amount >= 1`).
- Example locations used in tests: Addis Ababa, Bahir Dar, Gondar, Hawassa, Mekelle, etc.

## Frontend

```
src/services/contractService.js   axios wrapper for every Stage 12 endpoint
src/pages/contracts/ContractsPage.jsx
src/pages/contracts/ContractDetailsPage.jsx
src/components/contracts/ContractStatusBadge.jsx
src/components/contracts/ContractProgress.jsx
src/components/contracts/ContractSummary.jsx
src/components/contracts/MilestoneList.jsx
src/components/contracts/MilestoneCard.jsx
src/components/contracts/CreateMilestoneModal.jsx
src/components/contracts/SubmitMilestoneModal.jsx
src/components/contracts/RevisionModal.jsx
src/components/contracts/ConfirmDialog.jsx
src/utils/format.js               formatETB / formatDate helpers
```

Routes added: `/contracts` and `/contracts/:id`. The existing navbar receives a
"My Contracts" link. Authentication reuses the existing axios interceptor (token stored in
`localStorage['appworks_token']`). Design matches the existing dark theme; Stage 12 primary
actions (create milestone, submit, approve, complete contract) use the brand accent
`#FF6B35` (sunset orange).

## Testing

New backend suites:

- `tests/Feature/Api/V1/ContractTest.php` — ownership, listing, transitions, completion
  rules, double completion, sensitive data, backend totals.
- `tests/Feature/Api/V1/MilestoneTest.php` — CRUD, lifecycle transitions, validation,
  ETB amounts, IDOR scoping, paused/cancelled contract guards, full end-to-end workflow.
- Shared fixture helpers in `tests/Feature/Api/V1/Concerns/ContractTestHelpers.php`.

Run with `php artisan test` (or `composer test`).

## Verification results

- Backend: **not executable in this environment** — no PHP binary and `vendor/` is not
  installed (`composer install` requires PHP). All code was written against the actual
  repository conventions; static review only. Install PHP + run `composer install`,
  `php artisan migrate`, `php artisan test` before shipping.
- Frontend: `npm install` + `npm run build` executed successfully (see final report).
