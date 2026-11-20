# UI Pages and Application Modules

19-11-2026. The routes come from `php artisan route:list`, the views from the `view()` calls in the controllers, and the menu rules from `resources/views/components/layouts/app.blade.php`. Screenshots are in `docs/screenshots/`. They were taken at Modification V5 and show the inspection screens; newer screens (Map, Schedules, Rating) have no screenshot yet.

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
| School page | `/schools/{key}` | `SchoolController@show` | `schools/show` | `conduct_inspections` | Master data, map with directions, history, latest rating, Start inspection |
| Inspection step | `/inspections/{key}/step/{1-7}` | `InspectionController@step` | `inspections/step` (+ partials `stepper`, `question`, `camera`, `photo-viewer`, `fund-summary`) | officer of the draft | Wizard step with autosave, rows, photos, offline indicator |
| Review | `/inspections/{key}/review` | `InspectionController@review` | `inspections/review` | officer | Missing items by step, answers, confirm and Submit |
| Submitted | `/inspections/{key}/submitted` | `InspectionController@submitted` | `inspections/submitted` | officer / viewer | Confirmation with the inspection number |
| Inspection report | `/inspections/{key}` | `ReportController@show` | `inspections/show` (+ `step-answers`, `rating`) | officer or area viewer | Full report, photos, rating card, PDF button, unlock (State Admin) |
| Inspection PDF | `/inspections/{key}/pdf` | `ReportController@pdf` | `inspections/pdf` | same | DomPDF. Since V8 the device location at start/submit is not printed |
| My Inspections | `/my-inspections` | `ReportController@mine` | `inspections/index` (`mode=mine`) | `conduct_inspections` | Own inspections, filters, Excel |
| Inspections in Area | `/inspections` | `ReportController@area` | `inspections/index` (`mode=area`) | `view_reports` | Area list: district/block/cluster/officer/status/school/date filters, Excel |
| Excel export | `/export/{mine\|area}` | `ReportController@export` | — | `export_excel` | One column per question code |
| Rating Report | `/reports/ratings` | `RatingReportController@index` (+ `export`, `pdf`) | `reports/ratings/index`, `reports/ratings/pdf` | `view_reports` | Summary, bands, sections, areas, schools latest vs previous |
| Map View | `/map` | `MapController@index` (+ JSON `areas/{level}`, `schools`) | `map/index` | `view_reports` | Drill-down map, colour by status/rating, district outline, directions |
| Schedules calendar | `/schedules` | `ScheduleController@index` | `schedules/index` | `view_reports` | Plans by day for the officers in the area |
| Plan a day | `/schedules/plan` | `ScheduleController@plan` (+ JSON `plan/schools`, `POST /schedules`) | `schedules/plan` | `view_reports` | Choose officer, date, schools; drag to order; route estimate; save / submit / publish |
| Plan details | `/schedules/{key}` | `ScheduleController@show` | `schedules/show` | `view_reports` + `canView` | Visits, route map, approve/reject/cancel/reassign, skip a visit |
| Plan approvals | `/schedules/approvals` | `ScheduleController@approvals` | `schedules/approvals` | higher officers | Plans waiting for approval |

