# UI Pages and Application Modules

19-11-2026, navigation updated 25-11-2026. The routes come from `php artisan route:list`, the views from the `view()` calls in the controllers, and the menu rules from `resources/views/components/layouts/app.blade.php`. Screenshots are in `docs/screenshots/`. They were taken at Modification V5 and show the inspection screens; newer screens (Map, Schedules, Rating) have no screenshot yet.

"Role" means the gate needed. All officer levels have `conduct_inspections`, `view_reports` and `export_excel` by default (see `User_Roles_and_Permissions.md`).

---

## 1. Page inventory

### 1.1 Guest pages (layout `components/layouts/portal.blade.php`)
| Page | Route | Controller@method | Blade view | Role | Main functionality |
|---|---|---|---|---|---|
| Login | `GET /login` | `Auth\LoginController@show` / `@login` | `auth/login` | guest | Mobile + password, CAPTCHA after 2 failures, lockout message, demo officers panel (non-production) |
| Register | `GET /register` | `Auth\RegisterController@show` / `@store` | `auth/register` | guest | Officer self-registration: level, cascading area, designation/Other, photo, ID proof, kept uploads |
| Forgot password | `GET /forgot-password` | `Auth\PasswordController@forgot` / `@sendLink` | `auth/forgot-password` | guest | Email reset link by mobile number |
| Reset password | `GET /reset-password/{token}` | `Auth\PasswordController@resetForm` / `@reset` | `auth/reset-password` | guest | New password |

