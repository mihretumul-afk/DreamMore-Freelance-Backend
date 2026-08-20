# Dream More AppWorks — Backend

Laravel 13 REST API for the Dream More AppWorks freelance marketplace.

## Architecture

### Tech Stack
- **Framework:** Laravel 13.25
- **Database:** MySQL 8+ (SQLite for testing)
- **Authentication:** Laravel Sanctum (token-based)
- **Real-time:** Laravel Reverb + Broadcasting
- **Queue:** Database driver

### Project Structure
```
app/
├── Http/
│   ├── Controllers/Api/V1/     # API controllers (auth, profiles, jobs, etc.)
│   │   └── Admin/              # Admin-only controllers
│   ├── Middleware/              # EnsureRole middleware
│   └── Requests/               # Form request validation
├── Models/                     # Eloquent models
├── Resources/                  # API resource transformers
├── Services/                   # Business logic (Auth, Recommendation, Notification)
├── Events/                     # Broadcast events (MessageSent, MessageRead)
routes/
├── api.php                     # All API routes (126 total)
tests/
├── Feature/Api/V1/             # Feature tests (332 tests, 983 assertions)
```

## Features

### IMPLEMENTED

| Module | Status | Endpoints |
|--------|--------|-----------|
| Authentication | ✅ Complete | register, login, logout, me |
| Role-based Access | ✅ Complete | freelancer, employer, admin |
| Freelancer Profiles | ✅ Complete | CRUD + public discovery |
| Employer Profiles | ✅ Complete | CRUD + public view |
| Avatar Upload | ✅ Complete | upload, remove |
| Categories | ✅ Complete | public listing |
| Skills | ✅ Complete | public listing with search |
| Jobs | ✅ Complete | CRUD + close/reopen |
| Advanced Search | ✅ Complete | filters, sorting, pagination |
| Freelancer Discovery | ✅ Complete | search, filter, sort |
| Proposals | ✅ Complete | submit, withdraw, shortlist, accept, reject |
| Hiring | ✅ Complete | accept creates contract |
| Contracts | ✅ Complete | pause, resume, complete, cancel |
| Milestones | ✅ Complete | CRUD + submit/approve/revision |
| Messaging | ✅ Complete | conversations, send, read (WebSocket) |
| Notifications | ✅ Complete | list, mark read, unread count |
| Reviews | ✅ Complete | submit, view, aggregation |
| Saved Jobs | ✅ Complete | save/unsave/check |
| Saved Freelancers | ✅ Complete | save/unsave/check |
| Recommendations | ✅ Complete | jobs for freelancers, freelancers for employers |
| Verification | ✅ Complete | admin approve/reject |
| Dream More Certificates | ✅ Complete | LMS webhook integration |
| External Credentials | ✅ Complete | upload, admin review |
| Skill Tests | ✅ Complete | take, exemption check |
| Admin Dashboard | ✅ Complete | stats, user management, job moderation |
| Security | ✅ Complete | Sanctum, ownership checks, input validation |
| Rate Limiting | ✅ Complete | Laravel default throttle (60/min) |

### FUTURE / NOT IMPLEMENTED

| Module | Status | Notes |
|--------|--------|-------|
| Payment/Escrow | ❌ Not implemented | No payment gateway integrated |
| Dispute Resolution | ❌ Not implemented | No dispute workflow |
| Google OAuth | ❌ Not implemented | Frontend button exists but no backend endpoint |
| Email Notifications | ❌ Not implemented | Mail configured as log driver |

## API Endpoints

### Public Endpoints (no auth required)
- `GET /api/v1/health` — Health check
- `GET /api/v1/status` — System info
- `GET /api/v1/categories` — List categories
- `GET /api/v1/skills` — List/search skills
- `GET /api/v1/jobs` — Browse open jobs
- `GET /api/v1/jobs/{id}` — View job details
- `GET /api/v1/freelancers` — Discover freelancers
- `GET /api/v1/freelancers/{id}` — Public freelancer profile
- `GET /api/v1/employers/{id}` — Public employer profile
- `GET /api/v1/freelancers/{userId}/credentials` — Verified credentials
- `GET /api/v1/reviews/{id}` — View review
- `GET /api/v1/users/{userId}/reviews` — User reviews
- `GET /api/v1/skill-tests` — Browse skill tests

