# HCM API v1: Auth & Employees

Base URL (local): `http://localhost:8000/api/v1`

Every response is JSON, apart from the export file download. Send `Accept: application/json` and,
on every endpoint except login, `Authorization: Bearer <token>`.

> All examples below were **captured from a real run** against the seeded development database on
> 2026-11-01 (`php artisan migrate:fresh --seed` followed by curl). Tokens are redacted, and long arrays are
> shortened where marked. The CNIC values are fake factory data.
>
> Salary and bank details are **not part of the Employees module**. They will be added with the payroll
> module (an employee can have no salary, e.g. an intern).

## Conventions

| Topic | Rule |
|---|---|
| JSON keys | `snake_case` |
| Dates | `YYYY-MM-DD` (e.g. `join_date`) |
| Timestamps | ISO 8601 (e.g. `bound_at: "2026-08-14T20:35:47+00:00"`) |
| Array query params | `status[]=active&status[]=probation` (comma-separated `status=active,probation` also works) |
| Single resources | wrapped in `data` |

### Errors

| Status | When | Body |
|---|---|---|
| 401 | missing, invalid, expired or revoked token | `{ "message": "Unauthenticated." }` |
| 403 | the policy denies it (currently only "delete your own record") | `{ "message": "..." }` |
| 404 | unknown or soft-deleted id | `{ "message": "Not found." }` |
| 422 | validation | `{ "message": "...", "errors": { "field": ["..."] } }` |
| 429 | login rate limit | `{ "message": "Too Many Attempts." }` plus a `Retry-After` header |

With `APP_DEBUG=true` (local only), 403 and 500 bodies also carry `exception`, `file`, `line` and `trace`
keys. They are not present in production.

---

## 1. Authentication

### How to log in and get a token

1. Seed the admin: set `SEED_ADMIN_EMAIL` and `SEED_ADMIN_PASSWORD` in `.env`, then run `php artisan db:seed`.
2. Call login and keep `token`.
3. Send it on every request as `Authorization: Bearer <token>`.

```bash
curl -X POST http://localhost:8000/api/v1/auth/login \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"email":"admin@hcm.test","password":"<SEED_ADMIN_PASSWORD>","device_name":"docs"}'
```

Tokens expire after `SANCTUM_TOKEN_EXPIRATION` minutes (default 10080 = 7 days). Login is limited to
**5 attempts per minute per email + IP**.

### `POST /auth/login`
Body: `email` (required), `password` (required), `device_name` (optional; it labels the token).

Success: `200`
```json
{
    "token": "2|<redacted>",
    "token_type": "Bearer",
    "user": {
        "id": 1,
        "name": "Administrator",
        "email": "admin@hcm.test",
        "employee_id": null,
        "two_factor_enabled": false,
        "roles": [],
        "permissions": [
            "employees.view",
            "employees.create",
            "employees.import",
            "employees.update",
            "employees.delete",
            "employees.export",
            "employees.view-sensitive",
            "employees.reset-2fa",
            "employees.unbind-device"
        ]
    }
}
```

Wrong credentials: `422`. The same response is returned for an unknown email and for a deleted employee, so it can't be used to discover which accounts exist.
```json
# POST /api/v1/auth/login
# BODY {"email":"admin@hcm.test","password":"wrong-password"}
# STATUS 422
{
    "message": "These credentials do not match our records.",
    "errors": {
        "email": [
            "These credentials do not match our records."
        ]
    }
}
```

### `GET /auth/me`
```json
# GET /api/v1/auth/me
# STATUS 200
{
    "data": {
        "id": 1,
        "name": "Administrator",
        "email": "admin@hcm.test",
        "employee_id": null,
        "two_factor_enabled": false,
        "roles": [],
        "permissions": [
            "employees.view",
            "employees.create",
            "employees.import",
            "employees.update",
            "employees.delete",
            "employees.export",
            "employees.view-sensitive",
            "employees.reset-2fa",
            "employees.unbind-device"
        ]
    }
}
```

> **Roles and permissions are not implemented yet** (deferred on purpose). `roles` is always `[]` and
> `permissions` lists every permission, because every authenticated user may currently do everything except delete
> their own employee record. `App\Policies\EmployeePolicy` is the single place where real checks will go.

