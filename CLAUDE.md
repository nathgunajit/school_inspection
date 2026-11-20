# CLAUDE.md

Guidance for Claude Code (and developers) working in this repository.

## Project
**School Inspection Management System, Phase 1.** Inspecting officers at State, District, Block and Cluster level register themselves and are approved by a higher officer. They pick schools by UDISE code, fill a fixed six-step questionnaire, capture geo-stamped photos with the in-app camera, and submit locked reports.
- Spec: `Documents/School_Inspection_PRD.docx` (v3.0). The section numbers quoted in code comments (e.g. "PRD 8.2") refer to it.
- Paper form screenshots: `Documents/*.png`.
- Phase 2 (review, configurable questionnaire, offline, dashboards, notifications) is **out of scope**. Don't build it, but don't block it either.
- **New Modules** (`Documents/Modification of the Application New Module.docx`, plan in `Product Details/NEW_MODULES_PLAN.md`): 1 Map View (done), 2 map-based Inspection Scheduling (done), 3 Automatic Inspection Rating (done). These are requested now, even where they overlap Phase 2 (e.g. scoring).

## Stack
- Laravel 12, PHP 8.2 (XAMPP at `D:\xampp\php`), MySQL database **`schoolinspection`** (root, no password locally)
- Blade + Bootstrap 5 + custom SCSS theme (`resources/scss`), vanilla JS modules (`resources/js`), built with Vite
- `maatwebsite/excel` (import/export), `barryvdh/laravel-dompdf` (PDF), `mews/captcha`
- Cache and sessions:
  - Cache store `failover` (Redis, then file). Sessions use Redis when the phpredis extension is loaded, otherwise files. Without phpredis (plain XAMPP), both resolve to `file` (`config/cache.php`, `config/session.php`).
  - `php artisan cache:status` shows what is in use.
  - The pending-approvals badge uses `ApproverResolver::pendingCountFor()`: cached for 60 s, and a version key is bumped whenever a `RegistrationRequest` is saved or deleted.
  - Turn OPcache on in production (`.env.example` checklist).

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
- `app/Services/`: JurisdictionService, ApproverResolver, PermissionService, AuditLogger, InspectionNumberGenerator, EvidenceStorage, MapDataService, ScheduleService, RatingService, Otp/*
- Map View: `MapController` (`/map`, `/map/areas/{level}`, `/map/schools`), `resources/js/map.js` + `map-common.js` (Leaflet + markercluster, bundled; `leaflet-global.js` exposes `L` for the plugin). Tiles, cache and boundary files in `config('inspection.map')`. `php artisan map:boundaries` rebuilds `public/geo/*.geojson` from `public/KML/Assam` (raw KML is not committed).
  - V6 Module 7: the command also writes dissolved per-district outlines in `public/geo/districts/` (`index.json` + `<slug>.geojson`).
  - `DistrictBoundaryService` links a District Master row to its outline (`districts.boundary_name`, else by name) and locates points.
  - The map JSON carries `outline` (the URL for the district in view), drawn by `drawDistrictOutline()` in `map-common.js`.
  - `map:boundaries --check` / `--assign` link unmatched districts.
  - V6 Module 8: `createDirections()` / `directionsPanelHtml()` / `bindDirectionsPanel()` in `map-common.js` provide My location, distance, the OSRM road route (`config('inspection.map.routing')`) and a Google Maps link.
    - Used in the `/map` school popups and on the school page (`resources/js/school-map.js`).
    - The officer's position leaves the browser only when they press "Show route".
    - Location needs HTTPS (or localhost).
- Schedules: `ScheduleController` (`/schedules`: calendar, `plan`, `approvals`, show), `ScheduleService` (rules, approval, reassign, `markInspected()` called from `InspectionController::submit`), `App\Support\RouteOptimizer`, `resources/js/schedule-planner.js` (SortableJS drag and drop) and `schedule-route.js`. Settings in `config('inspection.schedule')`. `schedule_date` is stored as plain Y-m-d (accessor), like `inspection_date`.
- Rating: `app/Rating/RatingCalculator.php` (pure formula, N/A rules, metrics, bands, summary) and `DefaultScheme.php` (version 1); `RatingService` (active scheme, `rate()` called from `InspectionController::submit` inside try/catch, `recalculate()`, `saveNewVersion()`); `Admin\RatingController` (`/admin/rating`, `manage_masters`); `php artisan ratings:recalculate [--all]`. Partial `inspections/partials/rating.blade.php`, component `<x-rating-badge>`.
  - V6 Module 9: `School::latestRatedInspection` (latest submitted rated inspection; eager-load `latestRatedInspection.rating`) and `latestRating()` feed the school page header, the Find School list and the map.
  - `MapDataService` adds `rating` to school rows and a period average to area rows (`areaRatings()`).
  - The map has a "Colour schools by Status / Rating" switch.
- Rating report (V6 Module 10): `RatingReportService` (jurisdiction-scoped base query joined with `inspection_ratings`; summary, sections, `areas()`, `schools()` latest against previous) and `RatingReportController` (`/reports/ratings`, `/export`, `/pdf`; views in `resources/views/reports/ratings/`).
- Modification V7 (registration): `employee_id` and the ID proof are optional. Mobile numbers must match `^[6-9]\d{9}$` (UI `resources/js/mobile-input.js` on `input[data-mobile]`, server regex, MySQL CHECK).
  - Designation "Other" stores text in `users.designation_other`; always print `$user->designation_label`, never `designation?->label`.
  - `App\Support\FileSize::human()` formats size limits from config.
  - `App\Services\RegisterUploadStash` keeps valid uploads across a failed submit (`register-tmp/`, route `register.kept`, `register:purge-tmp`).
  - The rating card (`inspections/partials/rating.blade.php`) shows the change against `Inspection::previousRating()`.
- Step 2 teacher totals (blocking): TSI_10–13 must be less than or equal to Total teachers (TSI_04–08), and TSI_09 must not exceed it (`QuestionnaireDefinition::teacherBreakdown()`, `InspectionValidator::teacherConsistency()`, live box in `wizard.js`).
- Working without network: `resources/js/offline-queue.js` keeps unsent answers, rows and photos in IndexedDB per inspection (`SI.inspectionKey`) and replays them in order. `wizard.js` `queue(fn, onInvalid, op)` needs an `op` for anything that may wait offline.
  - New rows send a `row_uid`, so replays never duplicate (`InspectionController::addRow`).
  - `review.js` locks Submit until the device queue is empty.
  - Any new save endpoint used by the wizard must be safe to replay.
- `app/Questionnaire/QuestionnaireDefinition.php`: the **fixed** questionnaire, **Version 2** (from `Documents/Modification of the ApplicationV1.docx`). It has 7 steps:
  1. Condition (`CND_*` + `BAS_PERSON_*`)
  2. Teacher/Student numbers (`TSI_*`)
  3. Absentee rows (`inspection_absent_teachers`), or `ABS_00` "All teachers present"; one of them is required (`InspectionValidator`)
  4. Classroom rows (`inspection_classroom_observations`)
  5. Fund by financial year in lakh (`inspection_fund_positions`); at least one year is required
  6. Governance (`GOVR_*`) + 3 suggestions
  7. Photographs: 4 mandatory categories from `config('inspection.mandatory_photos')`

  The definition drives both rendering and `InspectionValidator`. v1 codes (`GOV_*`, `CON_*`, `ATT_*`, `CLS_*`, `FIN_*`) belong to legacy rows (`questionnaire_version = 1`) and must not be reused.
- `app/Http/Controllers/{Auth,Admin,Inspection,...}`, `app/Policies/`, `app/Http/Middleware/`
- Login monitoring (V6 Module 6): `LoginMonitorService` (records from `LoginController`; `TouchLastSeen` web middleware updates `last_seen_at` once a minute; report queries), table `user_login_logs` (operational, not the audit log), `Admin\LoginReportController` (`/admin/login-report`, `state_admin`), `App\Support\UserAgent`.
  - V8: a **Daily report** tab (`/admin/login-report/daily`, `LoginMonitorService::daily()`), one row per active officer with the current status (online / signed_out / timed_out / absent / never, `LoginMonitorService::DAILY_STATES`).
  - Stat tiles whose label must stay inside the box use `<x-stat-card wrap hint="…">`.
  - The inspection PDF no longer prints the start/submit device location.
- Excel Upload (`/admin/imports`, `Admin\ImportController`, `MasterImportService`): School Master is uploaded **district-wise**.
  - Pick a district; the file needs only `udise_code`, `school_name`, `cluster_code` (block and district come from the cluster, which must be in that district). Old files with `district_code` / `block_code` still import, and those columns are checked when filled.
  - `imports.preview` checks the file in a rolled-back transaction (nothing saved; file kept in `private:imports/pending`, token in session), then `imports.confirm` saves. `import_batches.district_id` + `summary` (per-block counts).
  - District template (`?district=`) has a second sheet with the district's clusters (`App\Exports\SchoolTemplateExport`).
  - Location Master is also checked before saving. Matching is by code, so a new `name` corrects the name and keeps the ID and all links (audited `location_renamed`). A different `parent_code` for an existing record is rejected (no moves). The "Current locations" download (`imports.template` location `?current=1`) is the file to edit.
  - District report tab: `App\Services\SchoolCountReport` (schools per district / block, active, closed, with GPS), block drill-down `imports.district`, Excel `imports.report`.
- Page loader and network banner: `<x-page-loader />` in both layouts (inline CSS, `window.SI_LOADER`), `resources/js/network-status.js` (slow / offline / back-online banner `#si-net-banner`). The full splash shows only on a slow load or for `data-loader="heavy"` (+ `data-loader-text`); other navigation gets a thin top bar and a busy submit button. `data-no-loader` opts out.
- `config/inspection.php`: the configurable "Proposed" values (PRD 30): draft expiry, lockout, limits, retention
- `database/migrations/`: schema; master tables run first (`0001_01_00_*`)

## Domain rules (do not break)
1. **Jurisdiction scoping.** Every school, user or inspection query a non-admin can reach goes through `JurisdictionService`. Never trust location IDs from the client.
2. **Users cannot change their own level or jurisdiction.** Transfers end the old `user_jurisdictions` row and create a new one.
3. **Question codes** (e.g. `GOVR_02`) are stable identifiers stored with each answer. Never rename or reuse a code.
4. **Submitted inspections are locked.** Only the State Admin can unlock, and only with a reason; every unlock is audited.
5. **One inspection per officer, per school, per day** (DB unique key). Starting goes through `InspectionStartGuard` (Modification V3):
   - The officer's own open draft (any date) is reopened, never duplicated.
   - Another officer's open draft blocks the school ("being inspected by …").
   - A school submitted by another officer is blocked for the rest of that calendar month.
   - Cancelled inspections never block.
   - Blocked attempts are audited (`inspection_start_blocked`).
6. **Evidence lives on the `private` disk** under random names. It is served only through a controller that checks permission. Photos come from the in-app camera **or the phone gallery** (Modification V1). Both go through the same browser pipeline: resize to ≤1600 px, JPEG, stamp strip, SHA-256. Gallery photos are stored with `source = gallery` and labelled "From gallery" in the report and PDF. An upload with the same `evidence_uid` is idempotent.
7. **The audit log is append-only.** Use `AuditLogger::log()`; never update or delete rows.
8. **Server time** is the source of truth for inspection date and time.
9. **Employee photo** (`users.photo_path`, required at registration, JPG/PNG ≤ 1 MB) and the identity proof live on the `private` disk. The photo is served only via `admin.users.photo` (self, approver or user manager in jurisdiction); render it with `<x-user-avatar :user="..." />`.
10. **Schedules** (New Module 2): one plan per officer per day and a school on at most one plan per day (DB unique keys). Only the officer or a higher officer covering them may change a plan; every step is audited.
11. **Ratings** are derived data: never edit `inspection_ratings` by hand. A scheme is never changed in place; saving settings creates a new version, so each rating keeps the scheme it was calculated with.
12. Phase 1 has **no OTP**. `OtpService` exists with a log driver, ready for Phase 2.
13. **Record IDs in URLs are encrypted** (V6 Module 4). Route-bound models use `App\Models\Concerns\HasEncryptedRouteKey`, which issues `App\Support\UrlId` tokens; a numeric or edited ID returns 404.
    - Pass the model, or `Model::routeKeyFor($id)`, to `route()`, never a raw `$x->school_id`.
    - JSON for JS carries a `key` field for URL placeholders (`__ID__`).
    - A new route-bound model must use the trait.
    - Query-string filters (`?cluster=`) stay numeric and are scoped by `JurisdictionService`.
14. **Passwords are RSA-encrypted in the browser** (V6 Module 5).
    - `resources/js/password-encrypt.js` handles every form with `password`, `password_confirmation` or `current_password`.
    - The global web middleware `DecryptPasswordFields` decrypts them before validation (`App\Services\PasswordCrypto`, keys from `php artisan security:keys` in `storage/app/private/keys`).
    - Plain text is refused unless `PASSWORD_ENCRYPTION_REQUIRED=false` (as in phpunit.xml) or load-test mode is on.
    - In tests, post `app(PasswordCrypto::class)->encryptForSession($pw)` after a GET, which creates the nonce.
    - Never submit a password form with `form.submit()`: it skips the submit event, so the password is not encrypted.
15. **One active officer per cluster, Cluster level only** (`AreaOfficerGuard`). State, District and Block officers are not limited and may share an area.
    - Active or Locked officers hold the cluster. Pending, Rejected and Inactive officers do not.
    - Checked at registration, approval, re-activation and transfer.
    - The message names the existing officer with a masked mobile.

## Conventions
- Validate with Form Requests and authorize with policies/gates; keep controllers thin and put logic in services.
- Mobile-first UI: touch targets ≥ 44px, one wizard step per screen on mobile, bottom nav on phones. Reuse the components in `resources/views/components`.
- Theme: Samagra Shiksha palette (blue `#1565c0`, green `#1e9b4b`, orange `#f39223`), base font 0.875rem. Logos are in `public/logo/` (`ASSAM GOVT LOGO.png`, `SSA LOGO.png`). The sidebar collapses to an icon rail; its state is stored in `localStorage` (`si-sidebar`).
- Production: see the checklist at the bottom of `.env.example`. With `APP_ENV=production`, URLs are forced to HTTPS.
- Lists paginate at 25 rows.

## Testing safety
- Tests must run on in-memory SQLite only. `tests/TestCase.php` aborts if the connection is anything else.
- **Never run `php artisan test` while the config is cached.** A cached config makes phpunit.xml be ignored, and RefreshDatabase would wipe MySQL. Run `php artisan config:clear` first.

## Product documents
All product and technical documents live in **`Product Details/`**:
- the technical set (database, architecture, mechanism, roles, UI, diagrams, audit, roadmap)
- `PROJECT_DOCUMENT.md`, `INSPECTION_FORM_DOCUMENT.md`, `NEW_MODULES_PLAN.md`, `DB_INDEXING.md`, `LOAD_TEST.md`, `BROWSER_SUPPORT.md`
- `Requirements/`: copies of `Documents/`, which stays the master copy
- the combined Word file `School_Inspection_Product_Details.docx`

When a document changes, rebuild the Word file.

## ACTION log
After completing each meaningful action (feature, migration, fix, config change), append an entry to **`ACTION.md`**: date, what was done, files touched, result or verification.
