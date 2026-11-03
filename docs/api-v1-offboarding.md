# API v1: off-boarding (Employees → Off-boarding)

Base URL `http://localhost:8000/api/v1`, Bearer token, `Accept: application/json`. Permission:
`employees.offboard`.

## The rule on the employee form
When an employee's **employment status is a notice-period status** (`is_notice_period = true`), three fields
are required. They're stored on the employee and appear in the employee form (`EmployeeDetail`) and payloads:

| Field | Rule |
|---|---|
| `resigned_date` | `YYYY-MM-DD`, not before `join_date` |
| `last_working_day` | `YYYY-MM-DD`, not before `resigned_date` |
| `reason_for_resignation` | text ≤ 2000 |

Enforced like the rest of the core set: on direct create, draft submit, every `PATCH` of a real employee
(`422`, nothing saved) and bulk import (new optional columns `resigned_date`, `last_working_day`,
`reason_for_resignation`). Drafts list them in `missing_fields`. Moving a real employee into a notice-period
status through the form **opens an offboarding case automatically** (exit type `resignation`, stage
`notice_period`). `EmployeeDetail.offboarding` is `{ id, exit_type, stage } | null` for the open case.

## Cases
An employee has at most one open case. A case has:
- `exit_type`: `resignation` | `termination` | `contract_end` | `retirement`
- `stage`: `resigned` → `notice_period` → `clearance` → `completed` (any order except
  `completed`, which only the complete action sets)
- a **checklist** (8 default items: exit letter, handover, laptop, access, ID card, final settlement, exit
  interview, experience letter; each with an `owner` such as HR, IT, Manager, Finance or Admin)
- `final_employment_status`: where the employee goes after leaving. Defaults by type: Resignation → Resigned,
  Termination → Terminated, Retirement → Retired, Contract end → Inactive.

**The day after the last working day** (`offboarding:process-exits`, daily at 00:10) the employee is moved to
the final status, `separation_date` is set to the last working day (if empty), and if that status can't log in
their sessions are revoked. `exited_at` is set on the case. Completing a case whose last working day has already
passed applies the exit immediately.

### `GET /offboardings`
Query: `search` (name, code), `stage`, `exit_type`, `page`, `per_page` (default 50, max 100). Returns open cases
plus those completed in the last 90 days, open first, then by soonest last working day.
```jsonc
{
  "data": [{
    "id": 7,
    "employee": { "id": 12, "code": "EMP-0012", "name": "Fatima Javed", "avatar_url": null,
                  "designation": { "id": 3, "name": "Software Engineer" }, "department": { "id": 1, "name": "Engineering" },
                  "employment_status": { "id": 4, "name": "Notice period", "can_login": true, "is_notice_period": true } },
    "exit_type": "resignation",
    "stage": "notice_period",
    "resigned_date": "2026-11-01",
    "last_working_day": "2026-11-30",
    "days_left": 27,                     // negative once passed
    "reason_for_resignation": "Moving abroad",
    "separation_date": null,
    "final_employment_status": { "id": 7, "name": "Resigned", "can_login": false, "is_notice_period": false },
    "checklist_progress": { "done": 3, "total": 8 },
    "exited_at": null, "completed_at": null,
    "created_by": { "id": 1, "name": "Administrator" }, "created_at": "…"
  }],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 50, "total": 1 },
  "summary": {
    "in_progress": 1, "leaving_in_30_days": 1, "pending_clearance": 0, "completed": 0,
    "by_stage": { "resigned": 0, "notice_period": 1, "clearance": 0, "completed": 0 },
    "stages": [{ "value": "resigned", "label": "Resigned" }, …],
    "exit_types": [{ "value": "resignation", "label": "Resignation" }, …]
  }
}
```

### `POST /offboardings`: start
```json
{ "employee_id": 12, "exit_type": "resignation", "resigned_date": "2026-11-01", "last_working_day": "2026-11-30",
  "reason_for_resignation": "Moving abroad",
  "notice_employment_status_id": 4, "final_employment_status_id": 7, "stage": "resigned" }
```
Required: the first five. `notice_employment_status_id` (must be a notice-period status) defaults to the first
one; the employee is moved into it and their previous status is remembered. → `201 { data: Case + checklist }`.
`409` if the employee already has an open case; `422` for drafts, deleted employees or bad dates.

### `GET /offboardings/{id}`
Case with `checklist: [{ id, label, owner, done, done_at, done_by: {id,name} | null }]`.

### `PATCH /offboardings/{id}`
Any of `exit_type`, `stage` (not `completed`), `final_employment_status_id`, `resigned_date`,
`last_working_day`, `reason_for_resignation` (the dates and reason are written to the employee). `409` once
completed.

### `POST /offboardings/{id}/complete`
`422 errors.checklist` while any item is unticked. `409` if already completed.

### `DELETE /offboardings/{id}`: cancel (e.g. resignation withdrawn)
Restores the employee's previous status and clears the three exit fields. `409` once the employee has exited
or the case is completed.

### Checklist
- `POST /offboardings/{id}/checklist` `{ label, owner? }` → `201`, case
- `PATCH /offboardings/{id}/checklist/{itemId}` `{ done?, label?, owner? }`: `done: true` records who and when
- `DELETE /offboardings/{id}/checklist/{itemId}`

All three return the case with its checklist. Items of another case → `404`.

## Terminations and other exits without notice
Statuses have a third flag, `is_exit` ("ends employment": seeded on for Resigned, Terminated, Retired,
Inactive; off for Suspended, which blocks login but is not an exit). A status can't be both `is_notice_period`
and `is_exit`.

| Situation | What to do | What happens |
|---|---|---|
| Terminated **with notice** | `POST /offboardings` with `exit_type: "termination"`, a future `last_working_day`, `notice_employment_status_id` = "Serving notice (terminated)" | Serves notice (can still log in); the day after the last working day → Terminated, signed out |
| Terminated **with immediate effect** | `POST /offboardings` with `last_working_day` **today or earlier** | No notice period: moved to the final status now (Terminated by default), signed out, `separation_date` set, case opens at `clearance` with `exited_at` set so the checklist still gets done |
| HR sets an exit status **directly in the employee form** (e.g. Terminated) | `PATCH /employees/{id}` with `employment_status_id` + the three exit fields (required on that change) | Same as immediate: a case is opened (exit type from the status name: Resigned → resignation, Retired → retirement, otherwise termination) or the open notice-period case is reused, and the exit is applied now |

The three fields are required when someone **moves into** an exit status (form, draft submit, direct create,
import), not for employees who were already in one before these fields existed. A case that has exited can't be
cancelled. For non-resignation exits, label the fields "Notice / exit date" and "Reason for leaving" in the UI;
the API keys stay `resigned_date` and `reason_for_resignation`.