### Authenticated Endpoints
- `POST /api/v1/auth/register` — Register
- `POST /api/v1/auth/login` — Login
- `POST /api/v1/auth/logout` — Logout
- `GET /api/v1/auth/me` — Current user
- `POST/DELETE /api/v1/avatar` — Avatar management
- `GET/PUT /api/v1/freelancer/profile` — Freelancer profile
- `GET/PUT /api/v1/employer/profile` — Employer profile
- `GET /api/v1/employer/jobs` — Employer's jobs
- `POST /api/v1/jobs` — Create job
- `PUT /api/v1/jobs/{id}` — Update job
- `DELETE /api/v1/jobs/{id}` — Delete job
- `POST /api/v1/jobs/{id}/close` — Close job
- `POST /api/v1/jobs/{id}/reopen` — Reopen job
- Full proposal management (submit, withdraw, shortlist, accept, reject)
- Full contract management (pause, resume, complete, cancel)
- Full milestone management (CRUD + submit/approve/revision)
- Messaging (conversations, send, read)
- Notifications (list, mark read)
- Reviews (submit)
- Saved jobs/freelancers (save/unsave/check)
- Recommendations (jobs, freelancers)
- Credentials (CRUD + download)
- Skill tests (take, exemption, history)

### Admin Endpoints
- `GET /api/v1/admin/dashboard` — Platform stats
- Full user management (list, view, activate, deactivate, change role, delete)
- Verification management (list, view, approve, reject)
- Credential management (view, download, approve, reject)
- Job moderation (list, moderate, delete)
- Report management (list, view, resolve, dismiss)
- Category management (CRUD)
- Skill management (CRUD)
- Settings management (get/update)

## Authentication

- **Driver:** Laravel Sanctum (token-based)
- **Token type:** Bearer token in Authorization header
- **Roles:** freelancer, employer, admin
- **Middleware:** `auth:sanctum` for authentication, `role:X` for authorization

## Database

### Tables (30 migrations)
- users, freelancer_profiles, employer_profiles
- categories, skills, freelancer_skills, job_skills
- marketplace_jobs, proposals, contracts, milestones
- messages, notifications, reviews
- saved_jobs, saved_freelancers
- credentials, skill_tests, admin_settings, verifications, reports

### Production Configuration
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=dreammore_appworks
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

## WebSocket (Reverb)

Real-time messaging requires:
1. Reverb server running: `php artisan reverb:start`
2. Queue worker: `php artisan queue:work`
3. Broadcasting connection set to `reverb`

### Events Broadcast
- `MessageSent` — new message in conversation
- `MessageRead` — read receipt

## Storage

- **Avatars:** `storage/app/public/` (symlinked to `public/storage`)
- **Credential files:** `storage/app/private/` (not publicly accessible)
- **Download:** Only owner or admin can download credential files

## Environment Variables

See `.env.example` for all required variables. Key ones:

| Variable | Description | Required |
|----------|-------------|----------|
| `APP_KEY` | Laravel encryption key | Yes |
| `DB_*` | Database connection | Yes |
| `SANCTUM_STATEFUL_DOMAINS` | Frontend domains | Yes (SPA) |
| `REVERB_*` | WebSocket config | For messaging |
| `QUEUE_CONNECTION` | Queue driver | For broadcasting |

## Local Development

```bash
# Install dependencies
composer install

# Setup environment
cp .env.example .env
php artisan key:generate

# Run migrations
php artisan migrate

# Seed database (optional)
php artisan db:seed

# Start server
php artisan serve

# Start Reverb (for WebSocket)
php artisan reverb:start

# Start queue worker
php artisan queue:work
```

## Testing

```bash
# Run all tests
php artisan test

# Run specific test suite
php artisan test --filter=AuthTest
php artisan test --filter=JobTest

# Run with coverage
php artisan test --coverage
```

**Current status:** 332 tests passing, 983 assertions

## Deployment

### Backend
1. MySQL database provisioned
2. `composer install --no-dev`
3. `php artisan migrate`
4. `php artisan config:cache`
5. `php artisan route:cache`
6. `php artisan storage:link`
7. Start queue worker: `php artisan queue:work`
8. Start Reverb: `php artisan reverb:start`

### Queue Worker
Required for:
- Broadcasting messages
- Sending notifications
- Processing webhook events

### Scheduler
No cron jobs currently required.

## Known Limitations

1. **No payment processing** — contracts and milestones exist but no money moves
2. **No dispute resolution** — no workflow for contested work
3. **Google OAuth** — frontend button present but backend not implemented
4. **Email notifications** — mail driver set to log (not sending real emails)
5. **Token expiration** — Sanctum tokens never expire (configurable)