### `POST /auth/logout`
Revokes **only the token used for this request**.
```json
# POST /api/v1/auth/logout
# STATUS 200
{
    "message": "Logged out."
}
```

Using the same token afterwards:
```json
# GET /api/v1/auth/me
# STATUS 401
{
    "message": "Unauthenticated."
}
```

### Unauthenticated request
```json
# GET /api/v1/employees
# STATUS 401
{
    "message": "Unauthenticated."
}
```

---

## 2. Employees

### `GET /employees`: list

| Param | Type | Notes |
|---|---|---|
| `search` | string ≤100 | matches first/last/full name, pseudo name, email, code, machine_uid, designation name; `%` and `_` are matched literally |
| `status[]` | `active` `probation` `on_leave` `notice_period` `inactive` | |
| `work_mode[]` | `onsite` `hybrid` `remote` | |
| `department_id[]` `designation_id[]` `division_id[]` `campaign_id[]` `shift_id[]` `location_id[]` | int | |
| `sort` | `name` (default) `-name` `join_date` `-join_date` `department` `-department` `code` `-code` | `-` = descending; id is the tie-breaker |
| `page` | int ≥1 | |
| `per_page` | int ≥1 | default 12; values above 100 are **clamped** to 100 |

`status_counts` applies **every filter except `status`**, so the status tabs show correct numbers.
The list never contains CNIC, phone or birth date. `can` is computed per row from the
current user's policy.

Unfiltered, `per_page=2`:
```json
# GET /api/v1/employees?per_page=2
# STATUS 200
{
    "data": [
        {
            "id": 18,
            "code": "EMP-0018",
            "machine_uid": "8916",
            "name": "Adeel Sheikh",
            "pseudo_name": null,
            "email": "adeel.sheikh@company.pk",
            "avatar_url": null,
            "designation": {
                "id": 12,
                "name": "Customer Success Manager"
            },
            "department": {
                "id": 4,
                "name": "Customer Success"
            },
            "status": "probation",
            "work_mode": "onsite",
            "shift": {
                "id": 2,
                "name": "Evening",
                "start": "14:00",
                "end": "23:00"
            },
            "campaign": {
                "id": 3,
                "name": "Nova"
            },
            "location": {
                "id": 3,
                "name": "Islamabad"
            },
            "join_date": "2023-12-05",
            "two_factor_enabled": false,
            "device_bound": false,
            "can": {
                "update": true,
                "delete": true,
                "reset_2fa": true,
                "unbind_device": true
            }
        },
        {
            "id": 33,
            "code": "EMP-0033",
            "machine_uid": "4286",
            "name": "Adeel Sheikh",
            "pseudo_name": "Adeel",
            "email": "adeel.sheikh2@company.pk",
            "avatar_url": null,
            "designation": {
                "id": 11,
                "name": "Sales Executive"
            },
            "department": {
                "id": 3,
                "name": "Sales"
            },
            "status": "active",
            "work_mode": "onsite",
            "shift": {
                "id": 2,
                "name": "Evening",
                "start": "14:00",
                "end": "23:00"
            },
            "campaign": null,
            "location": {
                "id": 3,
                "name": "Islamabad"
            },
            "join_date": "2024-02-14",
            "two_factor_enabled": true,
            "device_bound": false,
            "can": {
                "update": true,
                "delete": true,
                "reset_2fa": true,
                "unbind_device": true
            }
        }
    ],
    "meta": {
        "current_page": 1,
        "last_page": 32,
        "per_page": 2,
        "total": 64
    },
    "status_counts": {
        "all": 64,
        "active": 43,
        "probation": 8,
        "on_leave": 6,
        "notice_period": 2,
        "inactive": 5
    }
}
```

