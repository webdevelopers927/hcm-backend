# API v1: employment statuses (Setup → Employment statuses)

Employment statuses are a managed list, not a hardcoded enum. Each status has a **name** and three flags:

| Flag | Effect |
|---|---|
| `can_login` | `false` → employees in this status **can't sign in** (`POST /auth/login` → `422`, `errors.email`: "Your employment status does not allow signing in. Please contact HR."). Moving an employee into such a status, or switching a status's `can_login` off, **revokes their tokens** (signed out everywhere). New hires in such a status get no invite email. |
| `is_notice_period` | Marks people serving notice. `GET /employees?on_notice=1` lists everyone in any notice-period status; `status_counts.on_notice` counts them. Exit details (resigned date, last working day, reason) are required while in such a status. |
| `is_exit` | "Ends employment": the person has left (Resigned, Terminated, Retired, Inactive). Moving someone into it requires the exit details and applies the exit at once (see [api-v1-offboarding.md](api-v1-offboarding.md)). Can't be combined with `is_notice_period`. Optional on create (default `false`). |

Seeded (in order): Active, Probation, On leave, Notice period (notice), Serving notice (terminated) (notice),
Suspended (no login), Resigned, Terminated, Retired, Inactive (no login + ends employment).
The seeder never overwrites flags an admin has changed.

## CRUD: `/employment-statuses`

| Call | Body / result |
|---|---|
| `GET /employment-statuses` | `{ data: [{ id, name, can_login, is_notice_period, is_exit, sort_order, employees_count }] }`, ordered by `sort_order`, then name. `employees_count` = real employees (no drafts, no deleted). |
| `POST /employment-statuses` | `{ name, can_login, is_notice_period, is_exit?, sort_order? }` (all but `sort_order` required; `sort_order` defaults to last) → `201 { data: row }` |
| `GET /employment-statuses/{id}` | `{ data: row }` |
| `PATCH /employment-statuses/{id}` | any of `name`, `can_login`, `is_notice_period`, `is_exit`, `sort_order` → `{ data: row }` |
| `DELETE /employment-statuses/{id}` | `{ message }`; `409` while any employee record (including drafts and deleted employees) has it |

`name` is unique (case-insensitive) and trimmed. Permission: `employment-statuses.manage`.

## How employees use it (changed contract)

| Where | Before | Now |
|---|---|---|
| Employee payload (`POST /employees`, drafts, `PATCH`) | `status: "active"` | `employment_status_id: 1` (required to submit) |
| List item, profile, `EmployeeDetail` | `status: "active"` | `employment_status: { id, name, can_login, is_notice_period, is_exit }` (profile: `employment.employment_status`; detail also has `employment_status_id`) |
| List / export filter | `status[]=active` | `employment_status_id[]=1` (comma list also works), plus `on_notice=1` |
| `status_counts` | `{ all, active, probation, … }` | `{ all, on_notice, by_status: { "<status id>": count } }`, a key for every status; ignores the status filters, honours the rest |
| `GET /employees/filters` | `statuses: [{value, label}]` | `employment_statuses: [{ id, name, can_login, is_notice_period, is_exit }]` |
| `GET /employees/form-options` | `employment_statuses: [{value, label}]` | `employment_statuses: [{ id, name, can_login, is_notice_period, is_exit }]` |
| Bulk import `status` column | enum value | the status **name** (case-insensitive, e.g. `On leave`; `on_leave` also matches) |
| Validation error key | `status` | `employment_status_id` ("The employment status field is required.") |