### 1.2 Officer pages (layout `components/layouts/app.blade.php`)
| Page | Route | Controller@method | Blade view | Role | Main functionality |
|---|---|---|---|---|---|
| Dashboard | `/dashboard` | `DashboardController` | `dashboard` | any active | Stat cards (total schools in the officer's cluster / block / district / state with schools inspected this month, today, month, total, drafts), continue drafts, recent reports, today's plan, pending approvals |
| Profile | `/profile` | `ProfileController@show` (+ `updatePhoto`, `requestMobileChange`) | `profile` | any | Details, postings history, photo change, mobile change request |
| Change password | `/password/change` | `Auth\PasswordController@edit` / `@update` | `auth/change-password` | any | Forced on first login |
| Find School | `/schools` | `SchoolController@index` | `schools/index` (+ `schools/_results` for AJAX) | `conduct_inspections` | Search by UDISE/name in the jurisdiction; status and rating badges |
| My Schools | `/my-schools` | `SchoolController@mine` (+ `mineExport` Excel, `minePdf` PDF) | `schools/mine` | `conduct_inspections` (downloads: `export_excel`) | 23-11-2026: every school in the officer's own cluster / block / district / state, not inspected first; tiles, filters; Excel download with the official header (Govt of Assam / SSA, logos, area, officer, filters) on a protected, read-only sheet (columns sized to the content, long text wraps, A4 landscape print, logos inside the header), and a PDF download (official header, background, page numbers; at most `MASTER_PDF_MAX_ROWS` schools) |
| School page | `/schools/{key}` | `SchoolController@show` | `schools/show` | `conduct_inspections` | Master data, map with directions, history, latest rating, Start inspection |
| Inspection step | `/inspections/{key}/step/{1-7}` | `InspectionController@step` | `inspections/step` (+ partials `stepper`, `question`, `camera`, `photo-viewer`, `fund-summary`) | officer of the draft | Wizard step with autosave, rows, photos, offline indicator |
| Review | `/inspections/{key}/review` | `InspectionController@review` | `inspections/review` | officer | Missing items by step, answers, confirm and Submit |
| Submitted | `/inspections/{key}/submitted` | `InspectionController@submitted` | `inspections/submitted` | officer / viewer | Confirmation with the inspection number |
| Inspection report | `/inspections/{key}` | `ReportController@show` | `inspections/show` (+ `step-answers`, `rating`) | officer or area viewer | Full report, photos, rating card, PDF button, unlock (State Admin) |
| Inspection PDF | `/inspections/{key}/pdf` | `ReportController@pdf` | `inspections/pdf` | same | DomPDF. Since V8 the device location at start/submit is not printed |
| My Inspections | `/my-inspections` | `ReportController@mine` | `inspections/index` (`mode=mine`) | `conduct_inspections` | Own inspections, filters, Excel and PDF (23-11-2026) |
| Inspections in Area | `/inspections` | `ReportController@area` | `inspections/index` (`mode=area`) | `view_reports` | Area list: district/block/cluster/officer/status/school/date filters, Excel and PDF (23-11-2026) |
| ~~Excel export~~ | removed | — | — | — | Since V14 (23-11 / 25-11-2026) My Inspections and Inspections in My Area download as PDF only (the `inspections.export` route and `ReportController::export()` are removed) |
| PDF list | `/export-pdf/{mine\|area}` | `ReportController@exportPdf` | `admin/exports/master-pdf` | `export_excel` | 23-11-2026: one row per inspection (ID, date, school, cluster, block, officer, status, absentees, classrooms, photos, submitted), the filters as chips; at most `MASTER_PDF_MAX_ROWS` rows |
| Inspection Coverage Report | `/reports/coverage` (+ `/export` Excel, `/export-pdf` PDF, `/settings`) | `CoverageReportController` | `reports/coverage/index`, `settings` | `view_reports` (downloads: `export_excel`) | Visit / inspection coverage per district, block or cluster; the PDF (23-11-2026) prints the summary and the level in view (or a cluster's schools) |
| Rating Report | `/reports/ratings` (+ `/export` Excel, `/export-pdf` PDF) | `RatingReportController@index` (+ `export`, `exportPdf`) | `reports/ratings/index`, `reports/ratings/pdf` | State Admin only (downloads: `export_excel`) | Summary, bands, sections, areas, schools latest vs previous; V13 **Map view** button (`/map?colour=rating`, ratings always shown to the State Admin); 23-11-2026 designed A4 PDF (KPI cards, band / section bars, best and lowest, area-wise and school-wise tables) |
| Map View | `/map` | `MapController@index` (+ JSON `areas/{level}`, `schools`) | `map/index` | `view_reports` | Drill-down map, colour by status/rating, district outline, directions; 23-11-2026 District (D) / Block (B) office markers from the Location Master office location, with the distance from each school to its offices  `/map#mapCard` (the dashboard's Map view button) opens with the map in view (25-11-2026) |
| Schedules calendar | `/schedules` | `ScheduleController@index` | `schedules/index` | `view_reports` | Plans by day for the officers in the area |
| Plan a day | `/schedules/plan` | `ScheduleController@plan` (+ JSON `plan/schools`, `POST /schedules`) | `schedules/plan` | `view_reports` | Choose officer, date, schools; drag to order; route estimate; save / submit / publish |
| Plan details | `/schedules/{key}` | `ScheduleController@show` | `schedules/show` | `view_reports` + `canView` | Visits, route map, approve/reject/cancel/reassign, skip a visit |
| Plan approvals | `/schedules/approvals` | `ScheduleController@approvals` | `schedules/approvals` | higher officers | Plans waiting for approval |
| Today's Schools (V14) | `/today` (+ `rows/{key}/skip`, `rows/{key}` delete) | `TodaySchoolsController@index` (+ `skip`, `remove`) | `today/index` | `conduct_inspections` | The officer's schools for the day (made once on the first view: assigned, carried over, then picked by priority); Start / Continue an open draft, Not visited with a reason, distance from the office |
| Assign schools (V14) | `/today/assign` (+ POST) | `TodaySchoolsController@assign` / `@storeAssign` | `today/assign` | `view_reports`, superiors only | A higher officer picks an officer and a day and ticks schools for them |
| Today's Schools Settings (V14) | `/today/settings` (+ PUT) | `TodaySchoolsController@settings` / `@updateSettings` | `today/settings` | `manage_holidays` | State default and district settings: on / off, daily limit, officer levels (a district chooses Block / Cluster only), priority rules, recent-visit and distance limits, weekly off |
| Holiday Calendar (V14) | `/holidays` (+ store, update, destroy, `template`, `import`) | `HolidayController` | `holidays/index` (+ partial `holidays/partials/day-modal`) | any (editing: `manage_holidays`) | Month grid and year list. **Click a date:** a popup lists its holidays; holiday managers add a holiday on that date or remove one there. Excel template / import |
| Notices & Orders (V14) | `/notices` (Inbox / Sent) | `NoticeController@index` | `notices/index` | any (Sent: `issue_notices`) | Inbox with New tags and filters (type, unread); Sent with read counts |
| New notice (V14) | `/notices/create` (+ POST) | `NoticeController@create` / `@store` | `notices/create` | `issue_notices` | Type (from the Notice Types master), ref no, date, subject, message, attachment, show until, audience levels and area, "Show in Latest Notice on the homepage" |
| Notice page (V14) | `/notices/{key}` (+ `acknowledge`, `attachment`, `home`, `withdraw`) | `NoticeController@show` | `notices/show` | audience or sender | Opening marks it read; Acknowledge; attachment; read report, Show on / Remove from homepage and Withdraw for the sender |
| Network Issues (V11) | `/reports/network-issues` (+ `/export-pdf`) | `NetworkIssueController@index` / `@exportPdf` | `reports/network/index` | `view_reports` | Schools that reported a network problem (NET_01), filtered list and PDF |

### 1.3 Administration pages
| Page | Route | Controller@method | Blade view | Role | Main functionality |
|---|---|---|---|---|---|
| Pending Approvals | `/admin/approvals` | `Admin\ApprovalController@index` | `admin/approvals/index` | `approve_registrations` | Registrations this approver may decide |
| Approval detail | `/admin/approvals/{key}` | `@show` (+ `approve`, `reject`) | `admin/approvals/show` | same | Applicant details, photo, ID proof; approve or reject with a reason |
| User List | `/admin/users` | `Admin\UserController@index` | `admin/users/index` | `manage_users` | Filters by name, level, status, district, block |
| User detail | `/admin/users/{key}` | `@show` (+ `update`, `status`, `transfer`, `reset-password`, `permissions`) | `admin/users/show` | `manage_users` (transfer/reset/grants: State Admin) | Edit, activate/deactivate, transfer, reset password, grants |
| Mobile Changes | `/admin/mobile-requests` | `Admin\MobileRequestController@index` / `decide` | `admin/mobile-requests` | `state_admin` | Approve or reject mobile changes |
| Location Master | `/admin/locations/{type?}` | `Admin\LocationController` | `admin/locations/index` | `manage_masters` | Districts, blocks, clusters: add and edit, codes, coordinates; Delete when unused (V13); Download menu: the filtered list as Excel or PDF with block / cluster / school counts (`{type}/export/{excel|pdf}`, 23-11-2026) |
| AC / PC Master | `/admin/constituencies` (`?tab=pc`) | `Admin\ConstituencyController` | `admin/constituencies/index` | `manage_masters` | Assembly (AC) and Parliamentary (PC) constituencies with their codes: list, search, add, Edit modal, make inactive, Delete when unused (V13) |
| School Master | `/admin/schools`, `/create`, `/{key}/edit` (+ `schools-export`, `destroy`, `schools-remove-district`) | `Admin\SchoolMasterController` | `admin/schools/index`, `admin/schools/form` | `manage_masters` | V13: list with district / block / cluster, AC, PC, management, category, area and type filters and a count; Excel export of the filtered list in the upload order; Export PDF of the filtered list (up to 3,000 schools, `schools-export-pdf`, 23-11-2026); Delete when the school has no inspection or schedule; form |
| Excel Upload | `/admin/imports` | `Admin\ImportController` | `admin/imports/index` | `manage_masters` | Templates (Schools sheet: school, location, details, lat / lng last; plus Clusters, AC list, PC list, Value lists), upload (All districts by default), batch history, error file; District report with **Remove schools of a district** (typed confirmation, V13) |
| Rating Settings | `/admin/rating` | `Admin\RatingController` | `admin/rating/index` | `manage_masters` | Weights, points, bands; save as a new version; recalculate |
| Login Monitoring | `/admin/login-report` | `Admin\LoginReportController` | `admin/login-report/index`, `row`, `user`, `tabs` | `state_admin` | Logins, refusals, devices, top refused mobiles; per-user history; Excel |
| Daily login report (V8) | `/admin/login-report/daily` (+ `/export`) | `Admin\LoginReportController@daily` / `@dailyExport` | `admin/login-report/daily` | `state_admin` | One row per active officer for a date: first sign-in, last activity, sign-ins, time online, refused attempts and the **current status** (Online now / Signed out / Timed out / Not signed in / Never signed in); Excel |
| Audit Log | `/admin/audit` (+ `purge`, `cache`, `archive/{file}`) | `Admin\AuditLogController` | `admin/audit` | `state_admin` | Filterable audit trail. V13 Clean up card: archive to Excel and delete entries older than N days, Clear cache, archive downloads (also automatic on the 1st of each month) |
| Notice Types (V14) | `/admin/notice-types` (+ store, update, `status`) | `Admin\NoticeTypeController` | `admin/notice-types/index` (+ `fields`) | `manage_masters` | Letter types with icon, colour and use count; add, edit (window), make inactive; never deleted |
| Clean Test Data (local server only) | `/admin/test-data` (+ `clean`) | `Admin\TestDataController` | `admin/test-data/index` (+ `table`) | `state_admin`, only when `TestDataCleaner::enabled()` (else 404) | Four parts (Inspections & Today's Schools, Inspection Schedules, Login log, Audit log) with their tables, row counts and SQL; typed DELETE per part |

### 1.4 Non-page endpoints used by the UI (JSON / files)
- `/locations/{type}`: cascading dropdowns, public, throttled.
- `/session/ping`: keeps the session alive.
- `/inspections/{key}/answers`, `progress`, `teachers`, `classrooms`, `funds`, `suggestions`: autosave and rows.
- `/inspections/{key}/evidence` and `/evidence/{key}`: upload, serve and delete files.
- `/subjects/suggest`: subject autocomplete.
- `/school-suggest`: school name / UDISE suggestions (`school-suggest.js`).
- `/notices/unread-count`: the unread count for the live counters (`notice-badge.js`; JSON, no-store).
- `/notices/{key}/attachment`, `/schedules/{key}/attachment`: private letter files.
- `/admin/users/{key}/photo` and `id-proof`: private files.
- `/register/kept/{field}`: preview of a kept upload.
- `/captcha/*`: CAPTCHA image.
- `/up`: health check.

---

## 2. Screenshots

| Screen | File |
|---|---|
| Login | `docs/screenshots/01-login.png` |
| Dashboard | `docs/screenshots/02-dashboard.png` |
| Find School | `docs/screenshots/03-find-school.png` |
| School page | `docs/screenshots/04-school-detail.png` |
| Steps 1–7 | `docs/screenshots/05-step1-condition.png` … `11-step7-photos.png` |
| Missing items | `docs/screenshots/12-missing-items.png` |
| Review | `docs/screenshots/13-review.png` |
| Report | `docs/screenshots/14-report.png` |
| Mobile step 1, mobile photos | `docs/screenshots/15-mobile-step1.png`, `16-mobile-photos.png` |

---

## 3. Layout, header, footer and navigation

- **Sidebar** (`aside.app-sidebar`):
  - SSA logo and "School Inspection · Samagra Shiksha, Assam".
  - A green "New inspection" button.
  - Since 25-11-2026 a **smaller menu** of 9 items: Dashboard, Today's Schools, Schools (tabs Find School | My Schools), Inspections (tabs My Inspections | In My Area), Reports (tabs Coverage | Rating | Network Issues), Map View, Notices & Orders (unread count), Inspection Schedules, Holiday Calendar. The tabs are the component `<x-page-tabs>` at the top of those pages.
  - Under *Administration*, the folding groups **Users**, **Masters** and **Admin Settings** (Bootstrap collapse, open while one of their pages is open). Everything is filtered by permission.
  - The note "Government of Assam".
  - Since 23-11-2026 it opens as an icon rail by default (the highlighted white Expand button opens it, and `localStorage` `si-sidebar = full` keeps it open). Hovering a rail icon shows a styled name popup (`.si-nav-tip`, with the badge count where there is one). On phones it is an off-canvas drawer.
- **Top bar:** menu button (mobile), a back button where a page sets one, the page title, an unread notices megaphone (V14), a pending approvals bell with count, and the account dropdown. The dropdown shows the avatar, designation, level, jurisdiction, My Profile, Change Password and Sign out.
- **Bottom navigation (phones):** Home · My Visits · "+" (new inspection) · Area · Profile.
- **Footer:** there is no global footer; the sidebar note acts as one.
- **Session:** an idle warning before the 20-minute expiry, with "Stay signed in".
- **Flash messages:** `partials/flash` (success, info, warning, error).
- **Dashboard (V14):** a **Latest Notice** strip above the banner slowly scrolls the title and date of the newest notice ticked for the homepage (pauses on hover, still with reduced motion); the right rail has the month calendar (click a date for its holidays; no Upcoming holidays list since 25-11-2026) and the Notices & orders card; a **Map view** button in the top bar opens `/map#mapCard`.
- **Live counters (V14):** the unread notice counts (sidebar, topbar, Inbox tab, dashboard card, banner pill) drop at once when a notice is opened and are checked again on Back, tab focus and every minute (`notice-badge.js`).
- **Masters / Admin Settings (25-11-2026):** Masters = Location, AC / PC, School, TLM, Notice Types, Excel Upload; Admin Settings = Rating, Coverage, Today's Schools Settings, Login Monitoring, Audit Log, and Clean Test Data on a local server.

## 4. Design system (`resources/scss/app.scss`)

| Token | Value |
|---|---|
| Primary (Samagra blue) | `#1565c0` |
| Success / accent (Samagra green) | `#1e9b4b` |
| Warning (Samagra orange) | `#f39223` |
| Danger | `#d93025` |
| Info | `#0288d1` |
| Secondary (slate) | `#64748b` |
| Page background / text | `#edf1f6` / `#334155`; headings `#1e293b` |
| Font | System stack: Segoe UI, Noto Sans, Roboto, Helvetica Neue, Arial |
| Base size | `0.875rem`; h1 1.375rem … h6 0.875rem; weights 500/600 |
| Radius | `.625rem` (sm `.5rem`, lg `.875rem`, xl `1.125rem`) |
| Shadows | Soft, two-layer (`$box-shadow-sm`, `$box-shadow`) |
| Icons | Bootstrap Icons |

**Components and patterns:**
- **Cards:** white on a grey page, `stat-card` with a toned icon.
- **Badges:** `<x-status-badge>` (draft = warning, submitted = success, unlocked = info, cancelled = secondary); `<x-rating-badge>` coloured by band (green, blue, yellow, orange, red).
- **Tables:** responsive; on phones lists become cards.
- **Forms:** touch targets ≥ 44 px, large Yes/No toggle buttons, coloured answer tones (good/mid/bad).
- **Wizard:** a stepper with completion %, one step per screen on mobile.
- **Empty states:** `<x-empty-state>`.
- **Avatars:** `<x-user-avatar>` (private photo or initials).
- **Pagination:** Bootstrap 5, 25 rows.
- **Filter bars (23-11-2026):** `<x-filter-buttons>` gives every filter the same Apply / Reset size (40 px, 44 px on phones). Report and list filters load inside the results section only (`filter-partial.js`): a spinner covers the results box, the URL updates and Back works.
- **School search suggestions (V14, 23-11-2026):** the school boxes on Inspections, My Inspections, My Schools and School Master suggest schools by name or UDISE Code as you type (`school-suggest.js`, `schools.suggest`, only schools in your jurisdiction); picking one filters the list.
- **Downloads (V14):** My Inspections offers PDF only. The Coverage Report Excel / PDF hold the selected view (the District-, Block- or Cluster-wise tab, or a cluster's schools), named on the buttons.
- **Plan Inspections map (V14):** school popups use the Map View card (status, stop and arrival time, distance, last inspected, Add / Remove, Directions); the route start is shown ("Route starts at: Block office …", or the centre of the schools when no office location is set). Offices without a location are not shown on the Map View.

**Responsive behaviour:** mobile-first; sidebar → drawer; bottom nav; the camera is full screen on phones; maps are full height.

---

## 5. Module-wise functionality summary

| Module | Pages | Status |
|---|---|---|
| Authentication | Login, Register, Forgot/Reset, Change password | Implemented |
| Dashboard | Dashboard | Implemented (personal, no charts) |
| Schools | Find School, My Schools (own jurisdiction list, 23-11-2026), School page | Implemented |
| Inspection | Steps 1–7, Review, Submitted, Report, PDF | Implemented |
| Reports | My Inspections, Inspections in Area (PDF), Coverage, Rating, Network Issues | Implemented |
| Rating (New Module 3) | Rating card, Rating Report, Rating Settings | Implemented |
| Map View (New Module 1) | Map View, school map, directions | Implemented |
| Scheduling (New Module 2) | Calendar, Plan, Plan details, Approvals | Implemented |
| User administration | Approvals, User List/detail, Mobile changes | Implemented |
| Masters | Location, AC / PC, School, TLM, Notice Types, Excel Upload | Implemented |
| Holiday Calendar (V14) | Holiday Calendar (date popup with add / remove), dashboard calendar | Implemented |
| Notices & Orders (V14) | Inbox / Sent, New notice, Notice page, Latest Notice strip, live counters | Implemented |
| Today's Schools (V14) | Today's Schools, Assign schools, Settings | Implemented |
| Test data (local only) | Clean Test Data | Implemented (never in production) |
| Monitoring | Login Monitoring, Audit Log | Implemented |
| Settings | `config/inspection.php` + `.env`; screens: Rating, Coverage and Today's Schools Settings | Partial (no general settings UI) |
| Help / user manual in app | None | Not implemented |
