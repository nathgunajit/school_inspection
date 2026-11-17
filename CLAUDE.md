# CLAUDE.md

Guidance for Claude Code (and developers) working in this repository.

## Project
**School Inspection Management System, Phase 1.** Inspecting officers at State, District, Block and Cluster level register themselves and are approved by a higher officer. They pick schools by UDISE code, fill a fixed six-step questionnaire, capture geo-stamped photos with the in-app camera, and submit locked reports.
- Spec: `Documents/School_Inspection_PRD.docx` (v3.0). The section numbers quoted in code comments (e.g. "PRD 8.2") refer to it.
- Paper form screenshots: `Documents/*.png`.
- Phase 2 (review, configurable questionnaire, scoring, offline, dashboards, notifications) is **out of scope**. Don't build it, but don't block it either.

## Stack
- Laravel 12, PHP 8.2 (XAMPP at `D:\xampp\php`), MySQL database **`schoolinspection`** (root, no password locally)
- Blade + Bootstrap 5 + custom SCSS theme (`resources/scss`), vanilla JS modules (`resources/js`), built with Vite
- `maatwebsite/excel` (import/export), `barryvdh/laravel-dompdf` (PDF), `mews/captcha`

## Commands
```bash
php artisan serve --port=8010     # http://127.0.0.1:8010 (port 8000 is used by another local project)
npm run dev                       # Vite dev server (or: npm run build)
php artisan migrate --seed        # schema + roles, permissions, masters, State Admin, demo data
php artisan test                  # feature tests (use sqlite in-memory, see phpunit.xml)
php artisan inspections:expire-drafts   # cancels drafts older than config('inspection.draft_expiry_days')
```
Default State Admin login: mobile `9999999999` / `Admin@12345` (from `.env` ADMIN_*; password change is forced on first login).

Demo officers (`DemoUserSeeder`, non-production only; password `Demo@1234` for all; re-run safely with `php artisan db:seed --class=DemoUserSeeder`). Active demo officers are listed on the login page (tap to fill) unless `APP_ENV=production` or `DEMO_LOGINS=false`:

| Mobile | Name | Level / jurisdiction | Notes |
|---|---|---|---|
| 9000000001 | Ananya Sharma | State | can approve District registrations |
| 9000000011 | Rakesh Baruah | District · North District (1801) | approve + manage users |
| 9000000012 | Meera Kalita | District · South District (1802) | |
| 9000000021 | Sanjay Das | Block · Riverside (180101) | can approve Cluster registrations |
| 9000000022–24 | Priya Gogoi, Imran Hussain, Deepa Bora | Block · Hillview, Lakeside, Greenfield | |
| 9000000031–38 | Arjun Saikia … Pooja Medhi | Cluster · one per cluster (18010101 … 18020202) | inspectors |
| 9000000041–43 | Amit Phukan, Rina Bhuyan, Suresh Kakoti | Cluster / Block / District | **Pending** approval |
| 9000000044 | Gita Barman | Cluster | Rejected (can re-apply) |
| 9000000045 | Hemant Dutta | Cluster | Inactive |

## Layout
- `app/Enums/`: UserLevel, UserStatus, InspectionStatus, EvidenceStatus
- `app/Services/`: JurisdictionService, ApproverResolver, PermissionService, AuditLogger, InspectionNumberGenerator, EvidenceStorage, Otp/*
- `app/Questionnaire/QuestionnaireDefinition.php`: the **fixed** questionnaire, **Version 2** (from `Documents/Modification of the ApplicationV1.docx`). It has 7 steps:
  1. Condition (`CND_*` + `BAS_PERSON_*`)
  2. Teacher/Student numbers (`TSI_*`)
  3. Absentee rows (`inspection_absent_teachers`)
  4. Classroom rows (`inspection_classroom_observations`)
  5. Fund by financial year in lakh (`inspection_fund_positions`)
  6. Governance (`GOVR_*`) + 3 suggestions
  7. Photographs: 4 mandatory categories from `config('inspection.mandatory_photos')`

  The definition drives both rendering and `InspectionValidator`. v1 codes (`GOV_*`, `CON_*`, `ATT_*`, `CLS_*`, `FIN_*`) belong to legacy rows (`questionnaire_version = 1`) and must not be reused.
- `app/Http/Controllers/{Auth,Admin,Inspection,...}`, `app/Policies/`, `app/Http/Middleware/`
- `config/inspection.php`: the configurable "Proposed" values (PRD 30): draft expiry, lockout, limits, retention
- `database/migrations/`: schema; master tables run first (`0001_01_00_*`)

## Domain rules (do not break)
1. **Jurisdiction scoping.** Every school, user or inspection query a non-admin can reach goes through `JurisdictionService`. Never trust location IDs from the client.
2. **Users cannot change their own level or jurisdiction.** Transfers end the old `user_jurisdictions` row and create a new one.
3. **Question codes** (e.g. `GOVR_02`) are stable identifiers stored with each answer. Never rename or reuse a code.
4. **Submitted inspections are locked.** Only the State Admin can unlock, and only with a reason; every unlock is audited.
5. **One inspection per officer, per school, per day** (DB unique key). An existing draft is reopened instead of creating a new one.
6. **Evidence lives on the `private` disk** under random names. It is served only through a controller that checks permission. Photos come from the in-app camera **or the phone gallery** (Modification V1). Both go through the same browser pipeline: resize to ≤1600 px, JPEG, stamp strip, SHA-256. Gallery photos are stored with `source = gallery` and labelled "From gallery" in the report and PDF. An upload with the same `evidence_uid` is idempotent.
7. **The audit log is append-only.** Use `AuditLogger::log()`; never update or delete rows.
8. **Server time** is the source of truth for inspection date and time.
9. **Employee photo** (`users.photo_path`, required at registration, JPG/PNG ≤ 1 MB) and the identity proof live on the `private` disk. The photo is served only via `admin.users.photo` (self, approver or user manager in jurisdiction); render it with `<x-user-avatar :user="..." />`.
10. Phase 1 has **no OTP**. `OtpService` exists with a log driver, ready for Phase 2.

## Conventions
- Validate with Form Requests and authorize with policies/gates; keep controllers thin and put logic in services.
- Mobile-first UI: touch targets ≥ 44px, one wizard step per screen on mobile, bottom nav on phones. Reuse the components in `resources/views/components`.
- Theme: Samagra Shiksha palette (blue `#1565c0`, green `#1e9b4b`, orange `#f39223`), base font 0.875rem. Logos are in `public/logo/` (`ASSAM GOVT LOGO.png`, `SSA LOGO.png`). The sidebar collapses to an icon rail; its state is stored in `localStorage` (`si-sidebar`).
- Production: see the checklist at the bottom of `.env.example`. With `APP_ENV=production`, URLs are forced to HTTPS.
- Lists paginate at 25 rows.

## Testing safety
- Tests must run on in-memory SQLite only. `tests/TestCase.php` aborts if the connection is anything else.
- **Never run `php artisan test` while the config is cached.** A cached config makes phpunit.xml be ignored, and RefreshDatabase would wipe MySQL. Run `php artisan config:clear` first.

## ACTION log
After completing each meaningful action (feature, migration, fix, config change), append an entry to **`ACTION.md`**: date, what was done, files touched, result or verification.
