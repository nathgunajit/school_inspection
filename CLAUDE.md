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

Demo officers (`DemoUserSeeder`, non-production only; password `Demo@1234` for all). Since V13 they are placed on the **current Location Master**: the first two districts by code, the first two blocks of each and the first cluster of each block. On the real Assam master that means KOKRAJHAR / DHUBRI.
- `php artisan users:reset-demo --force` (refused in production) deletes **every user except the State Administrator**, with their inspections (and evidence files), schedules, jurisdictions and requests, and then seeds these accounts. It is audited `demo_users_reset`.
- `php artisan db:seed --class=DemoUserSeeder` re-runs safely.
- Active demo officers are listed on the login page (tap to fill) unless `APP_ENV=production` or `DEMO_LOGINS=false`.
- `DemoLocationSchoolSeeder` (North / South District) skips itself when more than two districts exist.

| Mobile | Name | Level / jurisdiction | Notes |
|---|---|---|---|
| 9000000001–04 | Ananya Sharma (F), Bikash Kalita, Chandana Bora (F), Dipankar Saikia | State | 01 can approve District registrations |
| 9000000011–12 | Rakesh Baruah, Meera Kalita (F) | District · KOKRAJHAR (1801) | 11 approve + manage users |
| 9000000013–14 | Nabajit Deka, Rupali Das (F) | District · DHUBRI (1802) | 13 approve + manage users |
| 9000000021–24 | Sanjay Das, Priya Gogoi (F), Imran Hussain, Deepa Bora (F) | Block · DOTMA, GOSSAIGAON, GOLAKGANJ, AGOMONI | can approve Cluster registrations |
| 9000000031–34 | Arjun Saikia, Kavita Deka (F), Rohit Nath, Sunita Rabha (F) | Cluster · GOSSAINICHINA, BHAWRAGURI, BALAJAN, AGOMONI | inspectors |
| 9000000041 | Amit Phukan | Cluster · GOSSAINICHINA | **Pending** approval (the cluster is held, so approval names the officer) |
| 9000000044 | Gita Barman (F) | Cluster | Rejected (can re-apply) |

