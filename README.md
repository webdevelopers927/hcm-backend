# HCM API

JSON API for the HCM product (Laravel 12, MySQL/MariaDB). It is consumed by a separate Next.js frontend
through Bearer tokens (Laravel Sanctum). There is one deployment and one database per client company,
so there's no multi-tenancy.

Current scope: auth (login/logout/me), the **Employees → All employees** module, the full employee form
with drafts, and reference data (countries/states/cities, divisions, degree programs).
API reference with real request/response examples: [docs/api-v1-employees.md](docs/api-v1-employees.md);
the employee form, drafts, uploads and reference data: [docs/api-v1-employee-form.md](docs/api-v1-employee-form.md);
shift records and shift history: [docs/api-v1-employee-shifts.md](docs/api-v1-employee-shifts.md);
employment statuses: [docs/api-v1-employment-statuses.md](docs/api-v1-employment-statuses.md);
off-boarding: [docs/api-v1-offboarding.md](docs/api-v1-offboarding.md).

## Requirements

- PHP **8.2+** with `pdo_mysql`, `mbstring`, `openssl`, `zip`, `fileinfo`
- Composer 2
- MySQL 8 or MariaDB 10.4+ (utf8mb4)

> Laravel 12 is used because the local PHP is 8.2; Laravel 13 needs PHP 8.3+.

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Edit `.env`:

| Key | Purpose |
|---|---|
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | database (create `hcm` and `hcm_testing` with `utf8mb4`) |
| `FRONTEND_URL` | allowed CORS origin(s), comma-separated; also used for links in emails |
| `SEED_ADMIN_EMAIL`, `SEED_ADMIN_PASSWORD` | dev admin account created by the seeder (skipped if empty) |
| `SANCTUM_TOKEN_EXPIRATION` | token lifetime in minutes (default 10080 = 7 days) |
| `MAIL_MAILER=log` | dev: invite emails go to `storage/logs/laravel.log` |

```sql
CREATE DATABASE hcm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE hcm_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

```bash
php artisan migrate --seed   # world geo data (~8 s), lookups, admin user, 64 demo employees
php artisan storage:link     # serves profile pictures from storage/app/public
php artisan serve            # http://localhost:8000
php artisan queue:work       # sends queued invite emails (QUEUE_CONNECTION=database)
php artisan schedule:work    # dev: uploads:prune hourly; shifts:apply-scheduled and offboarding:process-exits daily (cron + schedule:run in production)
```

Get a token:

```bash
curl -X POST http://localhost:8000/api/v1/auth/login \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"email":"<SEED_ADMIN_EMAIL>","password":"<SEED_ADMIN_PASSWORD>"}'
```

### Local note (XAMPP alongside MySQL 8)

On the original dev machine a standalone MySQL 8 service occupies port 3306, so XAMPP's MariaDB runs on
**3307** (`C:\xampp\mysql\bin\my.ini`, backup at `my.ini.bak`) and `.env` uses `DB_PORT=3307`.

## Quality checks

```bash
php artisan test                                   # Pest; uses the hcm_testing database (phpunit.xml)
./vendor/bin/pint --test                           # code style
./vendor/bin/phpstan analyse --memory-limit=1G     # Larastan, level 6
```

## Architecture

| Layer | Location | Notes |
|---|---|---|
| Routes | `routes/api.php` | prefix `api/v1` (set in `bootstrap/app.php`), all behind `auth:sanctum` except login |
| Controllers | `app/Http/Controllers/Api/V1` | thin: authorize, then call an Action, then return a Resource |
| Validation | `app/Http/Requests` | shared employee rules in `Employees/EmployeeRules.php` |
| Business logic | `app/Actions/Employees` | one class per use case (create, update, delete, bulk delete, export, …) |
| Authorization | `app/Policies/EmployeePolicy.php` | the **only** place for permission checks |
| Response shapes | `app/Http/Resources` | list item, profile, sensitive, summary, user |
| Enums | `app/Enums` | `EmployeeStatus`, `WorkMode`, `Gender`, `Permission` |

Key decisions:

- **PII:** `cnic`, `passport_no`, `driving_license_no` and spouse `cnic` use encrypted casts. CNIC
  uniqueness is checked through `cnic_hash` (an HMAC blind index keyed with `APP_KEY`). The full values are
  returned only by `GET /employees/{id}/sensitive` (other responses mask them), and each call is written to
  the activity log. Rotating `APP_KEY` requires re-encrypting these columns and recomputing `cnic_hash`.
- **Drafts** are employee rows with `is_draft = true`: no code, no login, hidden from the list, export and
  manager pickers. Field rules (`EmployeeRules`) only check format, so a draft can be saved at any point;
  `EmployeeCompleteness` is the single definition of "complete" and runs on direct create, draft submit,
  every edit of a real employee, and import rows.
- **Files** are uploaded first (`POST /uploads`) and attached by id in the JSON save; `FileBatch` keeps
  disk and DB in step (copies removed on rollback, replaced files deleted after commit). Documents and
  experience letters are private (`local` disk, audited download endpoints); avatars are on `public`.
- **Phone numbers** are validated per country with libphonenumber and stored in E.164.
- **Geo data** in `database/data/geo` is trimmed from the
  [countries-states-cities-database](https://github.com/dr5hn/countries-states-cities-database)
  (© Darshan Gada, ODbL-1.0). Keep this attribution if you redistribute the data.
- **Salary and bank details** are not stored on employees (interns may have none). They belong to the
  payroll module.
- **Soft deletes** for employees. Deleting also revokes the linked user's tokens.
- **Audit trail:** `spatie/laravel-activitylog`, with manual entries that store ids and IP only, never
  model attributes.
- **Strict models** outside production (`Model::shouldBeStrict`) to catch N+1 queries and missing columns.
  A test asserts the list runs the same number of queries for 10 and 50 rows.

## Not built yet

- Roles and permissions (spatie/laravel-permission) and manager tree scoping. Every authenticated user
  can currently do everything except delete their own record.
- Email OTP / 2FA. The `users` columns already exist (`two_factor_*`).
- Set-password endpoint for invite links, and the attendance, leave and payroll modules.
- CRUD for probation durations (seeded only for now).
