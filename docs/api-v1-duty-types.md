# API v1: duty types (Setup → Duty types)

Duty types are a managed list, not a hardcoded enum. Each has a **name** and one flag:

| Flag | Meaning |
|---|---|
| `is_contractual` | Fixed-term or engagement-based work (contract, freelance, internship…), as opposed to regular employment. Identification only for now. |

Seeded (in order): Full-time, Part-time, Contract (contractual), Freelance (contractual), Internship
(contractual), Temporary (contractual), Consultant (contractual). The seeder never overwrites changes made in Setup.

## CRUD: `/duty-types`

| Call | Body / result |
|---|---|
| `GET /duty-types` | `{ data: [{ id, name, is_contractual, sort_order, employees_count }] }`, ordered by `sort_order`, then name. `employees_count` = real employees (no drafts, no deleted). |
| `POST /duty-types` | `{ name, is_contractual, sort_order? }` → `201 { data: row }` (`sort_order` defaults to last) |
| `GET /duty-types/{id}` | `{ data: row }` |
| `PATCH /duty-types/{id}` | any of `name`, `is_contractual`, `sort_order` → `{ data: row }` |
| `DELETE /duty-types/{id}` | `{ message }`; `409` while any employee record (including drafts and deleted employees) has it |

`name` is unique (case-insensitive) and trimmed. Permission: `duty-types.manage`.

## Changed contract on employees

| Where | Before | Now |
|---|---|---|
| Employee payload (`POST /employees`, drafts, `PATCH`) | `duty_type: "full_time"` | `duty_type_id: 1` (required to submit) |
| `EmployeeDetail` | `duty_type: "full_time"` | `duty_type_id` and `duty_type: { id, name, is_contractual }` |
| `GET /employees/form-options` | `duty_types: [{ value, label }]` | `duty_types: [{ id, name, is_contractual }]` |
| Validation error key | `duty_type` | `duty_type_id` ("The duty type field is required.") |
| Bulk import `duty_type` column | enum value | the duty type **name**; case, spaces, hyphens and underscores are ignored (`Full-time` = `full time` = `full_time`) |
