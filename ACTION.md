# ACTION LOG

A running record of every action taken on this project, newest at the bottom.
Format: **#. Date · Action**, then files touched and the result.

---

### 1. 2026-11-17 · Reviewed PRD and planned Phase 1
- Read `Documents/School_Inspection_PRD.docx` (v3.0), `PRODUCT REQUIREMENTS DOCUMENT.docx` (older) and `Document.docx` (original brief).
- Decisions: **no mobile OTP in Phase 1** (PRD Sec 2; OTP service stubbed for Phase 2); build **all of Phase 1** (PRD Steps 1–4).
- Result: plan approved.

### 2. 2026-11-17 · Scaffolded Laravel 12 project
- `composer create-project laravel/laravel ^12.0` (Laravel 12.72), moved into the project root, kept `Documents/`.
- Installed `maatwebsite/excel`, `barryvdh/laravel-dompdf`, `mews/captcha`.
- `.env` / `.env.example`: MySQL `schoolinspection`, app name, `APP_TIMEZONE=Asia/Kolkata`, `SESSION_LIFETIME=30`, ADMIN_* seed credentials, `OTP_DRIVER=log`.
- `config/app.php`: timezone from env. `config/filesystems.php`: added the `private` disk and turned off `serve` on local.
- `git init` (branch `main`); `.gitignore` excludes private uploads.

### 3. 2026-11-17 · Created database schema (PRD Sec 13)
- Checked first that `schoolinspection` existed and had 0 tables.
- Migrations:
  - `0001_01_00_000000_create_master_tables.php`: master_values, states, districts, blocks, clusters
  - `0001_01_01_000000_create_users_table.php`: users (mobile/employee_id unique, level, status, lockout fields), password_reset_tokens, sessions
  - `2026_11_17_000001_create_access_tables.php`: roles, permissions, role_permissions, user_permissions, user_jurisdictions, registration_requests, mobile_change_requests, otp_verifications
  - `2026_11_17_000002_create_school_tables.php`: schools (udise_code CHAR(11) unique), import_batches
  - `2026_11_17_000003_create_inspection_tables.php`: inspections (unique officer+school+date, location snapshot), inspection_answers, inspection_absent_teachers, inspection_fund_positions, inspection_evidence, inspection_remarks
  - `2026_11_17_000004_create_audit_logs_table.php`: audit_logs (append-only)
- Result: `php artisan migrate` succeeded and created 30 tables.

### 4. 2026-11-17 · Added CLAUDE.md and ACTION.md
- `CLAUDE.md`: stack, commands, layout, domain rules, conventions.
- `ACTION.md`: this log.
