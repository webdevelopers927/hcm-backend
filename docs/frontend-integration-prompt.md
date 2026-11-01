# Task: Connect the Next.js HCM frontend to the new Laravel API (Employees → All employees)

## Context
- You are working on the **Next.js frontend** of an HCM (human capital management) product. It runs on
  `http://localhost:3000`.
- The backend is a separate **Laravel 12 JSON API** at `http://localhost:8000/api/v1` (repo `C:\HCM\hcm-api`).
  It uses **Bearer tokens** (Laravel Sanctum). There are no cookies or CSRF, and the API never sees the browser session.
- Scope: **login/logout/me** plus the **Employees → All employees** module: list, filters, profile, reveal
  sensitive data, create wizard, edit, delete, bulk delete, row actions, export, bulk import (CSV/XLSX).
- First inspect the existing frontend (router type, data fetching, UI kit, state, forms, any mock data).
  **Match its conventions.** If screens already exist on mock data, replace the mocks with the API
  instead of rebuilding the UI. If something below conflicts with how the app is built, stop and ask.

## Configuration
- Put the API base URL in env: `NEXT_PUBLIC_API_URL=http://localhost:8000/api/v1` (or a server-only
  `API_URL` if all calls go through the Next server). Never hard-code it.
- The API's CORS allows only the origin in its `FRONTEND_URL` (default `http://localhost:3000`) and exposes
  `Content-Disposition`.
- Send `Accept: application/json` and `Authorization: Bearer <token>` on every request.

## Auth
- `POST /auth/login` `{ email, password, device_name? }` →
  ```json
  { "token": "1|abc...", "token_type": "Bearer",
    "user": { "id": 1, "name": "Administrator", "email": "admin@hcm.test", "employee_id": null,
              "two_factor_enabled": false, "roles": [], "permissions": ["employees.view", "..."] } }
  ```
  Wrong credentials → **422** `{ message, errors: { email: ["These credentials do not match our records."] } }`.
  Rate-limited to 5/min per email+IP → **429** `{ message }` with a `Retry-After` header. Show a friendly
  "try again in N seconds".
- `GET /auth/me` → `{ data: <same user object> }`. Use it to bootstrap the session.
- `POST /auth/logout` → `{ message }`. Revokes the current token only.
- Tokens expire after **7 days**. Any **401** (`{ "message": "Unauthenticated." }`) → clear the token and
  redirect to login, keeping a `?next=` return path.
- **Token storage:** prefer an httpOnly cookie set by a Next route handler or server action that proxies
  login, with API calls made server-side or through a thin proxy. If the app is purely client-side, keep
  the token in memory plus sessionStorage, never in a non-httpOnly long-lived cookie. Pick whatever fits
  the existing app and explain the choice.
- **Permissions:** `roles` is `[]` and `permissions` currently lists **all** of these:
  `employees.view, employees.create, employees.import, employees.update, employees.delete, employees.export,
  employees.view-sensitive, employees.reset-2fa, employees.unbind-device`.
  Gate UI on `permissions` anyway (a small `can('employees.export')` helper), because real roles come
  later. Per-row actions use the row's own `can` object (see below), not the global list.
- 2FA/OTP is **not built yet**. Don't add OTP screens. `two_factor_enabled` is display-only.

## Error contract (all JSON)
| Status | Body | UI |
|---|---|---|
| 401 | `{ message: "Unauthenticated." }` | logout and redirect to login |
| 403 | `{ message }` (e.g. "You cannot delete your own employee record.") | toast the message |
| 404 | `{ message: "Not found." }` | not-found state |
| 422 | `{ message, errors: { field: ["msg", ...] } }` | map `errors` onto form fields; keys can be nested like `status.0` |
| 429 | `{ message }` + `Retry-After` | throttle message |
| 500 | `{ message }` | generic error toast |

Build one typed `apiFetch` wrapper that handles these centrally.