## Layout
- `app/Enums/`: UserLevel, UserStatus, InspectionStatus, EvidenceStatus
- `app/Services/`: JurisdictionService, ApproverResolver, PermissionService, AuditLogger, InspectionNumberGenerator, EvidenceStorage, MapDataService, ScheduleService, RatingService, Otp/*
- Map View: `MapController` (`/map`, `/map/areas/{level}`, `/map/schools`), `resources/js/map.js` + `map-common.js` (Leaflet + markercluster, bundled; `leaflet-global.js` exposes `L` for the plugin). Tiles, cache and boundary files in `config('inspection.map')`. `php artisan map:boundaries` rebuilds `public/geo/*.geojson` from `public/KML/Assam` (raw KML is not committed).
  - V6 Module 7: the command also writes dissolved per-district outlines in `public/geo/districts/` (`index.json` + `<slug>.geojson`).
  - `DistrictBoundaryService` links a District Master row to its outline (`districts.boundary_name`, else by name) and locates points.
  - The map JSON carries `outline` (the URL for the district in view), drawn by `drawDistrictOutline()` in `map-common.js`.
  - `map:boundaries --check` / `--assign` link unmatched districts.
  - 23-11-2026: zoomed out, grouped school markers show a school icon with the count (`clusterIcon()` in `map.js`, `.si-cluster`), coloured by the main status or the rating band; clustering stops at zoom 16.
  - V6 Module 8: `createDirections()` / `directionsPanelHtml()` / `bindDirectionsPanel()` in `map-common.js` provide My location, distance, the OSRM road route (`config('inspection.map.routing')`) and a Google Maps link.
    - Used in the `/map` school popups and on the school page (`resources/js/school-map.js`).
    - The officer's position leaves the browser only when they press "Show route".
    - Location needs HTTPS (or localhost).
- Schedules: `ScheduleController` (`/schedules`: calendar, `plan`, `approvals`, show), `ScheduleService` (rules, approval, reassign, `markInspected()` called from `InspectionController::submit`), `App\Support\RouteOptimizer`, `resources/js/schedule-planner.js` (SortableJS drag and drop) and `schedule-route.js`. Settings in `config('inspection.schedule')`. `schedule_date` is stored as plain Y-m-d (accessor), like `inspection_date`.
- Rating: `app/Rating/RatingCalculator.php` (pure formula, N/A rules, metrics, bands, summary) and `DefaultScheme.php` (version 1); `RatingService` (active scheme, `rate()` called from `InspectionController::submit` inside try/catch, `recalculate()`, `saveNewVersion()`); `Admin\RatingController` (`/admin/rating`, `manage_masters`); `php artisan ratings:recalculate [--all]`. Partial `inspections/partials/rating.blade.php`, component `<x-rating-badge>`.
  - V6 Module 9: `School::latestRatedInspection` (latest submitted rated inspection; eager-load `latestRatedInspection.rating`) and `latestRating()` feed the school page header, the Find School list and the map.
  - `MapDataService` adds `rating` to school rows and a period average to area rows (`areaRatings()`).
  - The map has a "Colour schools by Status / Rating" switch.
- Inspection Coverage Report:
  - Code: `CoverageReportService`, `CoverageReportController` (`/reports/coverage`, `/export`, `/settings`), views in `resources/views/reports/coverage/`.
  - **Visit Coverage**: schools with at least one *verified* visit (not cancelled; start / submit location or a geo-tagged photo within `verify_radius_m` of the school; a school without GPS accepts any location) in the period.
  - **Inspection Coverage**: schools with a *submitted* or unlocked inspection in the inspection-cycle window (`App\Support\CoveragePeriod::cycle()`) that contains the period end.
  - The period comes from `coverage_settings` (`CoverageSetting::forDistrict()`: district override, else state default; the radius always comes from the state row). Gate `configure_coverage`: the State Admin, or a District officer with `manage_users` (own district only).
  - Areas use the school's own district / block / cluster. Verification runs in PHP (`Inspection::haversine()`), and the query count is fixed.
- Rating report (V6 Module 10): `RatingReportService` (jurisdiction-scoped base query joined with `inspection_ratings`; summary, sections, `areas()`, `schools()` latest against previous) and `RatingReportController` (`/reports/ratings`, `/export`; views in `resources/views/reports/ratings/`). The PDF was removed in V9.
- Modification V9, rating display switch: `config('inspection.rating.display')` (`RATING_DISPLAY`, default **false**).
  - Ratings are still calculated at submit.
  - With the switch off they are hidden everywhere users see them: report aside, `<x-rating-badge>`, school page, map (`MapController::withoutRatings()`, `cfg.showRating`), Excel columns.
  - The **Rating Report** (`/reports/ratings*`, sidebar link) is for the **State Administrator only**: anyone else gets 403 (`can:state_admin` in the `RatingReportController` middleware). It is shown to the State Admin even with the switch off.
  - Rating Settings stays for admins.
  - The inspection PDF never prints the rating.
- Modification V7 (registration): `employee_id` and the ID proof are optional. Mobile numbers must match `^[6-9]\d{9}$` (UI `resources/js/mobile-input.js` on `input[data-mobile]`, server regex, MySQL CHECK).
  - Designation "Other" stores text in `users.designation_other`; always print `$user->designation_label`, never `designation?->label`.
  - `App\Support\FileSize::human()` formats size limits from config.
  - `App\Services\RegisterUploadStash` keeps valid uploads across a failed submit (`register-tmp/`, route `register.kept`, `register:purge-tmp`).
  - The rating card (`inspections/partials/rating.blade.php`) shows the change against `Inspection::previousRating()`.
- Step 2 teacher totals (blocking): TSI_10 + TSI_11 + TSI_12 + TSI_13 (Trained + Untrained) must be **equal to** Total teachers (TSI_04–08) since 21-11-2026, and TSI_09 must not exceed it. Since 23-11-2026 the live box, report and PDF show Total trained / untrained **and Total male / female** (`QuestionnaireDefinition::teacherBreakdown()`, `InspectionValidator::teacherConsistency()`, live box in `wizard.js`). The highlighted **Teachers absent today** box (`.is-highlight`) sits right after that box (after TSI_13), and the Total teachers box is highlighted too; the report and PDF add an absent tile.
- Modification V11 (`Documents/Modification of the ApplicationV11.docx`, 21-11-2026):
  1. The phone bottom nav and the wizard action bar hide while scrolling down (`body.bars-hidden`, end of `resources/js/app.js`). They come back on scroll up, a 700 ms pause, at the page bottom, or while a modal is open.
  2. Blank paper form: `schools.blank-form` (`SchoolController::blankForm()`, `inspections/blank-form.blade.php`, logos from `App\Support\PdfLogos`, audited `blank_form_downloaded`), linked from the school page and the step 1 info card.
     - Since 23-11-2026 it is driven by `QuestionnaireDefinition`: `GROUPS` (group headings, shared with the wizard), `STEP_INTROS` / `PAPER_INTROS` (section notes), `CALC_BOXES` (shaded total boxes, partial `inspections/partials/blank-calc.blade.php`). Questions are numbered 2.1, 2.2…, conditions print as "Answer only if …". Change the questionnaire there, not in the view.
     - `NET_01` "Internet / network problem at the school" (step 1, optional, a remark is required on Yes).
     - The list is `reports.network` (`NetworkIssueController`, `can:view_reports`, jurisdiction-scoped, drafts included). The NET_01 value is filtered in PHP (JSON column).
  3. Teacher names: `inspections.teacher-names` returns names from the same school's other non-cancelled inspections (absent and classroom rows). It is used by the `[data-ac]` autocomplete on `t_name` / `c_name` (`data-ac-icon`, `data-ac-free="off"`; the JSON key is `items`, and subjects still use `subjects`).
  4. Pre-fill: `App\Services\InspectionPrefill::apply()` runs only for a newly created draft (`createInspection`, `wasRecentlyCreated`).
     - It copies steps 1, 2, 5 and 6 answers and remarks, plus fund rows, from the school's latest Submitted / Unlocked inspection on the current questionnaire version.
     - `FRESH` (TSI_01, TSI_02, TSI_09, NET_01), absentees, suggestions and photos are never copied. Since V12, `ZERO` (TSI_09) is stored as 0, and classroom rows (with their TLM ticks, new `row_uid` null) are copied for editing.
     - It sets `inspections.prefilled_from_id` (wizard banner `.prefill-note` on `BANNER_STEPS` 1, 2, 4, 5, 6) and audits `inspection_prefilled`.
     - Tests that rate exact answers clear the copied ones first.
- Location IDs and codes (23-11-2026):
  - `php artisan locations:renumber --force` (`App\Services\LocationRenumber`) renumbers districts, blocks and clusters 1…n in **code order** (district 1801 KOKRAJHAR = 1). It moves every column in `LocationRenumber::REFS` and the id keys in `import_batches.summary`, resets AUTO_INCREMENT, flushes the cache and audits `locations_renumbered`. Run on MySQL 23-11-2026: districts 1–35, blocks 1–147, clusters 1–4607, with school / officer code links unchanged (checksum).
  - **Show location names with their code**: use `->label` (`App\Models\Concerns\HasCodeLabel`, "KOKRAJHAR (1801)", or `District::labelFor($name, $code)` for query rows), never a bare `->name` on a District, Block or Cluster in a view, export or JSON for display. Keep `->name` for form values, typed confirmations and boundary matching (map rows carry both `name` and `label`). Selects of these models must load `code`.
- Modification V13 (`Documents/Modification of the ApplicationV13.docx`, 23-11-2026):
  1. **Demo users**: see `users:reset-demo` above (`App\Services\DemoUserReset`). The MySQL users were reset on 23-11-2026, keeping only the State Admin.
  2. **School Master list** (`Admin\SchoolMasterController::index`, `FILTERS`):
     - Filters: district → block → cluster (server-filled selects), AC, PC, management, category, rural / urban, school type and status.
     - Shows the total and matching counts, plus columns for type · area · medium and AC / PC.
     - `admin.schools.export` downloads the filtered list as Excel in the upload order (`App\Exports\SchoolListExport`, read in chunks). The whole state takes about 150 s and 650 MB.
  3. **Remove master data**:
     - A Delete item in the three-dot menus of Location (not states), AC / PC, School and TLM masters (`*.destroy` routes, `manage_masters`).
     - `App\Services\MasterDeleteGuard` refuses while anything is linked ('used by 120 schools and 2 officers', with Make inactive suggested), and audits `location_deleted` / `ac_deleted` / `pc_deleted` / `school_deleted` / `tlm_item_deleted` with code and name.
     - Schools are force-deleted, so their UDISE codes can be uploaded again.
     - The Excel Upload District report tab has **Remove schools of a district** (`admin.schools.remove-district`). The district name must be typed to confirm. It deletes only schools with no inspection or schedule, and is audited `schools_removed`.
  4. **Audit log and cache clean-up** (`App\Services\HousekeepingService`):
     - `audit:purge [--days=]` (Excel archive, then delete, see rule 7) and `cache:purge` (cache flush, `cache` table rows, compiled views, kept registration files; audited `cache_cleared`).
     - Both are scheduled on the 1st of each month (02:00 / 02:15). The scheduler must run (`schedule:run`, `.env.example`).
     - The Audit Log page has a Clean up card (State Admin): Archive and delete older than N days, Clear cache, and archive downloads (`admin.audit.archive`).
     - `AUDIT_RETENTION_DAYS` defaults to 30.
  5. **Import timeout**: `ImportController::longRunning()` at the start of store / preview / confirm sets:
     - `set_time_limit(IMPORT_TIMEOUT)` (default 0 = no limit) and `memory_limit` = `IMPORT_MEMORY` (2048M)
     - `ignore_user_abort`
     - longer MySQL session timeouts

     The loaders say 'keep this page open'. Apache / php.ini values for production are in `.env.example`.
  6. **Rating map view**: the State Admin always sees ratings on `/map` (`MapController::showRatings()`), even with `RATING_DISPLAY=false`, like the Rating Report. The Rating Report has a **Map view** button (`/map?colour=rating` preselects Colour by Rating).
  7. **Default photo by gender**:
     - `users.gender` (male / female / other): required at registration, editable on the admin user page.
     - `<x-user-avatar>` shows `public/img/avatar-male.svg` / `avatar-female.svg` when no photo is uploaded (`User::defaultPhotoUrl()`), else the initials.
- Modification V12 (`Documents/Modification of the ApplicationV12.docx`, 21-11-2026):
  - Next visit: Teachers present starts at 0, the absentee form is blank, and last visit's classroom rows are listed for editing (`InspectionPrefill`, above).
  - All present: when a step 2 save makes Present exactly equal to Total teachers, `saveAnswer` deletes the absentee rows (audited `absent_teachers_cleared`, JSON `absent_removed`, wizard toast). Present above Total removes nothing.
  - Report page jump bar (`.rp-nav`) wraps, so every step tab is visible; the Photographs tab shows even with no photos. `.min-w-0` is a global utility.
  - School Master upload for **all districts** in one file: `district_id = ImportController::ALL` (`'all'`); the cluster decides the district. `summary['districts']` (per district) and `summary['all_districts']` are stored; the preview and History show a district-wise table. `MasterImportService::SCHOOL_ALIASES` reads UDISE+ headings (`udise_sch_code`, `clcode`). The size limit is `config('inspection.import_max_kb')` (`IMPORT_MAX_KB`, 50 MB).
  - TLM and Location masters: the three-dot menu has only Edit / Make inactive. Edit opens a Bootstrap modal per row, placed outside the table. Edit errors use the `edit` error bag with a hidden `_edit` id, and the modal opens again (`.modal[data-reopen]`, `app.js`). Dropdown menus fade only (a transform animation fought Popper).
  - Expired session / draft: `redirectGuestsTo` / `redirectUsersTo` use `route()` (sub-folder installs). A 419 on a web form goes back with a warning (logout goes to sign-in). `LoginController::safeIntended()` drops background URLs (photos, PDFs, exports, JSON). An officer's own cancelled draft (step / review) goes to My inspections with the reason, a submitted one to its report, and `/submitted` for a non-submitted inspection redirects. Friendly error pages are in `resources/views/errors/` (`page.blade.php`, self-contained).
- Modification V10 (`Documents/Modification of the ApplicationV10.docx`):
  - Step 2 "totals only" (item 2) was **rolled back** on 21-11-2026: the four male / female counts TSI_10–13 are asked again. `TSI_14` / `TSI_15` (Total trained / untrained) stay as `'hidden' => true` codes with no default, never reused. An inspection with only those answers is read through `Q::totalsOnlyBreakdown()` / `Q::shownBreakdownCodes()`; `teacherBreakdown()['split']` is false for it (male / female null), and the rating metric `trained_teachers` uses them.
  - TLM has two lists: `tlm_items.list` (`fln` / `ecce`, `TlmItem::LISTS`) and `tlm_items.section` (ECCE group). Unique key (list, class, name). Step 4 shows FLN (by class), then ECCE (by section) in `renderTlm()`; both are offered for every class. ECCE items print as "ECCE · name" (`fullLabel()`) in text. Display: FLN blue, ECCE orange; `InspectionClassroomObservation::tlmGroups()` (per-list items, no prefix) and `tlmSummary()` ("Yes · FLN 2 · ECCE 1") feed the step-4 rows (`tlm_groups` in the client array), the Review page (`.tlm-grp`, summary strip) and the PDF (`.tag.fln` / `.tag.ecce`).
  - Absent teachers stay on one line (wizard `.rs-teacher`, report `.rv-absent` / `.rv-nowrap`, PDF `.dt.abs`); Type shows on phones too.
- Modification V9, questionnaire:
  - `BAS_PERSON_*` are `'hidden' => true`: not rendered, not required (`InspectionValidator::visible()`). They are shown on the report only for older inspections.
  - TSI_05–TSI_08 and TSI_10–TSI_13 have `'default' => 0` (TSI_09 Present is required since 21-11-2026) (TSI_10–13 since 20-11-2026; `teacherBreakdown()` applies the defaults): not required, shown as 0, counted as 0 (`QuestionnaireDefinition::withDefaults()`), and stored as 0 at submit.
  - Step 2 shows only Total teachers, above TSI_04.
  - Absent rows must be **equal to** Total teachers − Present (`InspectionValidator::absentLimit()`, since 21-11-2026; was ≤). This is blocking, and `addAbsentTeacher` refuses a new row past it; a replay of a saved `row_uid` is let through.
  - Fund details are optional. Amounts are formatted on blur (`.2` → `0.20`, `formatLakh()` in `wizard.js`).
- FLN TLM (`Documents/FLN TLM list_2026-27.docx`) and, since V10, ECCE TLM (`Documents/TLM -2025-26-ECCE (1).docx`), step 4: when `tlm_used = yes`, the officer ticks the TLM items used.
  - Every active item is offered, grouped by class in `renderTlm()` (`SI.tlmClasses`), whatever class is observed.
  - At least one tick is required when TLM = Yes (`InspectionController::classroomData()`). Ticks are stored in `inspection_classroom_tlm`, synced on add, update and replay.
  - The master is `tlm_items` (`Admin\TlmItemController`, `/admin/tlm-items`, `manage_masters`, audited).
  - Items are never deleted, only made inactive. A row keeps an inactive item it already has.
  - UI: `renderTlm()` / `tlmPicked()` in `wizard.js`. Multiple ticks are sent as `tlm_items[]` through `fd.getAll()`.
- Working without network: `resources/js/offline-queue.js` keeps unsent answers, rows and photos in IndexedDB per inspection (`SI.inspectionKey`) and replays them in order. `wizard.js` `queue(fn, onInvalid, op)` needs an `op` for anything that may wait offline.
  - New rows send a `row_uid`, so replays never duplicate (`InspectionController::addRow`).
  - `review.js` locks Submit until the device queue is empty.
  - Any new save endpoint used by the wizard must be safe to replay.
- `app/Questionnaire/QuestionnaireDefinition.php`: the **fixed** questionnaire, **Version 2** (from `Documents/Modification of the ApplicationV1.docx`). It has 7 steps:
  1. Condition (`CND_*` + `BAS_PERSON_*`)
  2. Teacher/Student numbers (`TSI_*`)
  3. Absentee rows (`inspection_absent_teachers`): exactly Total teachers − Present (`TSI_09`, required) rows since 21-11-2026; none when all are present (step 2 Next then skips to step 4). `ABS_00` is hidden (kept for old data)
  4. Classroom rows (`inspection_classroom_observations`)
  5. Fund by financial year in lakh (`inspection_fund_positions`); optional since V9
  6. Governance (`GOVR_*`) + 3 suggestions
  7. Photographs: 4 mandatory categories from `config('inspection.mandatory_photos')`

  The definition drives both rendering and `InspectionValidator`. v1 codes (`GOV_*`, `CON_*`, `ATT_*`, `CLS_*`, `FIN_*`) belong to legacy rows (`questionnaire_version = 1`) and must not be reused.
- `app/Http/Controllers/{Auth,Admin,Inspection,...}`, `app/Policies/`, `app/Http/Middleware/`
- Login monitoring (V6 Module 6): `LoginMonitorService` (records from `LoginController`; `TouchLastSeen` web middleware updates `last_seen_at` once a minute; report queries), table `user_login_logs` (operational, not the audit log), `Admin\LoginReportController` (`/admin/login-report`, `state_admin`), `App\Support\UserAgent`.
  - V8: a **Daily report** tab (`/admin/login-report/daily`, `LoginMonitorService::daily()`), one row per active officer with the current status (online / signed_out / timed_out / absent / never, `LoginMonitorService::DAILY_STATES`).
  - Stat tiles whose label must stay inside the box use `<x-stat-card wrap hint="…">`.
  - The inspection PDF no longer prints the start/submit device location.
- Excel Upload (`/admin/imports`, `Admin\ImportController`, `MasterImportService`): School Master is uploaded **district-wise**, or for **all districts** in one file (V12).
  - Pick a district (or All districts); the file needs only `udise_code`, `school_name`, `cluster_code` (block and district come from the cluster, which must be in that district). Old files with `district_code` / `block_code` still import, and those columns are checked when filled.
  - `imports.preview` checks the file in a rolled-back transaction (nothing saved; file kept in `private:imports/pending`, token in session), then `imports.confirm` saves. `import_batches.district_id` + `summary` (per-block counts).
  - District template (`?district=`) has a second sheet with the district's clusters (`App\Exports\SchoolTemplateExport`).
  - Location Master is also checked before saving. Matching is by code, so a new `name` corrects the name and keeps the ID and all links (audited `location_renamed`). A different `parent_code` for an existing record is rejected (no moves). The "Current locations" download (`imports.template` location `?current=1`) is the file to edit.
  - UDISE+ 2025-26 export (`Documents/SchoolMaster_UDISE 202526.xlsx`, 44,197 schools, 21 columns) imports **as it is** (All districts).
    - Headings are read through `SCHOOL_ALIASES` (`cluster lcode`, `Med_Final_25`, `Assembly_Name` → `ac`, `LS` → `pc`, `School_Location`, `Other_Streams`).
    - Extra columns are stored in `schools.school_type` (boys / girls / co_ed), `area_type` (rural / urban), `medium`, `ac_id`, `pc_id`, `pincode` and `streams` (JSON; null = no Higher Secondary). Labels come from `School::SCHOOL_TYPES` / `AREA_TYPES` / `STREAMS` and the `*_label` accessors.
    - **Template order** (`MasterImportService::SCHOOL_COLUMNS` / `SCHOOL_GROUPS`, one list for every school template and the error file): school (udise_code, school_name), then location (district_code, block_code, cluster_code), then details (… `ac`, `pc` …, status), with **latitude / longitude last**. The header groups are coloured.
      - Sheets: Schools, Clusters, AC list, PC list, Value lists.
      - Uploads are still read by heading name, so any order imports.
    - The upload page preselects **All districts**.
  - **AC / PC Master**: `assembly_constituencies` (code `AC18002`, name, `pc_id`) and `parliamentary_constituencies` (code `PC1801`, name), each with its own code.
    - Models `AssemblyConstituency` / `ParliamentaryConstituency`, `School::ac()` / `pc()` (`pc_id` follows the AC).
    - Seeded by `ConstituencySeeder` from `database/data/assam_constituencies.php`: 126 ACs and 14 PCs from the UDISE file. PC codes use the ECI order (PC1801 Kokrajhar … PC1814 Jorhat), because the file has none.
    - The admin screen is `Admin\ConstituencyController` (`/admin/constituencies`, `manage_masters`, audited `ac_*` / `pc_*`). It has Edit modals and Make inactive. Moving an AC to another PC moves its schools' `pc_id`.
    - Import matching: `ac` accepts "NAME-AC18002", "AC18002", "18002" or the name; `pc` accepts the code or the name. A new AC (with a code) or PC is added, and listed as `summary['new_constituencies']`.
    - `"N - Label"` values are read by number (`MasterImportService::udiseNumbered()`). Category and Management map through `UDISE_CATEGORY` / `UDISE_MANAGEMENT` to master codes, so no duplicate master values are created.
    - A UDISE column missing from the file leaves the stored value alone, and a value that cannot be read becomes null without rejecting the row.
    - Schools are written with the query builder (bulk insert by 500, one preloaded UDISE→id map). The real file takes about 100 s and 700 MB for each of preview and confirm (`memory_limit` 1024M).
  - District report tab: `App\Services\SchoolCountReport` (schools per district / block, active, closed, with GPS), block drill-down `imports.district`, Excel `imports.report`.
- Page loader and network banner: `<x-page-loader />` in both layouts (inline CSS, `window.SI_LOADER`), `resources/js/network-status.js` (slow / offline / back-online banner `#si-net-banner`). The full splash shows only on a slow load or for `data-loader="heavy"` (+ `data-loader-text`); other navigation gets a thin top bar and a busy submit button. `data-no-loader` opts out. Submit buttons get the processing look from `resources/js/button-busy.js` (`data-loading-text`, `window.SI_BUSY`, `data-no-busy` to skip).
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
7. **The audit log is append-only.** Use `AuditLogger::log()`; never update or delete rows (the `AuditLog` model refuses both).
   - The single exception (Modification V13) is `HousekeepingService::purgeAudit()`, run by `audit:purge` or by the State Admin.
   - It first writes the entries older than `audit_retention_days` to an Excel archive (`private:audit-archive/`), then deletes them, and logs `audit_purged`.
   - A schedule's history (`InspectionSchedule::history()`) loses entries older than the cutoff; the archive keeps them.
8. **Server time** is the source of truth for inspection date and time.
9. **Employee photo** (`users.photo_path`, required at registration, JPG/PNG ≤ 1 MB) and the identity proof live on the `private` disk. The photo is served only via `admin.users.photo` (self, approver or user manager in jurisdiction); render it with `<x-user-avatar :user="..." />`.
10. **Schedules** (New Module 2): one plan per officer per day and a school on at most one plan per day (DB unique keys). Only the officer or a higher officer covering them may change a plan; every step is audited.
11. **Ratings** are derived data: never edit `inspection_ratings` by hand. A scheme is never changed in place; saving settings creates a new version, so each rating keeps the scheme it was calculated with.
12. Login asks a math CAPTCHA every time (`AppSupportMathCaptcha`, `LOGIN_MATH_CAPTCHA`; off in phpunit.xml, tests enable it with `config([...math_captcha => true])`). Phase 1 has **no OTP**. `OtpService` exists with a log driver, ready for Phase 2.
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
- Field size standard: every input, select, number stepper and button is **14 px text and 40 px tall** on desktop / tablet. Phones (md and down) use 16 px and 44 px (iOS zoom guard and touch targets). This comes from `$field-fs`, `$num-fs` and `$input-btn-padding-y` in `app.scss`, so don't add per-page font sizes for fields.
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