Search plus a status filter. `meta.total` is 4 (the active matches), while `status_counts.all` is 5 because
the counts ignore `status[]`:
```json
# GET /api/v1/employees?search=khan&status[]=active&sort=-join_date&per_page=2
# STATUS 200
{
    "data": [
        {
            "id": 43,
            "code": "EMP-0043",
            "machine_uid": "3909",
            "name": "Faisal Khan",
            "pseudo_name": "Faisal",
            "email": "faisal.khan@company.pk",
            "avatar_url": null,
            "designation": {
                "id": 14,
                "name": "Support Agent"
            },
            "department": {
                "id": 4,
                "name": "Customer Success"
            },
            "status": "active",
            "work_mode": "onsite",
            "shift": {
                "id": 4,
                "name": "Flexible",
                "start": "10:00",
                "end": "19:00"
            },
            "campaign": null,
            "location": {
                "id": 3,
                "name": "Islamabad"
            },
            "join_date": "2024-12-02",
            "two_factor_enabled": true,
            "device_bound": true,
            "can": {
                "update": true,
                "delete": true,
                "reset_2fa": true,
                "unbind_device": true
            }
        },
        "…"
    ],
    "meta": {
        "current_page": 1,
        "last_page": 2,
        "per_page": 2,
        "total": 4
    },
    "status_counts": {
        "all": 5,
        "active": 4,
        "probation": 0,
        "on_leave": 0,
        "notice_period": 0,
        "inactive": 1
    }
}
```

Invalid parameters: `422`
```json
# GET /api/v1/employees?sort=salary&status[]=fired
# STATUS 422
{
    "message": "The selected sort is invalid. (and 1 more error)",
    "errors": {
        "sort": [
            "The selected sort is invalid."
        ],
        "status.0": [
            "The selected status.0 is invalid."
        ]
    }
}
```

### `GET /employees/filters`: every dropdown option
Shortened: `designations` shows the first 3 of 26 entries.
```json
# GET /api/v1/employees/filters
# STATUS 200
{
    "data": {
        "departments": [
            {
                "id": 4,
                "name": "Customer Success"
            },
            {
                "id": 2,
                "name": "Design"
            },
            {
                "id": 1,
                "name": "Engineering"
            },
            {
                "id": 5,
                "name": "Finance"
            },
            {
                "id": 8,
                "name": "Marketing"
            },
            {
                "id": 7,
                "name": "Operations"
            },
            {
                "id": 6,
                "name": "People"
            },
            {
                "id": 3,
                "name": "Sales"
            }
        ],
        "designations": [
            {
                "id": 10,
                "name": "Account Manager",
                "department_id": 3
            },
            {
                "id": 16,
                "name": "Accountant",
                "department_id": 5
            },
            {
                "id": 26,
                "name": "Content Writer",
                "department_id": 8
            },
            "…"
        ],
        "divisions": [
            {
                "id": 2,
                "name": "Commercial"
            },
            {
                "id": 3,
                "name": "Corporate"
            },
            {
                "id": 1,
                "name": "Technology"
            }
        ],
        "campaigns": [
            {
                "id": 5,
                "name": "Apex"
            },
            {
                "id": 1,
                "name": "Atlas"
            },
            {
                "id": 3,
                "name": "Nova"
            },
            {
                "id": 2,
                "name": "Orion"
            },
            {
                "id": 4,
                "name": "Zenith"
            }
        ],
        "shifts": [
            {
                "id": 1,
                "name": "Morning",
                "start": "09:00",
                "end": "18:00"
            },
            {
                "id": 4,
                "name": "Flexible",
                "start": "10:00",
                "end": "19:00"
            },
            {
                "id": 2,
                "name": "Evening",
                "start": "14:00",
                "end": "23:00"
            },
            {
                "id": 3,
                "name": "Night",
                "start": "21:00",
                "end": "06:00"
            }
        ],
        "locations": [
            {
                "id": 3,
                "name": "Islamabad"
            },
            {
                "id": 2,
                "name": "Karachi"
            },
            {
                "id": 1,
                "name": "Lahore"
            },
            {
                "id": 4,
                "name": "Remote"
            }
        ],
        "statuses": [
            {
                "value": "active",
                "label": "Active"
            },
            {
                "value": "probation",
                "label": "Probation"
            },
            {
                "value": "on_leave",
                "label": "On leave"
            },
            {
                "value": "notice_period",
                "label": "Notice period"
            },
            {
                "value": "inactive",
                "label": "Inactive"
            }
        ],
        "work_modes": [
            {
                "value": "onsite",
                "label": "Onsite"
            },
            {
                "value": "hybrid",
                "label": "Hybrid"
            },
            {
                "value": "remote",
                "label": "Remote"
            }
        ]
    }
}
```