## TypeScript types (exact API shapes)
```ts
type IdName = { id: number; name: string };
type EmployeeStatus = 'active' | 'probation' | 'on_leave' | 'notice_period' | 'inactive';
type WorkMode = 'onsite' | 'hybrid' | 'remote';

interface EmployeeListItem {
  id: number;
  code: string;                 // "EMP-0001"
  machine_uid: string | null;   // attendance machine id, e.g. "2400"
  name: string;
  pseudo_name: string | null;
  email: string;
  avatar_url: string | null;    // null → render initials
  designation: IdName | null;
  department: IdName | null;
  status: EmployeeStatus;
  work_mode: WorkMode;
  shift: { id: number; name: string; start: string; end: string } | null; // "09:00"
  campaign: IdName | null;
  location: IdName | null;
  join_date: string;            // "YYYY-MM-DD"
  two_factor_enabled: boolean;
  device_bound: boolean;
  can: { update: boolean; delete: boolean; reset_2fa: boolean; unbind_device: boolean };
}

interface EmployeeSummary { id: number; name: string; designation: IdName | null; avatar_url: string | null }

interface EmployeeProfile extends EmployeeListItem {
  phone: string | null;
  gender: 'male' | 'female' | 'other' | null;
  birthday: string | null;      // "MM-DD" only, never the year
  reports_to: EmployeeSummary | null;
  direct_reports: EmployeeSummary[];
  employment: { join_date: string; status: EmployeeStatus };
  attendance_rate: null;        // module not built yet: show "—" / "Coming soon"
  leave_balances: null;         // module not built yet
}

interface EmployeeSensitive {
  employee_id: number;
  cnic: string | null;          // "35202-1234567-1"
}

interface EmployeeListResponse {
  data: EmployeeListItem[];
  meta: { current_page: number; last_page: number; per_page: number; total: number };
  status_counts: Record<'all' | EmployeeStatus, number>;
}
```

## Endpoints (all need the Bearer token)

### `GET /employees` (list page)
Query params, all optional:
- `search` (matches name, email, code, machine_uid, designation name, pseudo_name)
- arrays: `status[]`, `department_id[]`, `designation_id[]`, `division_id[]`, `campaign_id[]`, `shift_id[]`,
  `location_id[]`, `work_mode[]`. Send them as repeated `key[]=v` params (comma-separated `status=active,probation`
  also works).
- `sort`: `name` (default) | `-name` | `join_date` | `-join_date` | `department` | `-department` | `code` | `-code`
- `page`, `per_page` (default 12; values above 100 are clamped to 100)

UI requirements:
- **Status tabs** (All / Active / Probation / On leave / Notice period / Inactive) with counts from
  `status_counts`. Those counts already honour every other filter except status, so just display them.
  Clicking a tab sets `status[]`.
- Debounced search (~300 ms). Filter dropdowns fed by `/employees/filters`. Sortable columns. Pagination
  driven by `meta`.
- **Keep all filters, sort and page in the URL query string** so views are shareable and survive refresh.
  Reset `page` to 1 when filters change.
- Columns or cards: avatar/initials, name + pseudo_name, code, designation, department, status badge,
  work mode, shift (`Morning 09:00–18:00`), location, join date, 2FA indicator, device indicator.
- Row actions, each shown or enabled only when the matching `row.can.*` is true: View, Edit, Delete,
  Reset 2FA, Unbind device.
- Checkbox multi-select across the current page for bulk delete and export of the selected rows.
- The list never contains CNIC or phone. Don't try to show them there.

### `GET /employees/filters`
`{ data: { departments: IdName[], designations: (IdName & { department_id: number|null })[], divisions: IdName[],
campaigns: IdName[], shifts: {id,name,start,end}[], locations: IdName[], statuses: {value,label}[],
work_modes: {value,label}[] } }`. Fetch once and cache it, e.g. with a long staleTime. Use `statuses` and
`work_modes` labels for display. In forms, narrow designations by the chosen department.

### `GET /employees/{id}` (profile page)
`{ data: EmployeeProfile }`. Show the reporting line (manager card plus direct-reports list, each linking to
its profile), contact info, employment, and placeholders for attendance and leave.

### `GET /employees/{id}/sensitive` (reveal PII)
`{ data: EmployeeSensitive }`. Every call is **audit-logged** on the server.
- Show the CNIC **masked** by default (e.g. `35202-•••••••-1`).
- Call this endpoint **only when the user clicks "Reveal"**: never on page load, never prefetched, never
  in a list.
- Don't cache it (no persistent query cache, `cache: 'no-store'`). Re-mask on navigation away or after
  ~60 seconds.

> **Superseded (2026-11-01):** the create/edit form is now the multi-section form with drafts described in
> [api-v1-employee-form.md](api-v1-employee-form.md). The section below is kept for history only.

### `POST /employees` (create wizard, 3 steps)
Suggested steps: 1) Personal 2) Job 3) Review. Validate each step client-side, but
the server's 422 is the source of truth: map `errors` back to the right step and field, then jump to the
first step that has an error.
- Required: `first_name`, `last_name`, `email`, `designation_id`, `department_id`, `join_date` (`YYYY-MM-DD`).
- Optional: `pseudo_name`, `phone`, `cnic` (format `^\d{5}-\d{7}-\d$`, so use an input mask), `gender`
  (`male|female|other`), `birth_date`, `machine_uid`, `division_id`, `campaign_id`, `reports_to_id`
  (pick from the employee list via async search), `work_mode`, `status` (defaults to `active`),
  `shift_id`, `location_id`.
