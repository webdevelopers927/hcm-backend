# API v1: shift records (Employees → Shifts)

Who works which shift, since when, and bulk shift changes. Base URL `http://localhost:8000/api/v1`, Bearer
token, `Accept: application/json`. Only real employees appear (no drafts, no deleted employees).

## How shift history works
- Every change is a dated record (`employee_shift_assignments`). `employees.shift_id` is always the shift in
  force **today**, so the employee list, profile and filters stay correct.
- A change dated today or earlier applies at once. A future-dated change is **scheduled**: it shows as
  `upcoming` and is applied by `php artisan shifts:apply-scheduled` (scheduled daily at 00:05; run
  `php artisan schedule:work` in dev). A back-dated change older than the current shift does not undo it.
- Two changes for the same employee on the same date: the second replaces the first.
- New hires start their history with the shift they were hired on (from their join date). Changing the shift in
  the employee form records a change effective today.

## `GET /employee-shifts`
Query: `search` (name, code, department, division), `shift_id` (an id, or `unassigned`), `sort` (`name` default,
`-name`, `code`, `-code`, `department`, `-department`, `effective_from`, `-effective_from`), `page`, `per_page`
(default 12, max 100).

```jsonc
{
  "data": [
    {
      "employee": { "id": 12, "code": "EMP-0012", "name": "Fatima Javed", "avatar_url": null },
      "department": { "id": 6, "name": "People" },
      "division": { "id": 1, "name": "Technology" },
      "shift": { "id": 4, "name": "Flexible", "start": "10:00", "end": "19:00", "break_minutes": 60 },
      "effective_from": "2026-01-01",          // when the current shift started; null without history
      "previous_shift": { "id": 1, "name": "Morning", "start": "09:00", "end": "18:00", "break_minutes": 60 },
      "upcoming": null                          // or { "id": 31, "shift": {…}, "effective_from": "2026-12-01" }
    }
  ],
  "meta": { "current_page": 1, "last_page": 7, "per_page": 12, "total": 77 },
  // Cards and tabs: every shift with its headcount. Honours `search`, ignores `shift_id`.
  "shifts": [
    { "id": 1, "name": "Morning", "start": "09:00", "end": "18:00", "break_minutes": 60, "employees_count": 21 }
  ],
  "counts": { "all": 77, "unassigned": 0 }
}
```
`shift` is `null` for an employee without one. Night shifts end the next day (`"start": "21:00", "end": "06:00"`).
`meta`/`links` follow Laravel's paginator format.

## `POST /employee-shifts/assign`
```json
{ "employee_ids": [12, 15, 18], "shift_id": 4, "effective_from": "2026-11-10" }
```
Max 500 ids; real, non-deleted employees only. → `200`
```json
{ "message": "Shift change scheduled for 3 employee(s) from 2026-11-10.", "assigned": 3, "scheduled": true }
```
(`scheduled: false` and "3 employee(s) moved to the new shift." when the date is today or earlier.)
Audit-logged (`event=shift_assigned`). `422` errors: `employee_ids`, `employee_ids.N`, `shift_id`, `effective_from`.

## `DELETE /employee-shifts/assignments/{id}`
Cancels a scheduled change (the `upcoming.id` from the list). `409` if it already took effect.

## `GET /employees/{id}/shift-history`
Newest first:
```json
{ "data": [
  { "id": 31, "shift": { "id": 2, "name": "Evening", "start": "14:00", "end": "23:00", "break_minutes": 60 },
    "effective_from": "2026-12-01", "status": "upcoming", "assigned_by": { "id": 1, "name": "Administrator" },
    "created_at": "2026-11-03T10:00:00+00:00" },
  { "id": 12, "shift": { "id": 4, "name": "Flexible", "start": "10:00", "end": "19:00", "break_minutes": 60 },
    "effective_from": "2026-01-01", "status": "current", "assigned_by": null, "created_at": "…" }
] }
```
`status` is `upcoming`, `current` or `past`.

Permission: `employees.assign-shift` (everyone has every permission until roles are built).