### `GET /employees/{id}`: profile
The list item plus `phone`, `gender`, `birthday` (`MM-DD`, never the year), `reports_to`, `direct_reports`
and `employment`. `attendance_rate` and `leave_balances` are **always `null`** until those modules exist;
the keys are kept so the contract stays stable.
```json
# GET /api/v1/employees/1
# STATUS 200
{
    "data": {
        "id": 1,
        "code": "EMP-0001",
        "machine_uid": "5736",
        "name": "Ahmed Nawaz",
        "pseudo_name": null,
        "email": "ahmed.nawaz@company.pk",
        "avatar_url": null,
        "designation": {
            "id": 1,
            "name": "Engineering Manager"
        },
        "department": {
            "id": 1,
            "name": "Engineering"
        },
        "status": "active",
        "work_mode": "remote",
        "shift": {
            "id": 2,
            "name": "Evening",
            "start": "14:00",
            "end": "23:00"
        },
        "campaign": {
            "id": 2,
            "name": "Orion"
        },
        "location": {
            "id": 4,
            "name": "Remote"
        },
        "join_date": "2019-12-16",
        "two_factor_enabled": false,
        "device_bound": false,
        "can": {
            "update": true,
            "delete": true,
            "reset_2fa": true,
            "unbind_device": true
        },
        "phone": "+92 334-2612291",
        "gender": "male",
        "birthday": "01-09",
        "reports_to": null,
        "direct_reports": [
            {
                "id": 16,
                "name": "Asad Farooq",
                "designation": {
                    "id": 15,
                    "name": "Finance Manager"
                },
                "avatar_url": null
            },
            {
                "id": 12,
                "name": "Khadija Baig",
                "designation": {
                    "id": 1,
                    "name": "Engineering Manager"
                },
                "avatar_url": null
            },
            {
                "id": 8,
                "name": "Taimur Malik",
                "designation": {
                    "id": 15,
                    "name": "Finance Manager"
                },
                "avatar_url": null
            }
        ],
        "employment": {
            "join_date": "2019-12-16",
            "status": "active"
        },
        "attendance_rate": null,
        "leave_balances": null
    }
}
```

Unknown or deleted id:
```json
# GET /api/v1/employees/999999
# STATUS 404
{
    "message": "Not found."
}
```

### `GET /employees/{id}/sensitive`: PII (reveal)
Returns the CNIC. Every call writes an activity-log entry (`log_name=employees`, `event=sensitive_viewed`, causer = the viewer,
subject = the employee, `properties.ip`). The response carries `Cache-Control: no-store, private`.
```json
# GET /api/v1/employees/1/sensitive
# STATUS 200
{
    "data": {
        "employee_id": 1,
        "cnic": "45244-4627427-9"
    }
}
```

### `POST /employees`: create
Required: `first_name`, `last_name`, `email` (unique across employees, including deleted ones, and users),
`designation_id`, `department_id`, `join_date`.
Optional: `pseudo_name`, `phone`, `cnic` (`^\d{5}-\d{7}-\d$`, unique via a blind index), `gender`
(`male|female|other`), `birth_date`, `machine_uid`, `division_id`, `campaign_id`, `reports_to_id`, `work_mode`,
`status` (default `active`), `shift_id`, `location_id`. Unknown fields (e.g. `gross_salary`) are ignored.

In one transaction it creates the employee (code `EMP-NNNN` derived from the id) and a linked user. After commit it queues
an invite email (the `log` mailer in dev) with a 72-hour set-password link to
`${FRONTEND_URL}/set-password`.
```json
# POST /api/v1/employees
# BODY {"first_name":"Fatima","last_name":"Noor","email":"fatima.noor@company.pk","phone":"+92 300 1234567","cnic":"35202-1234567-1","gender":"female","designation_id":18,"department_id":6,"reports_to_id":1,"work_mode":"hybrid","shift_id":1,"location_id":1,"join_date":"2026-11-03"}
# STATUS 201
{
    "data": {
        "id": 65,
        "code": "EMP-0065",
        "machine_uid": null,
        "name": "Fatima Noor",
        "pseudo_name": null,
        "email": "fatima.noor@company.pk",
        "avatar_url": null,
        "designation": {
            "id": 18,
            "name": "People Ops Lead"
        },
        "department": {
            "id": 6,
            "name": "People"
        },
        "status": "active",
        "work_mode": "hybrid",
        "shift": {
            "id": 1,
            "name": "Morning",
            "start": "09:00",
            "end": "18:00"
        },
        "campaign": null,
        "location": {
            "id": 1,
            "name": "Lahore"
        },
        "join_date": "2026-11-03",
        "two_factor_enabled": false,
        "device_bound": false,
        "can": {
            "update": true,
            "delete": true,
            "reset_2fa": true,
            "unbind_device": true
        },
        "phone": "+92 300 1234567",
        "gender": "female",
        "birthday": null,
        "reports_to": {
            "id": 1,
            "name": "Ahmed Nawaz",
            "designation": {
                "id": 1,
                "name": "Engineering Manager"
            },
            "avatar_url": null
        },
        "direct_reports": [],
        "employment": {
            "join_date": "2026-11-03",
            "status": "active"
        },
        "attendance_rate": null,
        "leave_balances": null
    }
}
```