- **No salary or bank fields.** They belong to the payroll module (not built yet). Don't add a
  compensation step.
- Response **201** `{ data: EmployeeProfile }` → go to the new profile and toast "Invite sent". The server
  creates a login account and emails an invite link to `${FRONTEND_URL}/set-password?token=...&email=...`.

### `PATCH /employees/{id}` (edit)
The same fields, all optional. Send **only changed fields**.
- Changing the email also changes the employee's login email. Show a confirm.
- Returns `{ data: EmployeeProfile }`.

### `DELETE /employees/{id}`
`{ message }`. Soft delete; the employee's sessions are revoked. Use a confirm dialog. Users can't delete
their own record: `row.can.delete` is `false` for it, and the API returns 403 if tried.

### `POST /employees/bulk-delete` `{ ids: number[] }` (max 500)
→ `{ deleted: number[], failed: { id: number, reason: 'not_found' | 'cannot_delete_self' | 'forbidden' }[] }`.
It always returns 200, even on partial failure. Show "Deleted N employees", list the failures with
human-readable reasons, refetch the list and clear the selection.

### Row actions
- `POST /employees/{id}/two-factor/reset` → `{ message, two_factor_enabled: false }`. 422
  `errors.employee` if the employee has no login account. Confirm first.
- `GET /employees/{id}/device` → `{ data: { device_id, model, platform: 'android'|'ios', bound_at } | null }`.
  `bound_at` is an ISO 8601 timestamp.
- `POST /employees/{id}/device/unbind` → `{ message, device_bound: false }`. 422 `errors.device` if nothing is
  bound. Confirm first, then optimistically set `device_bound=false` on the row.

### `POST /employees/export`
JSON body: the **current list filters + sort** (same keys as the list, as arrays), optional `ids: number[]`
(export only the selected rows) and `format: 'csv' | 'xlsx'`. The response is a **file stream**, not JSON:
fetch it as a blob and read the filename from `Content-Disposition`
(`attachment; filename=employees-2026-11-01-153000.csv`). Trigger the download with an object URL. Show a
loading state, because large exports take a few seconds. Columns: code, name, email, designation,
department, status, work mode, shift, location, join date (no PII).

### Bulk import (`GET /employees/import/template`, `POST /employees/import`)
Gate on `permissions` containing `employees.import`. Flow on the list page, via an "Import" button:
1. Offer "Download template": fetch `/employees/import/template` as a blob, same as export.
2. The user picks a `.csv` or `.xlsx` file (≤ 5 MB, ≤ 500 rows). Send `multipart/form-data` with `file` and
   `dry_run=1`. Don't set `Content-Type` yourself; let the browser add the boundary.
3. **200** → show "All N rows are valid" and an "Import N employees" button, which resends the same file with
   `dry_run=0`. **201** → `{ imported, employee_ids }`: toast it, close the dialog and refetch the list.
4. **422** → `errors` keys are `rows.<line>.<column>` (spreadsheet line; the header is line 1) or `file`.
   Show a table grouped by line ("Line 3: department: Department "Enginering" does not exist."), plus the
   summary `message`. Nothing was imported, so the user fixes the file and uploads again.
Lookups in the file are **names** (department, designation, shift, location…), not ids. `reports_to` is an
employee code or email.

## Out of scope / stubs (don't build backends for these)
- Attendance rate and leave balances are `null`. Show placeholders.
- OTP / 2FA setup screens.
- Roles and permission management UI.
- The `/set-password` page is linked from invite emails, but **the API endpoint to complete it doesn't exist
  yet**. Build the page UI only if it's cheap, and mark it TODO.
- Avatar upload (there's no upload endpoint yet; `avatar_url` is read-only).
- Salary and bank details (they arrive with the payroll module).

## Quality bar
- Strict TypeScript, no `any` on API data. Put API calls in one module (e.g. `lib/api/employees.ts`).
- Loading skeletons, empty states (including "no results for these filters" with a clear-filters button)
  and error states on every screen.
- Accessible: labelled inputs, keyboard-usable dialogs and menus, status not shown by colour alone.
- Never log CNIC or tokens to the console or to analytics.
- Run the project's lint, typecheck and tests before finishing, and report their real output.
- Finish with a short note: what you wired up, what is mocked or TODO, and any mismatch you found
  between this contract and the real API responses.

## Note on the contract
The shapes above have been verified against the running backend (135 passing tests). Real request/response
captures for every endpoint are in `C:\HCM\hcm-api\docs\api-v1-employees.md`, which is the source of truth.
If you find a difference, report it instead of silently working around it. To log in locally, use the seeded
admin (`SEED_ADMIN_EMAIL` / `SEED_ADMIN_PASSWORD` in the backend `.env`; email `admin@hcm.test`).
