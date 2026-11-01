# Task: Add "Bulk import employees" to the Next.js HCM frontend

## Context
- Next.js frontend (`http://localhost:3000`) talking to the Laravel API at `http://localhost:8000/api/v1`
  with Bearer tokens. Auth, the API client (`apiFetch` or similar) and the **Employees → All employees** list
  page should already exist. Reuse them and match the app's existing conventions (UI kit, data fetching,
  dialogs, toasts). If something is missing or conflicts with this spec, stop and ask.
- Scope: **only** the bulk import feature: an "Import" button on the employee list page that opens an import
  dialog.
- Show the button only when the logged-in user's `permissions` (from `/auth/me`) contains `employees.import`.

## Endpoints

### 1. `GET /employees/import/template`: download the template
Returns a CSV file, not JSON (`Content-Disposition: attachment; filename=employee-import-template.csv`).
Fetch it as a blob and trigger the download, reading the filename from `Content-Disposition`.

Template columns (`*` = required):
```
first_name*, last_name*, email*, phone, cnic, gender, birth_date, pseudo_name, machine_uid,
department*, designation*, division, campaign, shift, location, reports_to,
work_mode, status, join_date*
```

### 2. `POST /employees/import`: validate or import
`multipart/form-data` fields:
- `file`: `.csv` or `.xlsx`, max **5 MB**, max **500 rows**
- `dry_run`: `1` = validate only, nothing saved; `0` = import

Use `FormData`. **Don't set `Content-Type` yourself**; the browser adds the multipart boundary.
Send `Accept: application/json` and the Bearer token as usual.

Real responses:

Dry run OK: **200**
```json
{ "message": "All 2 rows are valid. Nothing was imported (dry run).", "dry_run": true, "total_rows": 2 }
```

Import OK: **201**
```json
{ "message": "2 employees imported. Invitations are being sent.", "dry_run": false, "imported": 2, "employee_ids": [67, 68] }
```

Row errors: **422**. Nothing was imported, all-or-nothing:
```json
{
  "message": "3 of 3 rows have errors. Nothing was imported.",
  "errors": {
    "rows.2.email": ["The email has already been taken."],
    "rows.3.department": ["Department \"Enginering\" does not exist."],
    "rows.3.join_date": ["The join date field must match the format Y-m-d."],
    "rows.4.designation": ["Designation \"Software Engineer\" does not exist in department \"Finance\"."]
  }
}
```
- Keys are `rows.<line>.<column>`. `<line>` is the spreadsheet line number, and the header is line 1, so it
  matches what the user sees in Excel.

File problems: **422** with a `file` key, for example:
```json
{ "message": "Missing required columns: email, join_date. ...", "errors": { "file": ["Missing required columns: email, join_date. Download the template for the expected headers."] } }
```
(Other `file` errors: wrong type, unreadable file, no rows, more than 500 rows, larger than 5 MB.)

Other statuses follow the app's normal handling: 401 → logout, 500 → generic error toast.

## TypeScript types
```ts
type ImportDryRunResponse = { message: string; dry_run: true; total_rows: number };
type ImportCommitResponse = { message: string; dry_run: false; imported: number; employee_ids: number[] };
type ImportErrorResponse = { message: string; errors: Record<string, string[]> }; // "rows.3.department" | "file"

type ImportRowError = { line: number; column: string; messages: string[] };
// Parse: key.match(/^rows\.(\d+)\.(.+)$/) → { line, column }; the "file" key → file-level error
```

## UI flow (one dialog, three states)
1. **Pick file**
   - A short explanation, plus a "Download template" button (endpoint 1).
   - A file input or drop zone that accepts `.csv,.xlsx`. Check the extension and the 5 MB size on the client
     before uploading, and show the chosen filename.
   - A "Check file" button sends the upload with `dry_run=1` and shows a loading state.
2. **Result of the check**
   - **200** → success state: "All N rows are valid." Primary button "Import N employees" resends the **same
     file** with `dry_run=0`. Also offer "Choose a different file".
   - **422 with `rows.*`** → show the summary `message`, then a table grouped by line:
     `Line | Column | Problem` (sorted by line; one row per message). Use human column labels
     (`join_date` → "Join date"). Make clear that **nothing was imported** and the user should fix the
     file and upload again. Offer "Choose a different file".
   - **422 with `file`** → show that message inline under the file input.
3. **Imported (201)**
   - Toast `"{imported} employees imported. Invitations are being sent."`, close the dialog, and refetch or
     invalidate the employee list and the status counts.

Other UX rules:
- Disable the buttons while a request is in flight, and prevent a double submit on "Import".
- Keep the selected `File` object in state so the commit reuses it without asking again.
- Closing the dialog resets everything.
- Keyboard accessible dialog with focus trap and Escape to close. The errors table must be readable by
  screen readers.

## Help text to show in the dialog
Keep it short, as bullet points:
- Department, designation, shift, location, division and campaign use **names** (e.g. "Engineering",
  "Morning"), not ids. The designation must belong to the department.
- `reports_to` = an existing employee code (`EMP-0001`) or email, or the email of another row in the same file.
- Dates are `YYYY-MM-DD`.
- Status: active, probation, on leave, notice period, inactive. Work mode: onsite, hybrid, remote.
- If any row has an error, nothing is imported. Every imported employee receives an invitation email.

## Quality bar
- Strict TypeScript, no `any`. Put the API calls in the existing employees API module
  (`downloadImportTemplate()`, `importEmployees(file, { dryRun })`).
- Never log file contents (they contain CNIC and other personal data) to the console or to analytics.
- Run the project's lint, typecheck and tests, and report the real output. Finish with a short note on what
  you built and any mismatch with these responses.
- Local login for testing: `admin@hcm.test` (the password is in the backend `.env` as `SEED_ADMIN_PASSWORD`).
