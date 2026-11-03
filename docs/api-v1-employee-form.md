# API v1: employee form, drafts, uploads and reference data

Base URL `http://localhost:8000/api/v1`. Every endpoint needs `Authorization: Bearer <token>` and
`Accept: application/json`. Conventions (snake_case, `YYYY-MM-DD` dates, `422` error shape) are the same as in
[api-v1-employees.md](api-v1-employees.md).

## 1. How the form is saved

The employee form is long, so it can be saved as a **draft** and finished later.

| Step | Call |
|---|---|
| Start a draft (any subset of fields, even `{}`) | `POST /employees/drafts` → `201 { data: EmployeeDetail }` |
| Save more, any time, any section | `PATCH /employees/{id}` → `200 { data: EmployeeDetail }` |
| Load the form | `GET /employees/{id}/details` → `{ data: EmployeeDetail }` |
| List drafts | `GET /employees/drafts?search=&page=&per_page=` |
| Make it a real employee | `POST /employees/{id}/submit` → `200 { data: EmployeeProfile }` |
| Discard a draft | `DELETE /employees/{id}` → `{ message: "Draft deleted." }` (row + files deleted for good) |
| One-shot hire (no draft) | `POST /employees` → `201 { data: EmployeeProfile }` (payload must be complete) |

Drafts have **no employee code, no login account and no invite**. They are not in `GET /employees`, its
status counts, the export, bulk delete, or the reporting-authority pickers; `GET /employees/{id}` (the profile)
returns `404` for them.

### Two kinds of validation

1. **Field rules** run on every save, draft or not: formats, existence, uniqueness, "belongs to"
   (state ⊂ country, city ⊂ state, designation ⊂ department), date order, phone numbers per country. A draft
   can't store an invalid value, only an incomplete one.
2. **Completeness** ("core set") is required to be a real employee. It is checked on submit, on
   `POST /employees`, on every `PATCH` of a real employee (if the result would be incomplete: `422`, nothing
   saved) and per row in the bulk import. For drafts, `EmployeeDetail.missing_fields` shows what is still
   missing, in the same `{ field: [message] }` shape as a `422`.

Core set: `first_name`, `last_name`, `email` (business email = login email), `cnic`, `department_id`,
`designation_id`, `employment_status_id`, `duty_type_id`, `join_date`.
Conditional:
- `remote_access = true` → `remote_access_from` required; `remote_access_until` required unless
  `permanent_remote_access = true`.
- `has_work_experience = true` → at least one work experience, and in each one every field except
  `description` (including the experience letter).
- Each spouse needs `full_name`; each child needs `full_name`; each qualification needs `institution` and
  `degree_program_id`.

Submitting an incomplete draft:
```json
# POST /api/v1/employees/79/submit
# STATUS 422
{
    "message": "The business email field is required. (and 17 more errors)",
    "errors": {
        "email": ["The business email field is required."],
        "department_id": ["The department field is required."],
        "employment_status_id": ["The employment status field is required."],
        "duty_type_id": ["The duty type field is required."],
        "join_date": ["The date of joining field is required."],
        "work_experiences.0.designation": ["The designation field is required."],
        "work_experiences.0.experience_letter": ["The experience letter field is required."]
    }
}
```
(shortened). Submitting twice: `409 { message: "This draft has already been submitted." }`.

## 2. Fields

All optional on a draft. `PATCH` changes only the keys you send; `null` clears a field.

### Personal
| Field | Type / rule |
|---|---|
| `avatar_upload_id` | uuid from `POST /uploads` (`purpose=avatar`); `null` removes the picture |
| `salutation` | `mr` `mrs` `ms` `miss` `dr` `prof` `engr` |
| `first_name`, `middle_name`, `last_name`, `pseudo_name` | string ≤ 100 |
| `father_husband_name` | string ≤ 200 |
| `gender` | `male` `female` `other` |
| `birth_date` | date, before today |
| `cnic` | `^\d{5}-\d{7}-\d$`, unique |
| `cnic_expiry` | date, after `birth_date` |
| `personal_email` | email (lower-cased) |
| `country_id` → `state_id` → `city_id` | ids from the geo endpoints; state must be in the country, city in the state |
| `nationality_id` | an id from `GET /nationalities` (a country id) |
| `marital_status` | `single` `married` `divorced` `widowed` `separated` |
| `religion` | `islam` `christianity` `hinduism` `sikhism` `buddhism` `judaism` `other` `prefer_not_to_say` |
| `blood_group` | `A+` `A-` `B+` `B-` `AB+` `AB-` `O+` `O-` |