### 1.3 Administration pages
| Page | Route | Controller@method | Blade view | Role | Main functionality |
|---|---|---|---|---|---|
| Pending Approvals | `/admin/approvals` | `Admin\ApprovalController@index` | `admin/approvals/index` | `approve_registrations` | Registrations this approver may decide |
| Approval detail | `/admin/approvals/{key}` | `@show` (+ `approve`, `reject`) | `admin/approvals/show` | same | Applicant details, photo, ID proof; approve or reject with a reason |
| User List | `/admin/users` | `Admin\UserController@index` | `admin/users/index` | `manage_users` | Filters by name, level, status, district, block |
| User detail | `/admin/users/{key}` | `@show` (+ `update`, `status`, `transfer`, `reset-password`, `permissions`) | `admin/users/show` | `manage_users` (transfer/reset/grants: State Admin) | Edit, activate/deactivate, transfer, reset password, grants |
| Mobile Changes | `/admin/mobile-requests` | `Admin\MobileRequestController@index` / `decide` | `admin/mobile-requests` | `state_admin` | Approve or reject mobile changes |
| Location Master | `/admin/locations/{type?}` | `Admin\LocationController` | `admin/locations/index` | `manage_masters` | Districts, blocks, clusters: add and edit, codes, coordinates |
| School Master | `/admin/schools`, `/create`, `/{key}/edit` | `Admin\SchoolMasterController` | `admin/schools/index`, `admin/schools/form` | `manage_masters` | List and form |
| Excel Upload | `/admin/imports` | `Admin\ImportController` | `admin/imports/index` | `manage_masters` | Templates, upload, batch history, error file |
| Rating Settings | `/admin/rating` | `Admin\RatingController` | `admin/rating/index` | `manage_masters` | Weights, points, bands; save as a new version; recalculate |
| Login Monitoring | `/admin/login-report` | `Admin\LoginReportController` | `admin/login-report/index`, `row`, `user`, `tabs` | `state_admin` | Logins, refusals, devices, top refused mobiles; per-user history; Excel |
| Daily login report (V8) | `/admin/login-report/daily` (+ `/export`) | `Admin\LoginReportController@daily` / `@dailyExport` | `admin/login-report/daily` | `state_admin` | One row per active officer for a date: first sign-in, last activity, sign-ins, time online, refused attempts and the **current status** (Online now / Signed out / Timed out / Not signed in / Never signed in); Excel |
| Audit Log | `/admin/audit` | `Admin\AuditLogController@index` | `admin/audit` | `state_admin` | Filterable read-only audit trail |

### 1.4 Non-page endpoints used by the UI (JSON / files)
- `/locations/{type}`: cascading dropdowns, public, throttled.
- `/session/ping`: keeps the session alive.
- `/inspections/{key}/answers`, `progress`, `teachers`, `classrooms`, `funds`, `suggestions`: autosave and rows.
- `/inspections/{key}/evidence` and `/evidence/{key}`: upload, serve and delete files.
- `/subjects/suggest`: subject autocomplete.
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
  - Sections Inspection / Users / Masters, filtered by permission.
  - The note "Government of Assam".
  - It collapses to an icon rail with tooltips; the state is kept in `localStorage` (`si-sidebar`). On phones it is an off-canvas drawer.
- **Top bar:** menu button (mobile), a back button where a page sets one, the page title, a pending approvals bell with count, and the account dropdown. The dropdown shows the avatar, designation, level, jurisdiction, My Profile, Change Password and Sign out.
- **Bottom navigation (phones):** Home · My Visits · "+" (new inspection) · Area · Profile.
- **Footer:** there is no global footer; the sidebar note acts as one.
- **Session:** an idle warning before the 20-minute expiry, with "Stay signed in".
- **Flash messages:** `partials/flash` (success, info, warning, error).

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

**Responsive behaviour:** mobile-first; sidebar → drawer; bottom nav; the camera is full screen on phones; maps are full height.

---

## 5. Module-wise functionality summary

| Module | Pages | Status |
|---|---|---|
| Authentication | Login, Register, Forgot/Reset, Change password | Implemented |
| Dashboard | Dashboard | Implemented (personal, no charts) |
| Schools | Find School, School page | Implemented |
| Inspection | Steps 1–7, Review, Submitted, Report, PDF | Implemented |
| Reports | My Inspections, Inspections in Area, Excel | Implemented |
| Rating (New Module 3) | Rating card, Rating Report, Rating Settings | Implemented |
| Map View (New Module 1) | Map View, school map, directions | Implemented |
| Scheduling (New Module 2) | Calendar, Plan, Plan details, Approvals | Implemented |
| User administration | Approvals, User List/detail, Mobile changes | Implemented |
| Masters | Location, School, Excel Upload | Implemented |
| Monitoring | Login Monitoring, Audit Log | Implemented |
| Settings | `config/inspection.php` + `.env`; Rating Settings is the only settings screen | Partial (no general settings UI) |
| Help / user manual in app | None | Not implemented |