Validation: `422`
```json
# POST /api/v1/employees
# BODY {"first_name":"","email":"fatima.noor@company.pk","cnic":"123"}
# STATUS 422
{
    "message": "The first name field is required. (and 6 more errors)",
    "errors": {
        "first_name": [
            "The first name field is required."
        ],
        "last_name": [
            "The last name field is required."
        ],
        "email": [
            "The email has already been taken."
        ],
        "cnic": [
            "The cnic field format is invalid."
        ],
        "designation_id": [
            "The designation id field is required."
        ],
        "department_id": [
            "The department id field is required."
        ],
        "join_date": [
            "The join date field is required."
        ]
    }
}
```

### `PATCH /employees/{id}`: partial update
Accepts the same fields, all optional. Changing name or email also updates the linked login user.
```json
# PATCH /api/v1/employees/65
# BODY {"work_mode":"remote"}
# STATUS 200
{
    "data": {
        "id": 65,
        "code": "EMP-0065",
        "machine_uid": null,
        "name": "Fatima Noor",
        "pseudo_name": null,
        "email": "fatima.noor@company.pk",
        "avatar_url": null,
        "designation": {
            "id": 18,
            "name": "People Ops Lead"
        },
        "department": {
            "id": 6,
            "name": "People"
        },
        "status": "active",
        "work_mode": "remote",
        "shift": {
            "id": 1,
            "name": "Morning",
            "start": "09:00",
            "end": "18:00"
        },
        "campaign": null,
        "location": {
            "id": 1,
            "name": "Lahore"
        },
        "join_date": "2026-11-03",
        "two_factor_enabled": false,
        "device_bound": false,
        "can": {
            "update": true,
            "delete": true,
            "reset_2fa": true,
            "unbind_device": true
        },
        "phone": "+92 300 1234567",
        "gender": "female",
        "birthday": null,
        "reports_to": {
            "id": 1,
            "name": "Ahmed Nawaz",
            "designation": {
                "id": 1,
                "name": "Engineering Manager"
            },
            "avatar_url": null
        },
        "direct_reports": [],
        "employment": {
            "join_date": "2026-11-03",
            "status": "active"
        },
        "attendance_rate": null,
        "leave_balances": null
    }
}
```

### `DELETE /employees/{id}`
Soft delete, revokes all of the linked user's tokens, and writes an activity-log entry (`event=deleted`).
```json
# DELETE /api/v1/employees/65
# STATUS 200
{
    "message": "Employee deleted."
}
```

Deleting your own record: `403` (debug keys removed)
```json
# DELETE /api/v1/employees/6
# STATUS 403
{
    "message": "You cannot delete your own employee record."
}
```

### `POST /employees/bulk-delete`
Body `{ "ids": [int, ...] }` (1–500, distinct). It runs in one transaction and checks the delete policy per id.
Reasons: `not_found`, `cannot_delete_self`, `forbidden`. It always returns `200`.
```json
# POST /api/v1/employees/bulk-delete
# BODY {"ids":[64,63,999999]}
# STATUS 200
{
    "deleted": [
        64,
        63
    ],
    "failed": [
        {
            "id": 999999,
            "reason": "not_found"
        }
    ]
}
```

Caller includes their own id:
```json
# POST /api/v1/employees/bulk-delete
# BODY {"ids":[6]}
# STATUS 200
{
    "deleted": [],
    "failed": [
        {
            "id": 6,
            "reason": "cannot_delete_self"
        }
    ]
}
```

