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
  - 23-11-2026 **District / Block offices**: a district's or block's `latitude` / `longitude` in the Location Master is its **office location** (plus `office_address`, edited in the Location Master window; also the schedule start point). `MapDataService::offices()` lists the active offices with coordinates for the view (state: district offices; a district: it and its blocks; a block / cluster: its block and district; search: the schools' offices; outside the State only the user's own district / block), sent as `offices` in the map JSON. `map.js` draws them in their own pane (`officeIcon()` / `OFFICE` in `map-common.js`, since 25-11-2026 District HQ purple `bi-bank2` "HQ" / Block office teal `bi-house-door-fill` "B", apart from the school colours; "Show" switch `#fOffices`; the **Plan Inspections map** draws the planned officer's offices too, `ScheduleController::plannerOffices()` → cfg `offices`, so a Cluster officer sees their District HQ and Block office), the office popup has the directions panel and the nearest / farthest school, the school popup shows the straight-line distance to its Block and District office with a Google Maps route from the office, and the schools table a Block office (km) column. Area circles now sit at the centre of their schools (the office point only when no school has GPS). District HQ office locations were set on 25-11-2026 from `Documents/Lat Long of district headquarter.xlsx` (`database/data/assam_district_offices.php`, migration `2026_11_25_000001`, fills only empty values; Tamulpur and Bajali are approximate). Block offices are not set yet.
  - V6 Module 8: `createDirections()` / `directionsPanelHtml()` / `bindDirectionsPanel()` in `map-common.js` provide My location, distance, the OSRM road route (`config('inspection.map.routing')`) and a Google Maps link.
    - Used in the `/map` school popups and on the school page (`resources/js/school-map.js`).
    - The officer's position leaves the browser only when they press "Show route".
    - Location needs HTTPS (or localhost).
- Schedules: `ScheduleController` (`/schedules`: calendar, `plan`, `approvals`, show), `ScheduleService` (rules, approval, reassign, `markInspected()` called from `InspectionController::submit`), `App\Support\RouteOptimizer`, `resources/js/schedule-planner.js` (SortableJS drag and drop) and `schedule-route.js`. Settings in `config('inspection.schedule')`. `schedule_date` is stored as plain Y-m-d (accessor), like `inspection_date`.
- Rating: `app/Rating/RatingCalculator.php` (pure formula, N/A rules, metrics, bands, summary) and `DefaultScheme.php` (version 1); `RatingService` (active scheme, `rate()` called from `InspectionController::submit` inside try/catch, `recalculate()`, `saveNewVersion()`); `Admin\RatingController` (`/admin/rating`, `manage_masters`); `php artisan ratings:recalculate [--all]`. Partial `inspections/partials/rating.blade.php`, component `<x-rating-badge>`.
  - V6 Module 9: `School::latestRatedInspection` (latest submitted rated inspection; eager-load `latestRatedInspection.rating`) and `latestRating()` feed the school page header, the Find School list and the map.
  - `MapDataService` adds `rating` to school rows and a period average to area rows (`areaRatings()`).
  - The map has a "Colour schools by Status / Rating" switch.
- Inspections / My Inspections PDF (23-11-2026): `inspections.export-pdf` (`/export-pdf/{mine|area}`, `ReportController::exportPdf`), the same scope as the Excel export (`exportQuery()`; Cluster officers get only their own), one row per inspection, filter chips, capped by `master_pdf_max_rows`; audited `inspections_exported` with `format`. Since V14 (23-11-2026) **My Inspections has no Excel**, and since 25-11-2026 **Inspections in My Area has none either**: the `inspections.export` route and `ReportController::export()` are removed, both lists download as PDF only.
- Coverage downloads (V14): Excel and PDF both hold the **view on screen**: Summary + the selected tab (`?level=`, `CoverageReportService::level()`) or the selected cluster's schools (`clusterSchoolRows()`), and the Excel adds Schools not visited / Due in cycle for that area. Buttons read "Excel · Block-wise" (`viewLabel()`). Both use the period picked on screen (Daily / Weekly / Monthly / Quarterly / Custom) and since 25-11-2026 name it (`CoveragePeriod::typeLabel()`): PDF title "Inspection Coverage Report · Monthly", period chip, Summary row "Report period type", file names `coverage-monthly-block-wise-<from>-to-<to>`, audit meta `level` and `type`.
- Inspection Coverage Report (Excel `reports.coverage.export`, PDF `reports.coverage.export-pdf` since 23-11-2026: `CoverageReportService::pdfSections()` = Summary + the level in view with a Total row, or a cluster's schools, capped by `master_pdf_max_rows`):
  - Code: `CoverageReportService`, `CoverageReportController` (`/reports/coverage`, `/export`, `/settings`), views in `resources/views/reports/coverage/`.
  - **Visit Coverage**: schools with at least one *verified* visit (not cancelled; start / submit location or a geo-tagged photo within `verify_radius_m` of the school; a school without GPS accepts any location) in the period.
  - **Inspection Coverage**: schools with a *submitted* or unlocked inspection in the inspection-cycle window (`App\Support\CoveragePeriod::cycle()`) that contains the period end.
  - The period comes from `coverage_settings` (`CoverageSetting::forDistrict()`: district override, else state default; the radius always comes from the state row). Gate `configure_coverage`: the State Admin, or a District officer with `manage_users` (own district only).
  - Areas use the school's own district / block / cluster. Verification runs in PHP (`Inspection::haversine()`), and the query count is fixed.
- Rating report (V6 Module 10): `RatingReportService` (jurisdiction-scoped base query joined with `inspection_ratings`; summary, sections, `areas()`, `schools()` latest against previous) and `RatingReportController` (`/reports/ratings`, `/export`; views in `resources/views/reports/ratings/`). The PDF removed in V9 is back since 23-11-2026 as a designed A4 portrait report: `reports.ratings.export-pdf` (`exportPdf()`, view `reports/ratings/pdf.blade.php`: KPI cards, band and section bars, best / lowest 5, area-wise and school-wise tables; table-based layout for DomPDF), rendered with `MasterPdf::view()`, at most `master_pdf_max_rows` schools, audited `rating_report_exported` with `format: pdf`.
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
     - Since 23-11-2026 it is driven by `QuestionnaireDefinition`: `GROUPS` (group headings, shared with the wizard), `STEP_INTROS` / `PAPER_INTROS` (section notes), `CALC_BOXES` (shaded total boxes, partial `inspections/partials/blank-calc.blade.php`). Questions are numbered 2.1, 2.2…, conditions print as "Answer only if …". Change the questionnaire there, not in the view. Print layout: every tick / number box is a **table cell** (partials `blank-opts`, `blank-question`, `blank-calc`), never an inline span, because DomPDF misplaces inline boxes; one fixed-column table per section (No. 9 % / Question 51 % / Answer 40 %, set on a first spacer row since DomPDF ignores `<colgroup>`); text at least 8 pt and dark (print-safe). The PDFs (this form, the inspection report and the master list PDFs) share the background partial `inspections/partials/pdf-watermark.blade.php`: faint SSA logo + title + "SSA, ASSAM", fixed, centred and turned 45° on every page (23-11-2026). The absentee table (section 3) has 12 rows so it fills its page before section 4 (`$absentRows`; 13 fit, re-check with a rendered PDF if steps 1–2 change). Section 4 Class Room Performance is a page of its own: it starts a page, has 7 observation rows (`$classRows`; 8 spill over) and section 5 starts the next page. The report step 2 boxes are the table cells themselves (equal size, fixed rows).
     - `NET_01` "Internet / network problem at the school" (step 1, optional; Yes opens a remarks box, and since 25-11-2026 the remark is optional too: `'remarks_optional' => true`, `InspectionValidator::remarksShown()` shows the box, `remarksRequired()` is false).
     - The list is `reports.network` (`NetworkIssueController`, `can:view_reports`, jurisdiction-scoped, drafts included). The NET_01 value is filtered in PHP (JSON column). Since 25-11-2026 the filtered list downloads as PDF: `reports.network.export-pdf` (`exportPdf()`, `export_excel`, through `MasterPdf::download()`, at most `master_pdf_max_rows`, audited `network_issues_exported`).
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
     - **Master downloads** (23-11-2026): `admin.schools.export-pdf` (filtered School Master PDF, at most `master_pdf_max_rows` = `MASTER_PDF_MAX_ROWS` 3000 schools, else a warning asks for a filter or Excel) and `admin.locations.export` (`/admin/locations/{type}/export/{excel|pdf}`, State / District / Block / Cluster Master with the `q` / `parent` filters and block, cluster and school counts, Download menu on the Location Master; audited `master_exported`). PDFs go through `App\Support\MasterPdf` (`download()` one table, `sections()` several captioned tables, `maxRows()`, `prepare()`; A4 landscape, official header, watermark, Page X of Y; view `admin/exports/master-pdf`, rows in tables of 300 because one huge DomPDF table runs out of memory). All 4,607 clusters: about 130 s, 200 pages, 650 MB; one district's 2,200 schools about 70 s.
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
    - Extra columns are stored in `schools.school_type` (boys / girls / co_ed), `area_type` (rural / urban), `medium` (since 23-11-2026 a list such as "Assamese, Bodo": `School::normaliseMedium()` turns UDISE "AssameseBodo" into it on import and form save, `School::MEDIUMS` are the School Master tick boxes, `mediums()` returns the array; migration `2026_11_23_000003` normalised the stored values. **Since 23-11-2026 the medium is shown nowhere**: not in lists, filters, the school page, the map, the inspection PDF or any report / download; it stays only in the School Master edit form, the import and the upload-format `SchoolListExport`. The inspection PDF prints neither school type nor rural / urban), `ac_id`, `pc_id`, `pincode` and `streams` (JSON; null = no Higher Secondary). Labels come from `School::SCHOOL_TYPES` / `AREA_TYPES` / `STREAMS` and the `*_label` accessors.
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
- **School search suggestions** (V14, 23-11-2026): `schools.suggest` (`/school-suggest`, `SchoolController::suggest`, throttle 120/min, `JurisdictionService::schools()`; 2+ characters, digits = UDISE prefix, else name contains; 10 rows; active only unless `all=1` with `manage_masters`; JSON `items` of `udise`, `name`, `area`, `status`) and `resources/js/school-suggest.js` (delegated, so swapped filter forms keep it). Add `data-school-suggest="{{ route('schools.suggest') }}"` to any school search input in a GET form: picking a school puts its UDISE Code in the box and submits the form. Used on Inspections (mine / area), My Schools and School Master (`all=1`). Find School (live results), the Map View search and the planner list filter have their own.
- **Plan Inspections popups** (V14): `schedule-planner.js` / `schedule-route.js` use the Map View card (`map-pop map-pop-plan is-{planned|available|inspected|pending}`, head + body + actions: status / stop and ETA, distance, last inspected, Add / Remove, Google Maps Directions). A bare `<div class="map-pop">` loses its padding and close button, so always use `mp-head` / `mp-body`.
- **Offices without a location** (V14): never shown. The map lists only offices with coordinates and hides the Offices legend panel (`[data-office-panel]`) when there are none; school popups show only the offices that have a location. The schedule start point is `ScheduleService::startInfo()` (cluster → block → district office location, else the centre of the officer's schools with GPS, else none; `source` and `label`), shown as "Route starts at: …" on the planner.
- **Modification V14, updated document** (25-11-2026; `Documents/Modification of the ApplicationV14.docx`; plan: holidays → notices → assigned visits with an order → Today's schools):
  - **Holiday calendar** (item 3):
    - Table `holidays` (`district_id` null = state holiday, otherwise that district's local holiday). Model `Holiday`.
    - `App\Services\HolidayCalendar`: `holiday()`, `isWorkingDay()`, `offReason()`, `previous/nextWorkingDay()`, `districtOf()`, `canManage()`. Cached per year and district; the version key is bumped by the model events.
    - `HolidayController` (`/holidays`): every officer sees state holidays plus their district's.
    - Gate `manage_holidays` (= `configure_coverage`): the State Admin manages everything, a District officer with `manage_users` their own district's local rows only.
    - Excel import: `date`, `name`, `district_code`. All rows are checked first; existing rows are skipped.
    - Weekly off: `config('inspection.holidays.weekly_off')` (Sunday). 2nd / 4th Saturdays are **not** off days (user decision).
    - **Types** (25-11-2026, `holidays.type`): `gazetted` is the only type that closes the day (`Holiday::OFF_TYPES`; `HolidayCalendar::year()` reads only it); `restricted` and `half` are shown only. `between()` / `upcoming()` return every type for display; chips use `Holiday::chipClass()`.
    - **Assam 2026 list:** `database/data/assam_holidays_2026.php` + `AssamHolidaySeeder` (in `DatabaseSeeder`, not in the test seed list; re-runnable). Loaded on MySQL 25-11-2026. Ali-Aye-Ligang is a gazetted district row for its 11 districts, restricted elsewhere.
    - **Template:** `HolidayTemplateExport` (sheets Holidays + Value lists); `holidays.template?fill=assam-2026` is the filled list (also `Documents/HolidayList2026.xlsx`).
    - **Dashboard Calendar & Notices:** the right rail (`col-xl-4`) stacks `dashboard/partials/calendar.blade.php` (mini month, upcoming 4 holidays) and `dashboard/partials/notices.blade.php` (the State Admin sees sent notices); the banner has quick-link pills. Colours: gazetted red, local orange, **restricted green**, half sky blue. The sidebar (25-11-2026, reduced) lists Dashboard, Today's Schools, **Schools** (tabs Find School | My Schools), **Inspections** (My | In My Area), **Reports** (Coverage | Rating (State Admin) | Network Issues), Map View, Notices & Orders, Inspection Schedules, Holiday Calendar; then the folding groups **Users** and **Masters & Admin** (Bootstrap collapse, open while one of their pages is open; always unfolded in the icon rail). The page tabs are `<x-page-tabs group="schools|inspections|reports" />` (each tab keeps its own permission; hidden when only one is allowed). A new page of one of those groups goes into that component, not the sidebar.
    - `ScheduleService::checkDate()` refuses a plan on a holiday of the officer's district, and the schedule calendar shows the holiday.
  - **Notices & Orders** (item 1):
    - Tables `notices` (type instruction / notification / order, ref_no, attachment on the private disk `notices/`, `levels` JSON, district / block / cluster, expires_on, status published / withdrawn) and `notice_reads` (read_at, acknowledged_at).
    - `Notice::scopeFor($user)` gives the audience, `recipients()` the officers.
    - `NoticeService`: `publish()` (the area is checked against the sender's district), `unreadCount()` (cached 60 s with a version key), `canView()` / `canManage()`, `report()`.
    - `NoticeController` (`/notices`): Inbox / Sent, compose (gate `issue_notices` = `configure_coverage`, levels below the sender only), the show page (marks read; Acknowledge; read report; Withdraw), and the audited attachment route.
    - Shown in the sidebar and topbar badge and the dashboard card. No SMS or email.
  - **Assigned visits with an order** (item 4):
    - `inspection_schedules.order_ref` / `attachment_*` (private `schedule-orders/`), set by `ScheduleService::attachOrder()` from `store` (the planner posts `FormData`).
    - Served by `schedules.attachment` (`canView`, audited). Shown by the partial `schedules/partials/order` (plan page, dashboard).
    - Item 2 (self-planned visits): a past `planned` stop is **Pending** (`InspectionScheduleItem::isPending()`, calendar filter `status=pending`).
  - **Today's Schools** (item 5, `/today`, `TodaySchoolsController`, `DailySchoolListService`):
    - **Tables:**
      - `daily_display_settings` (null district = state default, a district override like `CoverageSetting`; gate `manage_holidays`). **Officers who get the list** (`levels`) is the State's choice: a District user sees the state levels ticked and locked, and their row stores `levels = null` (= follow the State, filled by `DailyDisplaySetting::forDistrict()`, `followsStateLevels()`); only the State Admin can give a district its own levels (migration `2026_11_25_000007`)
      - `daily_school_list_days` (the generated-day marker: `insertOrIgnore` = generate once)
      - `daily_school_lists` (source auto / carried / manual, status pending / visited / skipped / carried; unique (school, day) and (officer, day, school))
    - **Generation:** lazy on the first view (dashboard or `/today`), in the order manual → carried (pending < 30 days) → `pick()` up to `daily_limit`.
    - **`pick()` eligibility:** jurisdiction schools, active; not on another officer's list or plan that day; not inspected within `recent_days`; `blockedSchools` when `skip_blocked`; `max_distance_km` from `startInfo()`.
    - **`pick()` scoring:** never inspected 100, low rating 40 + gap, issues 50 + 5 each (`config('inspection.daily_list.issue_answers')`), less visited ≤ 60, random tie-break; or `selection = random`.
    - No list on holidays or `weekly_off` (`HolidayCalendar::offReason`).
    - `markVisited()` runs at submit (try/catch).
    - **Open drafts** (25-11-2026): a pending row whose school has the officer's own Draft (`DailySchoolListService::openDrafts()`) shows "Draft in progress · N %" and **Continue** (`inspections.step`) in place of Start, has no Skip, and stays (and carries forward) until submitted; the dashboard card shows a "Draft N %" chip.
    - `assign()` / `remove()` are for superiors only (`ScheduleService::isSuperior`).
    - The geo-capturing Start form (`form[data-geo-start]`) is delegated in `app.js`.
- **Linked dates** (25-11-2026, `date-picker.js`): From / To boxes (`from`/`to`, `custom_from`/`custom_to`, map `fFrom`/`fTo`) limit each other: To cannot be before From, From cannot be after To.
- **Filter bars** (23-11-2026): every filter form ends with `<x-filter-buttons />` (Apply + Reset, `.btn-filter` fixed 40 px / 44 px on phones, min width 112 px; props `reset`, `label`, `icon`). Don't add page-specific Apply buttons.
- **Section loading** (23-11-2026, `resources/js/filter-partial.js`): a GET `form[data-filter]` and same-page links (pagination, sort, drill-down) inside a `[data-filter-region]` fetch the page and swap every `[data-filter-region][id]` (filter form, header download buttons, results) with the response's; only `[data-filter-results]` boxes show the spinner overlay (`.section-loader`, text from `data-loader-text`). The URL is pushed (Back works); errors and redirects fall back to a normal load. No server change: the full page is returned. `[data-autosubmit]` (with `data-clears="a,b"`) and `form[data-confirm]` are delegated in `app.js`, so swapped content keeps working. Used on Inspections (mine / area), Coverage, Ratings, Network issues, My Schools, School Master, Users, Audit Log, Login report and Daily report; don't put `data-loader="heavy"` on these filter forms.
- Page loader and network banner: `<x-page-loader />` in both layouts (inline CSS, `window.SI_LOADER`), `resources/js/network-status.js` (slow / offline / back-online banner `#si-net-banner`). The full splash shows only on a slow load or for `data-loader="heavy"` (+ `data-loader-text`); other navigation gets a thin top bar and a busy submit button. `data-no-loader` opts out. Submit buttons get the processing look from `resources/js/button-busy.js` (`data-loading-text`, `window.SI_BUSY`, `data-no-busy` to skip).
- Find School order (23-11-2026, `SchoolController::index`): active first, then `visits` (count of Submitted / Unlocked inspections) ascending, so never-inspected schools come first, then the oldest `last_inspected_on`, then name. Each result shows "Not inspected yet" (`.school-tag.todo`) or "Inspected N times · last date".
- **My Schools** (23-11-2026, `schools.mine` `/my-schools`, `SchoolController::mine`, view `schools/mine`, sidebar after Find School): every school in the officer's own jurisdiction (cluster, block, district, or whole state, via `JurisdictionService::schools()`) listed without a search, in the Find School order (shared `listQuery()`), with tiles (total, inspected, not inspected yet, inspected this month), filters (name / UDISE, district / block / cluster per level, inspected yes / no) and downloads (`export_excel`, audited `my_schools_exported` with `format`):
  - Excel `schools.mine.export` (`App\Exports\MySchoolsExport`): official header in rows 1–5 (Govt of Assam / SSA, logos, MY SCHOOLS, area · officer · records · generated, filters), headings in row 6, and a **protected sheet + locked workbook structure** (read-only; password `EXCEL_PROTECT_PASSWORD`, else random per file; select and auto filter still work). Column widths follow the content (min 10, max 55 for School / 34 others, data wraps), the logos are sized into their column box (`LOGOS`, inside rows 1–3), A4 landscape one page wide with Page X of N. No Medium column (10 columns, A–J).
  - PDF `schools.mine.export-pdf` (`SchoolController::minePdf`, through `App\Support\MasterPdf`, rows from `MySchoolsExport::row()`), at most `master_pdf_max_rows`, else a warning.
  - State-wide list on MySQL about 0.7 s.
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
6. **Evidence lives on the `private` disk** under random names. It is served only through a controller that checks permission. Photos come from the in-app camera **or the phone gallery** (Modification V1). Both go through the same browser pipeline: resize to ≤1600 px, JPEG, stamp strip, SHA-256. Gallery photos are stored with `source = gallery` and labelled "From gallery" in the report and PDF. In the inspection PDF every photo prints at the same size (25-11-2026): `App\Support\PdfPhoto` fits each one, uncropped, into a 1200 × 900 (4:3) light canvas as JPEG (`ReportController::pdf()`), because DomPDF has no object-fit. An upload with the same `evidence_uid` is idempotent. Since 23-11-2026 the camera (`resources/js/camera.js`, `geoState`) shows "Getting location…" while the location loads and keeps **Use photo** disabled; a photo taken meanwhile is stamped again when the location arrives (`restampShot()`), and only a failed location falls back to the required no-location reason.
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
- Theme: Samagra Shiksha palette (blue `#1565c0`, green `#1e9b4b`, orange `#f39223`), base font 0.875rem. Logos are in `public/logo/` (`ASSAM GOVT LOGO.png`, `SSA LOGO.png`). The sidebar opens as an icon rail **by default** (23-11-2026; `localStorage` `si-sidebar = full` keeps it open after the user presses the highlighted Expand button). Rail icons show a styled name popup (`.si-nav-tip`, placed by `app.js`, because `.app-sidebar` clips overflow), not the browser title.
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