### Positional
| Field | Type / rule |
|---|---|
| `email` | business email; also the login email; unique across employees and users |
| `machine_uid` | string ≤ 50, unique |
| `department_id`, `designation_id` | designation must belong to the department |
| `division_id`, `campaign_id`, `shift_id`, `location_id` | lookup ids |
| `work_mode` | `onsite` (default) `hybrid` `remote` |
| `employment_status_id` | id from `form-options.employment_statuses` (managed in Setup; see [api-v1-employment-statuses.md](api-v1-employment-statuses.md)) |
| `duty_type_id` | id from `form-options.duty_types` (managed in Setup; see [api-v1-duty-types.md](api-v1-duty-types.md)) |
| `join_date` | date of joining |
| `probation_start_date` | date, not before `join_date` |
| `probation_duration_id` | id from `form-options.probation_durations`; the response adds `probation_end_date` |
| `confirmation_date`, `separation_date` | dates, not before `join_date` |
| `resigned_date`, `last_working_day`, `reason_for_resignation` | **required while the employment status is a notice period**; last working day ≥ resigned date ≥ join date. See [api-v1-offboarding.md](api-v1-offboarding.md) |
| `reports_to_id`, `indirect_reports_to_id` | a real employee **with team authority**; not self; not the same person twice; no reporting cycles |
| `has_team_authority` | boolean. Can't be turned off while anyone reports to this person |
| `remote_access` | boolean. Turning it off clears the three fields below |
| `permanent_remote_access` | boolean (default false). When true, `remote_access_until` is cleared |
| `remote_access_from`, `remote_access_until` | dates; until ≥ from |

### Contact
| Field | Type / rule |
|---|---|
| `phone` (primary), `secondary_phone`, `landline`, `emergency_contact_phone` | valid for the selected `country_id` (national format like `0300 1234567` is fine). Without a country, international format (`+92 300 1234567`) is required. **Stored and returned as E.164** (`+923001234567`) |
| `permanent_address`, `present_address` | string ≤ 500 |
| `emergency_contact_name` | string ≤ 200 |
| `emergency_contact_relation` | `father` `mother` `spouse` `brother` `sister` `son` `daughter` `guardian` `relative` `friend` `other` |

### Other
| Field | Type / rule |
|---|---|
| `passport_no` | letters/digits ≤ 20 (encrypted; returned masked) |
| `passport_expiry` | date |
| `driving_license_no` | ≤ 30 (encrypted; returned masked) |
| `driving_license_expiry` | date |

### Repeating sections

Sending an array **replaces the whole list**: items with an `id` update that row, items without one are
created, rows you leave out are deleted. Order is kept. Inside an existing item, keys you don't send keep
their value (handy for masked values like a spouse CNIC). Leave the key out entirely to leave the list alone.

```jsonc
"spouses": [                        // max 4
  { "id": 4, "full_name": "Sara", "cnic": "35202-1111111-2", "birth_date": "1996-01-01",
    "children": [                    // max 20 per spouse
      { "id": 9, "full_name": "Ali", "birth_date": "2020-05-05" },
      { "full_name": "Musa", "birth_date": "2025-01-01" } ] }
],
"qualifications": [                 // max 20
  { "id": 2, "institution": "LUMS", "degree_program_id": 23,
    "start_date": "2016-09-01", "end_date": "2018-06-30", "year": 2018 }
],
"has_work_experience": true,        // the one toggle; turning it off deletes all experiences
"work_experiences": [               // max 20; only allowed while the toggle is on (else 422)
  { "id": 1, "company_name": "Systems Ltd", "designation": "Software Engineer", "last_salary": 150000,
    "country_id": 167, "state_id": 3176, "city_id": 85572,
    "period_from": "2022-01-01", "period_to": "2025-12-31",
    "contact_person_name": "Bilal Ahmed", "contact_person_email": "bilal@systems.pk",
    "contact_person_phone": "0300 7654321",          // validated for this experience's country
    "experience_letter_upload_id": "9f3c…",          // purpose=experience_letter; null removes it
    "reason_for_leaving": "Growth", "description": null }
],
"documents": [                      // max 50
  { "title": "CNIC copy", "upload_id": "1b2d…" },    // new: upload_id required (purpose=document)
  { "id": 7, "title": "Renamed" },                   // existing: rename, keep file
  { "id": 8, "upload_id": "77aa…" }                  // existing: replace the file
]
```

Errors inside arrays are keyed by position: `spouses.0.children.1.full_name`, `work_experiences.2.city_id`.

## 3. `EmployeeDetail` (response of drafts, details and PATCH)

Keys mirror the payload, so a form can be loaded straight from it. Lookups also come as `{id, name}`
objects for display. National ids are **masked** (`cnic_masked`, `passport_no_masked`,
`driving_license_no_masked`, `spouses[].cnic_masked`); the full values come only from the audited
`GET /employees/{id}/sensitive`.

