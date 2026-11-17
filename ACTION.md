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

### 5. 2026-11-17 · Core domain layer
- Enums: `UserLevel`, `UserStatus`, `InspectionStatus`, `EvidenceStatus`. Config: `config/inspection.php` (PRD Sec 30 proposed values).
- Models for all tables (`AuditLog` throws an exception on update or delete, so it is append-only).
- Services: `JurisdictionService` (scoping), `ApproverResolver` (PRD 3.3 chain), `PermissionService` (PRD 3.2 matrix plus "if permitted" grants), `AuditLogger`, `InspectionNumberGenerator` (SI/year/district/000001), `EvidenceStorage` (finfo MIME check, size, SHA-256, ClamAV hook, private disk, idempotent), `MasterImportService`, `Otp/OtpSender` + `LogOtpSender` (Phase 2 stub).
- `app/Questionnaire/QuestionnaireDefinition.php`: the fixed PRD 8.3 questionnaire (GOV_01–24, CON_01–11, ATT_*, CLS_*, FIN_*) with show/required/remarks/photo rules. `InspectionValidator` enforces the rules on the server and computes progress.
- Seeders: roles and permissions, master values, demo locations (2 districts, 4 blocks, 8 clusters, 24 schools), State Admin (9999999999 / Admin@12345, forced change).

### 6. 2026-11-17 · UI theme and layouts
- Swapped Tailwind for Bootstrap 5.3 + custom SCSS theme (`resources/scss/app.scss`): Inter font, indigo/teal gradient, soft cards, choice chips, stepper, sticky action bar, photo grid, full-screen camera.
- `components/layouts/app` (sidebar on desktop, top bar plus bottom nav with a floating "new inspection" button on mobile), `components/layouts/guest` (split auth screen), components for status badges, stat cards and empty states.

### 7. 2026-11-17 · Modules
- Auth: mobile + password login, CAPTCHA after 3 failures, 15-minute lock after 5, 30-minute session, forced password change, email reset link (no SMS OTP in Phase 1).
- Registration: level-driven cascading location fields, ID proof upload, password policy, privacy notice, re-application after rejection.
- User management: pending approvals (ID proof preview, approve, reject with reason), user list and filters, activate/deactivate, transfer, edit, temporary password reset, grant rights, mobile-change approvals.
- Masters: location and school CRUD screens, Excel templates, upload with validation, added/updated/rejected summary and downloadable error list.
- Inspection: school search by UDISE within jurisdiction, start with GPS, six-step wizard with auto-save, conditional rules, absent-teacher rows, fund rows, suggestions, review screen listing missing items, submit/lock/ID, cancel, State-Admin unlock with reason, 7-day draft expiry command (scheduled daily).
- Evidence: in-app camera (getUserMedia, no gallery), resize to ≤1600 px, stamp strip (UDISE, school, time, lat/lng, officer), SHA-256 in the browser, background upload with automatic retry plus a Retry button, permission-checked image serving, audited downloads.
- Reports: My Inspections, Inspections in my area (filters, count cards), report view with distance from school, DomPDF report with embedded photos, Excel export (cluster users: own only).
- Audit log search screen (State Admin).

### 8. 2026-11-17 · Fixes found during smoke testing
- Laravel 12 base controller lacks `AuthorizesRequests`, so I added it to `app/Http/Controllers/Controller.php`.
- `@json` with inline closures broke Blade compilation in `inspections/step.blade.php`; the data is now built in `@php` and printed with `json_encode`.
- Date filters now use `whereDate` (date-cast values are saved with a time component, which broke `whereBetween` on SQLite).
- Port 8000 is used by another local project (`SchoolInspection-Phase1`). This app now runs on **port 8010** (`APP_URL` updated).

### 9. 2026-11-17 · Verification
- `npm run build`: OK. `php artisan view:cache`: all templates compile.
- HTTP smoke test on MySQL (`schoolinspection`): admin login, forced password change, every admin, master and report page → 200. Inspection start, steps 1–6, auto-save, bad option → 422, JPEG upload → 201, same evidence ID → 200 (no duplicate), PNG as photo → 422, missing location reason → 422, bad fingerprint → 422, image view → 200 image/jpeg, PDF → 200 application/pdf, incomplete submit → redirected to review.
- `php artisan test`: **30 passed (280 assertions)**, run on SQLite in-memory with CAPTCHA disabled in `phpunit.xml`. Covers registration, hierarchy check, re-apply, lockout, approval chain, user management scoping, full submit with ID format and locking, same-day duplicate block, conditional rules, attendance arithmetic, mandatory photos, evidence checks, jurisdiction isolation, unlock (State Admin only) and resubmission, draft expiry, Excel export, school import with error list, append-only audit.
- Not yet verified: visual check in a real browser and on an Android phone (camera and GPS need a device).