### `POST /employees/{id}/two-factor/reset`
Clears the linked user's 2FA fields and logs `event=two_factor_reset`. Returns `422` (`errors.employee`) if the
employee has no user account.
```json
# POST /api/v1/employees/65/two-factor/reset
# STATUS 200
{
    "message": "Two-factor authentication reset.",
    "two_factor_enabled": false
}
```

### `GET /employees/{id}/device`
Returns the active bound attendance device, or `{ "data": null }`.
```json
# GET /api/v1/employees/19/device
# STATUS 200
{
    "data": {
        "device_id": "cd2a054b-75d7-4a33-843f-36d020d182d2",
        "model": "Tecno Camon 20",
        "platform": "android",
        "bound_at": "2026-08-14T20:35:47+00:00"
    }
}
```

### `POST /employees/{id}/device/unbind`
Sets `unbound_at` (the row is kept as history) and logs `event=device_unbound`.
```json
# POST /api/v1/employees/19/device/unbind
# STATUS 200
{
    "message": "Device unbound.",
    "device_bound": false
}
```

Nothing bound: `422`
```json
# POST /api/v1/employees/19/device/unbind
# STATUS 422
{
    "message": "No device is bound to this employee.",
    "errors": {
        "device": [
            "No device is bound to this employee."
        ]
    }
}
```

### `POST /employees/export`
Body: the list filters (`search`, `status`, `department_id`, … as arrays), `sort`, optional `ids` (export only
those rows) and `format` (`csv` | `xlsx`, required). The file is streamed. Columns: code, name, email,
designation, department, status, work mode, shift, location, join date. **No PII.** CSV cells that start
with `= + - @` are prefixed with `'` to block formula injection.

Request: `{"format":"csv","status":["active"],"department_id":[1],"sort":"name"}`

Response headers:
```http
HTTP/1.1 200 OK
Host: localhost:8000
Connection: close
X-Powered-By: PHP/8.2.12
Content-Type: text/csv; charset=UTF-8
Cache-Control: no-store, private
Date: Sun, 01 Nov 2026 08:52:17 GMT
Content-Disposition: attachment; filename=employees-2026-11-01-085217.csv
Access-Control-Allow-Origin: http://localhost:3000
Access-Control-Expose-Headers: Content-Disposition
```

First lines of the body:
```csv
Code,Name,Email,Designation,Department,Status,"Work mode",Shift,Location,"Join date"
EMP-0001,"Ahmed Nawaz",ahmed.nawaz@company.pk,"Engineering Manager",Engineering,Active,Remote,"Evening (14:00-23:00)",Remote,2019-12-16
EMP-0030,"Asad Ahmed",asad.ahmed@company.pk,"QA Engineer",Engineering,Active,Onsite,"Evening (14:00-23:00)",Lahore,2026-10-18
EMP-0057,"Fahad Baig",fahad.baig@company.pk,"Senior Software Engineer",Engineering,Active,Onsite,"Night (21:00-06:00)",Karachi,2025-08-07
EMP-0051,"Faisal Siddiqui",faisal.siddiqui@company.pk,"DevOps Engineer",Engineering,Active,Remote,"Night (21:00-06:00)",Remote,2024-08-17
```

`format=xlsx` returns `Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`
with a `.xlsx` filename.

### `GET /employees/import/template`
Downloads a CSV with every supported column (required columns end in `*`) and one example row built from
real lookup names. The template imports cleanly as-is.

```http
Content-Type: text/csv; charset=UTF-8
Content-Disposition: attachment; filename=employee-import-template.csv
```
```csv
first_name*,last_name*,email*,phone,cnic,gender,birth_date,pseudo_name,machine_uid,department*,designation*,division,campaign,shift,location,reports_to,work_mode,status,join_date*
Ayesha,Khan,ayesha.khan@company.pk,"+92 300 1234567",35202-1234567-1,female,1995-04-18,Ayesha,,"Customer Success","Customer Success Manager",,,Morning,Lahore,,onsite,probation,2026-11-01
```

### `POST /employees/import`: bulk create from CSV/XLSX
`multipart/form-data`: `file` (`.csv` or `.xlsx`, ≤ 5 MB, ≤ 500 rows, first sheet only) and `dry_run`
(`1` validates only).

Rules:
- Headers are case-insensitive; `*`, spaces and punctuation are ignored (`Join Date*` = `join_date`).
  Unknown columns are ignored. Blank lines are skipped.