Real response (draft just created, shortened):
```json
{
  "data": {
    "id": 79, "code": null, "is_draft": true,
    "created_by": { "id": 1, "name": "Administrator" },
    "created_at": "2026-11-01T18:26:23+00:00", "updated_at": "2026-11-01T18:26:23+00:00",
    "avatar_url": null, "salutation": "ms", "first_name": "Sana", "middle_name": null, "last_name": "Tariq",
    "name": "Sana Tariq", "cnic_masked": "35202-•••••••-2",
    "country_id": 167, "country": { "id": 167, "name": "Pakistan", "iso2": "PK", "phone_code": "92" },
    "state_id": 3176, "state": { "id": 3176, "name": "Punjab" },
    "city_id": 85485, "city": { "id": 85485, "name": "Jaranwala" },
    "nationality_id": null, "nationality": null,
    "email": null, "work_mode": "onsite", "employment_status_id": null, "employment_status": null, "duty_type_id": null, "duty_type": null, "join_date": null,
    "probation_duration_id": null, "probation_end_date": null,
    "reports_to_id": 1,
    "reports_to": { "id": 1, "name": "Ahmed Nawaz", "designation": { "id": 1, "name": "Engineering Manager" }, "avatar_url": null },
    "indirect_reports_to_id": null, "indirect_reports_to": null,
    "has_team_authority": false, "remote_access": false, "permanent_remote_access": false,
    "remote_access_from": null, "remote_access_until": null,
    "phone": "+923001234567",
    "passport_no_masked": null, "driving_license_no_masked": null,
    "spouses": [], "qualifications": [],
    "has_work_experience": true,
    "work_experiences": [
      { "id": 1, "company_name": "Systems Ltd", "designation": null, "last_salary": null,
        "country_id": null, "country": null, "state_id": null, "state": null, "city_id": null, "city": null,
        "experience_letter": null, "reason_for_leaving": null, "description": null }
    ],
    "documents": [],
    "missing_fields": {
      "email": ["The business email field is required."],
      "work_experiences.0.designation": ["The designation field is required."]
    }
  }
}
```
Once attached, `experience_letter` is `{ name, mime_type, size, download_url }` and each document is
`{ id, title, name, mime_type, size, uploaded_at, download_url }`. `last_salary` is a decimal **string**
(`"150000.00"`). `missing_fields` is `{}` for a real employee.

## 4. Files

1. `POST /uploads` (`multipart/form-data`: `purpose`, `file`) → `201`
   ```json
   { "data": { "id": "9f3c1a…", "purpose": "document", "original_name": "cnic.pdf",
               "mime_type": "application/pdf", "size": 183204, "expires_at": "2026-11-02T18:30:00+00:00" } }
   ```
   | purpose | allowed |
   |---|---|
   | `avatar` | jpg, png, webp; ≤ 2 MB |
   | `experience_letter`, `document` | pdf, jpg, png, webp, doc, docx; ≤ 10 MB |
2. Put the id in the JSON save (`avatar_upload_id`, `experience_letter_upload_id`, `documents[].upload_id`).
   An upload can be used once, only by the user who uploaded it, only for its purpose. Unused uploads are
   deleted after 24 hours. If the save fails, the upload stays usable for a retry.
3. Downloads (private, audit-logged, `Cache-Control: no-store`): fetch the `download_url` with the Bearer
   token as a blob. `GET /employees/{id}/documents/{documentId}/download`,
   `GET /employees/{id}/work-experiences/{experienceId}/letter`.

Avatars are public URLs (`avatar_url`).

## 5. Reference data

| Endpoint | Returns |
|---|---|
| `GET /employees/form-options` | every fixed dropdown: `salutations`, `genders`, `marital_statuses`, `religions`, `blood_groups`, `work_modes`, `emergency_contact_relations` (all `{value, label}`), `employment_statuses` (`{id, name, can_login, is_notice_period, is_exit}`), `duty_types` (`{id, name, is_contractual}`), `probation_durations` (`{id, name, months}`), `degree_programs`, `departments`, `designations` (`+department_id`), `divisions`, `campaigns`, `shifts` (`{id, name, start, end}`), `locations` |
| `GET /countries?search=` | all ~250: `{id, name, iso2, iso3, phone_code, emoji}` |
| `GET /countries/{id}/states?search=` | `{id, country_id, name, code}` |
| `GET /states/{id}/cities?search=&page=&per_page=` | paginated (default 50, max 100): `{ data: [{id, state_id, name}], meta }` |
| `GET /nationalities?search=` | `{id, name}` e.g. `{ "id": 167, "name": "Pakistani" }` |

```json
# GET /api/v1/states/3176/cities?search=lah&per_page=3
{"data":[{"id":85572,"state_id":3176,"name":"Lahore"}],"meta":{"current_page":1,"last_page":1,"per_page":3,"total":1}}
```

### Divisions CRUD (`/divisions`) and degree programs CRUD (`/degree-programs`)

| Call | Body / result |
|---|---|
| `GET /divisions` | `[{ id, name, employees_count }]` |
| `GET /degree-programs?search=` | `[{ id, name, qualifications_count }]` |
| `POST` | `{ name }` (unique, trimmed) → `201` |
| `GET /{id}`, `PATCH /{id}` | `{ data: row }` |
| `DELETE /{id}` | `{ message }`; `409` while anything still uses it |

Permissions reported by `/auth/me` gain `divisions.manage` and `degree-programs.manage` (everyone has
everything until roles are built).