- **Lookups by name**, case-insensitive: `department`, `designation` (must belong to that department),
  `division`, `campaign`, `shift`, `location`.
- `reports_to`: an existing employee's **code or email**, or the **email of another row in the same file**
  (resolved after insert). Self-references and cycles are rejected.
- Enum values accept labels: `On leave` becomes `on_leave`, `Hybrid` becomes `hybrid`. Dates are `YYYY-MM-DD`;
  Excel date cells, including unformatted serial numbers, are converted.
- Each row is validated with the **same rules as `POST /employees`**, plus duplicate email, CNIC and machine_uid
  checks within the file.
- **All-or-nothing:** any invalid row means nothing is written (`422`, errors keyed `rows.<line>.<column>`,
  where line 1 is the header). On success, all rows are inserted in one transaction, an `imported` activity-log
  entry is written (count and ids), and an invite email is queued per employee.

The example file has 2 rows; Maham reports to Bilal, who is a new row in the same file:
```csv
first_name*,last_name*,email*,department*,designation*,join_date*,shift,location,work_mode,status,reports_to
Bilal,Ashraf,bilal.ashraf@company.pk,Engineering,Software Engineer,2026-11-10,Morning,Lahore,hybrid,probation,EMP-0001
Maham,Tariq,maham.tariq@company.pk,Engineering,QA Engineer,2026-11-10,Morning,Lahore,onsite,probation,bilal.ashraf@company.pk
```

Dry run (`dry_run=1`): `200`
```json
{
    "message": "All 2 rows are valid. Nothing was imported (dry run).",
    "dry_run": true,
    "total_rows": 2
}
```

Commit (`dry_run=0`): `201`
```json
{
    "message": "2 employees imported. Invitations are being sent.",
    "dry_run": false,
    "imported": 2,
    "employee_ids": [
        67,
        68
    ]
}
```

A file with errors: `422`, nothing imported
```csv
first_name*,last_name*,email*,department*,designation*,join_date*
Bilal,Ashraf,ahmed.nawaz@company.pk,Engineering,Software Engineer,2026-11-10
Maham,Tariq,maham.t@company.pk,Enginering,QA Engineer,10/11/2026
Zara,Iqbal,zara.iqbal@company.pk,Finance,Software Engineer,2026-11-10
```
```json
{
    "message": "3 of 3 rows have errors. Nothing was imported.",
    "errors": {
        "rows.2.email": [
            "The email has already been taken."
        ],
        "rows.3.department": [
            "Department \"Enginering\" does not exist."
        ],
        "rows.3.join_date": [
            "The join date field must match the format Y-m-d."
        ],
        "rows.4.designation": [
            "Designation \"Software Engineer\" does not exist in department \"Finance\"."
        ]
    }
}
```

File-level problems (missing or unreadable file, missing required columns, no rows, more than 500 rows) come
back as `422` with `errors.file`.

---

## 3. CORS

Only the origin(s) in `FRONTEND_URL` (comma-separated) are allowed. `Content-Disposition` is exposed so
the frontend can read export filenames. Preflight from `http://localhost:3000`:
```http
HTTP/1.0 204 No Content
Host: localhost:8000
Connection: close
X-Powered-By: PHP/8.2.12
Cache-Control: no-cache, private
Date: Sun, 01 Nov 2026 08:52:18 GMT
Access-Control-Allow-Origin: http://localhost:3000
Vary: Access-Control-Request-Method, Access-Control-Request-Headers
Access-Control-Allow-Methods: GET
Access-Control-Allow-Headers: authorization
Access-Control-Max-Age: 0
Content-type: text/html; charset=UTF-8
```

---

## 4. Not built yet (by design)

- **Roles and permissions** (spatie/laravel-permission), and manager scoping to their reporting tree. See the
  note under `/auth/me`.
- **Email OTP / 2FA.** `users` already has `two_factor_enabled`, `two_factor_secret` (encrypted),
  `two_factor_recovery_codes` (encrypted) and `two_factor_confirmed_at`. A later task can add an OTP
  challenge step to login (e.g. `POST /auth/login` returns `{ two_factor_required: true, challenge_id }`, then
  `POST /auth/two-factor/verify`) without schema changes.
- **Set-password endpoint** for the invite link.
- **`attendance_rate` / `leave_balances`** (always `null`), and avatar upload.
