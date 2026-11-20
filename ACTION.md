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

### 10. 2026-11-17 · Demo user seed
- Added `database/seeders/DemoUserSeeder.php`, called from `DatabaseSeeder` (skipped in production). It creates 20 officers with password `Demo@1234`:
  - 1 State, 2 District, 4 Block and 8 Cluster officers, one per demo location.
  - 3 pending registrations (cluster, block, district), 1 rejected and 1 inactive account.
  - "If permitted" grants: State → approve; North District → approve + manage users; Riverside Block → approve.
  - Each account gets its jurisdiction, a registration request record and a placeholder ID-proof image on the private disk.
- Ran it on `schoolinspection` twice: no duplicates (idempotent, upserts by mobile).
- Verified the approval queues match PRD 3.3: State Admin sees all 3 pending; the State officer sees the District applicant; North District sees the Block applicant (Hillview has no approver); Riverside Block sees the Cluster applicant. School scope: state 24, district 12, block 6, cluster 3. Cluster officer login → dashboard 200.
- Note: the State Admin mobile in the database is `8287619163` (changed after seeding); left as is.
- Demo credentials table added to `CLAUDE.md`.

### 11. 2026-11-17 · INCIDENT: test run wiped the MySQL database. Fixed and guarded
- **What happened:** after seeding the demo users I ran `php artisan test`. The config had been cached at 15:00 (`bootstrap/cache/config.php`, env=local, DB=mysql), so phpunit.xml's SQLite settings were ignored and `RefreshDatabase` reset the real `schoolinspection` tables. All data entered after the 14:40 reseed was lost; the only change known to have been lost was the State Admin mobile (`8287619163`).
- **Fix:**
  - `php artisan config:clear`, then `migrate:fresh --seed`: 21 users (admin + 20 demo), 24 schools.
  - Restored the State Admin mobile to `8287619163`.
- **Guard:** `tests/TestCase.php::beforeRefreshingDatabase()` now exits unless the connection is `sqlite` with `:memory:`. I verified it by re-running tests with the config cached: the run was refused and a probe table in MySQL was untouched.
- **Verification:** with the config cleared, `php artisan test` → 30 passed (280 assertions); MySQL still had 21 users afterwards.
- Added a "Testing safety" section to `CLAUDE.md`.

---
## Round 2: "Modification of the ApplicationV1.docx" + pending quick fixes (2026-11-17)
Plan approved by the user. Decisions:
- Photos: camera **+ gallery** upload.
- Step 1: compact info card + person met.
- Seating arrangement: number of benches/desks.
- Include the pending quick fixes.
- Safety: MySQL backup taken before changes (`scratchpad/backup-before-v2.sql`, 67 KB).

### 12. Questionnaire v2: schema
- `2026_11_18_000001_questionnaire_v2.php` (additive):
  - absentee `teacher_type` + `reason` (`status` made nullable);
  - new `inspection_classroom_observations` table;
  - fund columns `asg_*`, `mdm_*`, `govt_*`, `other_*` (DECIMAL lakh) plus UNIQUE(inspection, year);
  - `inspection_evidence.source` ENUM(camera, gallery).
- `2026_11_18_000002_widen_fund_financial_year.php`: `financial_year` VARCHAR(9) → 12 for "FY-2026-27" (found by the MySQL smoke test; SQLite does not enforce length).
- Both migrations ran on MySQL; `SHOW COLUMNS` confirmed the changes.
- `MasterValueSeeder` re-run: new photo categories `school`, `toilet_block`, `ict_smart`, `classroom_transaction` (v1 categories kept).
- `config/inspection.php`: mandatory photos = those 4; `max_suggestions` = 3.

### 13. Questionnaire v2: logic
- `QuestionnaireDefinition` (VERSION 2): 7 steps.
  - Step 1 `CND_01–26`. Conditionals: Internet → functional; ICT lab → functional; Tinkering lab → agency + functional.
  - Step 2 `TSI_01–13` (numbers).
  - Steps 3–5 are repeating sections.
  - Step 6 `GOVR_01–24`; reasons shown/required when the answer is No (SMC, free textbooks, uniform, MDM); monthly SMC sitting only if SMC = Yes.
  - Step 7 photographs.
- New models: `InspectionClassroomObservation`; `InspectionAbsentTeacher` (types, leave reasons); `InspectionFundPosition` (heads, balances, FY list).
- `InspectionValidator`:
  - Blocking: at least one classroom record, the 4 mandatory photos, no pending uploads.
  - Non-blocking warnings: teachers present > total; absentee rows fewer than implied absences.
- `InspectionController`: absentee (type/reason), classroom add/remove, fund upsert per year. A v1 draft moves to v2 when opened.
- `routes/web.php`: classroom routes.
- `ReportController::export`: all v2 answers plus absentee/unauthorised-leave/classroom counts, fund totals, gallery photo count.
- Report view and PDF render the absentee, classroom and fund (lakh) tables, "From gallery" badges, and a legacy-version notice for v1 records.

### 14. Gallery upload
- `camera.js`:
  - A shared `processImage()` (resize ≤1600 px, JPEG ≤ ~1 MB, stamp strip; gallery photos get "· From gallery").
  - `[data-gallery]` buttons plus a hidden file input; GPS read at upload, or the no-location reason is asked.
  - Uploaded with `source=gallery`; "Gallery" badge on tiles.
- Server: `source` validated (`in:camera,gallery`) and stored via `EvidenceStorage`; audit meta includes the source.

### 15. Theme, branding, layout
- `app.scss`:
  - Samagra Shiksha palette (blue `#1565c0` / green `#1e9b4b` / orange `#f39223`) and a themed background (soft blue/green glow + dot grid).
  - Base font 0.875rem; smaller headings.
  - New components: info card, row lists/forms, fund groups, photo slots, CTA card, summary strip, registration sections, level pills.
- Sidebar: 232 px with a deep-blue gradient, green active marker, SSA logo mark, both logos in the footer. Collapses to a 72 px icon rail (toggle saved in `localStorage`, applied before first paint).
- Login and guest layout: Govt. of Assam + Samagra Shiksha logos in the card header and in the side panel; gradient-bordered card.
- Registration: three compact sections (Personal / Jurisdiction / Sign-in), level pills, shorter hints, privacy notice collapsed. Validation unchanged.
- Dashboard: removed the hero and 4 stat boxes. Now: greeting, "Start new inspection" card, one summary strip (Today / This month / Drafts), "Continue draft" cards only when drafts exist, recent submissions.

### 16. Pending quick fixes
- Session expiry: `wizard.js` / `camera.js` detect 401/419, show "session expired, answers saved", and redirect to login (`window.siHandleAuthError`).
- Production hardening: `URL::forceScheme('https')` in production (AppServiceProvider); production checklist (debug off, secure cookie, ClamAV, mail, cache commands, Task Scheduler) appended to `.env.example`.
- User List: Block filter (blocks of the chosen district, or the manager's own district); filter columns rebalanced.

### 17. Verification
- `npm run build` OK; `php artisan view:cache` OK.
- `php artisan config:clear && php artisan test`: **32 passed (337 assertions)**. New/updated tests: v2 conditional rules, absentee/classroom/fund sections incl. per-year upsert, 4 mandatory photo categories, gallery source stored and shown, 401 JSON on expired session, unlock and resubmission with v2 answers.
- MySQL smoke test on `:8010` as demo inspector 9000000031: login page shows both logos; dashboard, schools, profile 200; start inspection; steps 1–7 200 (28 question blocks on step 1); conditional answer saved; absentee, classroom and fund rows saved; gallery photo stored with `source=gallery`; review, show, PDF and Excel 200.
- Cleanup: deleted my smoke-test inspection #2 and its photo. The user's draft #1 is untouched (it will continue on v2 when opened).
- Not verified: visual check in a browser and on a phone (to be done by the user).

### 18. 2026-11-17 · Registration page redesign (education theme, proper alignment)
- **Problem:** registration shared the login's split screen, so the form only had half the width. The 4 location dropdowns were squeezed and labels with inline hints made the rows uneven.
- **New layout** `resources/views/components/layouts/register.blade.php` (full width):
  - an official header strip (Govt. of Assam logo + title + Samagra Shiksha logo, tricolour-gradient underline);
  - a blue→green banner with a faint pattern of education icons (mortarboard, book, school, pencil…), the "Inspecting Officer Registration" heading and a 3-step guide;
  - a centred form card overlapping the banner, and a footer.
- **`auth/register.blade.php` rebuilt:**
  - Each section is a two-column block on desktop (title, icon and short description on the left; fields on the right), stacking on mobile.
  - Every field in a section uses the same 2-column grid (`col-md-6`, `g-3`); labels are uniform; hints moved under their inputs as `form-text`, so rows line up.
  - Locations are a 2×2 grid (State, District, Block, Cluster) and only the needed ones show.
  - Level cards show an icon, the level and typical posts (Directorate / DEEO-IS / BEEO-BRP / CRCC).
  - Mobile number has a +91 prefix; required `*` and "(optional)" markers.
  - Bottom bar: privacy consent on the left, "Sign in" and "Submit registration" on the right.
- **Styles:** `app.scss`: new `.reg-*` styles; the level pills were restyled.
- **Unchanged:** field names, IDs, `register.js` and `RegisterRequest` validation.
- **Verification:**
  - `npm run build` OK; views compile; `/register` returns 200.
  - `php artisan test` → 32 passed (337 assertions).
  - A live POST without a CAPTCHA was correctly rejected; no user created.
  - The visual check in the browser is left to the user.

### 19. 2026-11-17 · Login (and password pages) in the same design as registration
- Renamed the registration layout to a shared **`components/layouts/portal.blade.php`** (official header with both logos, education-icon banner, overlapping card, footer). `register.blade.php` now uses `<x-layouts.portal>`.
- **Login** (`auth/login.blade.php`):
  - Banner: "Welcome to School Inspection" with a 3-point guide (find school by UDISE, 7-step form, photos & submit).
  - Two cards: **Sign in** (mobile with +91 prefix, password with show/hide, forgot link, CAPTCHA after 3 failures) and **New officer?** (what to keep ready, approval note, "Register as officer" button, help line). They sit side by side on desktop and stack on mobile.
- **Forgot password** and **reset password** use the same portal layout with a single centred card.
- Removed the now-unused `components/layouts/guest.blade.php` and its `.auth-*` styles; added `.login-*` styles in `app.scss`.
- **Verification:**
  - `npm run build` OK; views compile.
  - `/login`, `/register`, `/forgot-password` and `/reset-password/{token}` → 200, all with the header and banner.
  - `php artisan test` → 32 passed (337 assertions).
  - Live login as demo officer 9000000031 → dashboard.
  - Visual check left to the user.

### 20. 2026-11-17 · ROLLED BACK: login & registration spacing/alignment change
- At the user's request, `git revert` of commit `765bceb` ("Tighten spacing and standardise field alignment"). The revert commit restores the login, registration and password pages to the design of entry 19.
- Files restored: `resources/scss/app.scss`, `auth/login.blade.php`, `auth/register.blade.php`, `auth/forgot-password.blade.php`, `auth/reset-password.blade.php`, `partials/captcha.blade.php` (and the entry for that change was removed from this log).
- History is kept; the change can be re-applied with `git revert <revert-commit>` if wanted later.
- **Verification:**
  - `npm run build` OK on the second run (the first run failed once, most likely a temporary file lock while replacing the old build; no code changes between runs).
  - Views compile; `/login`, `/register`, `/forgot-password` → 200.
  - `php artisan test` → 32 passed.

### 21. 2026-11-17 · ROLLED BACK: standard portal login
- At the user's request, `git revert` of commit `9235b16` ("Redesign login as a standard portal login"). Login is back to the entry-19 design (banner + Sign in card + New officer card); the portal layout's banner is mandatory again; the `.portal-*` styles were removed. Registration and the password pages are unchanged.
- History is kept (the change can be restored by reverting the revert commit).
- **Verification:**
  - Build OK; views compile.
  - `/login`, `/register`, `/forgot-password` → 200 with the banner, and no portal-login card on `/login`.
  - `php artisan test` → 32 passed.

### 22. 2026-11-17 · Portal header: bigger logos, clear "School Inspection" identity
- **What:** Enlarged header logos on all public pages (Govt. of Assam 52→76 px, Samagra Shiksha 34→58 px; 52/40 px on phones). Header title now reads **"School Inspection Management System"** in bold blue with a clipboard icon, with a small green "Government of Assam · Samagra Shiksha, Assam" line above and a one-line purpose line below (desktop). Title is now visible on phones too (short form "School Inspection").
- **Files:** `resources/views/components/layouts/portal.blade.php`, `resources/scss/app.scss` (`.reg-topbar`)
- **Verify:** `npm run build` OK, `view:cache` OK; /login, /register, /forgot-password return 200 with the new title.

### 23. 2026-11-17 · Removed portal header sub-line
- **What:** Removed the line "Online portal for inspection of schools by State, District, Block & Cluster officers" from the public portal header (user request).
- **Files:** `resources/views/components/layouts/portal.blade.php`
- **Verify:** `view:cache` OK; /login no longer contains the line.

### 24. 2026-11-17 · Registration: attractive identity-proof (ID card) upload
- **What:** Replaced the plain file input on the registration page with a full-width upload zone: gradient badge icon, "Click to choose a file or drag & drop", format/size chips (PDF · JPG/PNG · Max 2 MB). After choosing, it shows a preview card (image thumbnail or PDF icon, file name, size, green "ready to upload") with a remove button. Client-side checks for type and the 2 MB limit with an inline message; server validation (`RegisterRequest`) unchanged, field name `id_proof` unchanged.
- **Files:** `resources/views/auth/register.blade.php`, `resources/js/register.js`, `resources/scss/app.scss` (`.upload-*`)
- **Verify:** `npm run build` OK, `view:cache` OK, /register 200 with the new zone; `php artisan test` 32 passed (337 assertions).

### 25. 2026-11-17 · Bigger CAPTCHA field + Employee photo upload
- **CAPTCHA:** Image now shown at 64 px high (was 44 px; generated at 345×65) with a large refresh button; code input is 56 px high with larger, spaced text, plus a "Can't read it?" hint. On registration it takes a wider column. Applies to login (after failed attempts) and registration.
- **Employee photo (new field, required at registration):** passport-size photo, JPG/PNG ≤ 1 MB, min 100×100 px. Shown on the registration page next to Identity proof as a matching upload box with a round preview (camera or gallery on phones). Stored on the `private` disk (`employee-photos/`, random name); old photo deleted on re-application. Served only via `GET /admin/users/{user}/photo` (`admin.users.photo`) to the user themself, their approver, or a user manager within jurisdiction. New `<x-user-avatar>` component shows the photo (or initials if none) in the top bar, sidebar, profile, approvals list/review, user list/detail and mobile-change requests.
- **Upload JS** generalised: every `.upload-zone` reads `data-exts` / `data-max-kb`.
- **Files:** `database/migrations/2026_11_18_000003_add_photo_to_users.php` (new), `config/inspection.php` (`employee_photo`), `app/Http/Requests/RegisterRequest.php`, `app/Http/Controllers/Auth/RegisterController.php`, `app/Http/Controllers/Admin/ApprovalController.php` (`photo()`), `app/Models/User.php`, `routes/web.php`, `resources/views/components/user-avatar.blade.php` (new), `resources/views/partials/captcha.blade.php`, `resources/views/auth/register.blade.php`, `resources/views/{profile,admin/approvals/index,admin/approvals/show,admin/users/index,admin/users/show,admin/mobile-requests,components/layouts/app}.blade.php`, `resources/js/register.js`, `resources/scss/app.scss`, `tests/Feature/RegistrationAndLoginTest.php`, `CLAUDE.md` (domain rule 9)
- **Verify:** MySQL `migrate` OK (`users.photo_path` VARCHAR(255) NULL); `npm run build` OK; `view:cache` OK; /register and /login 200; `php artisan test` **33 passed (346 assertions)** incl. new test (photo required, image only, visible to self, 404 for unrelated officer). Existing users have no photo and show initials.

### 26. 2026-11-17 · Aligned Employee photo and Identity proof boxes
- **What:** The two upload boxes now sit in equal columns (`col-md-6` each, was 5/7) and stretch to the same height (min 112 px). Both icons and preview thumbnails are 64 px (52 px on phones); photo stays round, ID proof square. Titles and helper lines shortened to the same length so they line up: "Passport-size photograph" / "Department ID card / posting order", both with "Click to choose or drag & drop". Long names are cut off with "…" instead of wrapping. Phones still stack them.
- **Files:** `resources/views/auth/register.blade.php`, `resources/scss/app.scss`
- **Verify:** `npm run build` OK, `view:cache` OK, /register 200.

### 27. 2026-11-17 · My Profile: upload / change employee photo
- **What:** My Profile now has a round camera button on the profile photo. Choosing a photo (camera or gallery on phones) shows a preview with **Save photo** / **Cancel**; type (JPG/PNG) and 1 MB limit checked in the browser and again on the server (same rules as registration, min 100×100 px). Officers without a photo (e.g. older accounts) see a "No photo yet" prompt. The old file is deleted from the private disk after a successful replace; audited as `photo_added` / `photo_changed`. Avatar URLs now carry a version tag (`?v=`) so the new photo shows immediately everywhere instead of a cached old one.
- **Files:** `app/Http/Controllers/ProfileController.php` (`updatePhoto`), `routes/web.php` (`POST /profile/photo`, `profile.photo`), `resources/views/profile.blade.php`, `resources/views/components/user-avatar.blade.php`, `resources/js/app.js`, `resources/scss/app.scss` (`.profile-photo-*`), `tests/Feature/RegistrationAndLoginTest.php`
- **Verify:** `npm run build` OK, `view:cache` OK; `php artisan test` **34 passed (357 assertions)** incl. new test (wrong type rejected, upload, replace deletes old file, audit entry, photo served).

### 28. 2026-11-17 · Registration: Employee photo and Identity proof on one line
- **What:** Full name, Designation and Employee ID now share one row (`col-md-4` each, were `col-md-6`), so Employee photo and Identity proof sit together on the next row (`col-md-6` each) instead of Employee photo sitting beside Employee ID and Identity proof wrapping alone. Phones still stack all fields.
- **Files:** `resources/views/auth/register.blade.php`
- **Verify:** `view:cache` OK, /register 200.

### 29. 2026-11-17 · Sidebar cleanup, standard top-right account header, elegant dashboard
- **Sidebar:** Removed the logo strip and the profile/sign-out footer from the bottom. Added an orange **New inspection** button under the brand (inspectors only), larger brand logo, nav icons in rounded tiles, gradient active item with a green icon tile, soft glow accents, and a small "Government of Assam" footer line. Collapsed icon rail still works (labels/footer hidden).
- **Header (top-right, standard portal style):** bell icon with count when the user has pending approvals; account button with photo + name + designation (photo only on phones). Dropdown: photo, name, designation, level badge, jurisdiction, mobile, then My Profile, Change Password, Sign out.
- **Dashboard:** gradient welcome banner (time-based greeting, date, jurisdiction, white **Start inspection** button); four KPI cards only: Inspected today, This month, Drafts in progress, and Total submitted (or **Pending approvals** for approvers); two compact panels: Recent submissions (last 5) and "Continue where you left off" drafts (last 3) with a progress ring. Old pending-approval banner and summary strip removed (now a KPI card + bell). KPI cards are only links for users who can open My Inspections. Unused `.quick-actions/.cta-card/.summary-strip` styles removed.
- **Files:** `resources/views/components/layouts/app.blade.php`, `resources/views/dashboard.blade.php`, `app/Http/Controllers/DashboardController.php` (`total` stat), `resources/scss/app.scss`
- **Verify:** `npm run build` OK, `view:cache` OK; `php artisan test` 34 passed (357 assertions); live MySQL: logged in as 9000000031 (Cluster) and 9000000001 (State approver) → dashboard 200 with the expected KPI cards (Total submitted vs Pending approvals), account header present, old sidebar footer/logos gone; /profile 200.

## 30. Lighter, standard government-portal typography (2026-11-17)
- Font: replaced Inter web font with the standard system stack `Segoe UI, Noto Sans, Roboto, Helvetica Neue, Arial` (no font download).
- Weights: bold capped at 600 (`fw-bold`, headings, titles, KPI values); everything else that was 550–650 is now 500 (`fw-semibold`, buttons, labels, badges, table heads). 800/750/700 removed.
- Colours softened: body text #334155, headings #1e293b, form labels #475569. Negative letter-spacing removed.
- Sizes eased: portal title 1.3rem, dashboard greeting 1.25rem, KPI value 1.4rem, stat value 1.5rem, register hero 1.35rem.
- Files: `resources/scss/app.scss`.
- Verified: `npm run build` OK, `view:cache` OK, tests 34 passed.

## 31. District/Block latitude & longitude + standard clean background (2026-11-17)
- Migration `2026_11_18_000004_add_coordinates_to_districts_and_blocks` adds nullable `latitude`/`longitude` DECIMAL(10,7) to `districts` and `blocks` (run on MySQL).
- `District`, `Block` models: fillable + float casts.
- Location Master (`Admin\LocationController`, `admin/locations/index.blade.php`): Latitude/Longitude fields in Add and Edit for Districts and Blocks (optional, both-or-neither, range-checked); list shows a coordinate chip that opens the map, or "No coordinates".
- Excel upload (`MasterImportService`): optional `latitude`, `longitude` columns in the location template; validated for district/block rows; empty cells keep existing values; old 5-column files still import. Error file includes the new columns.
- Background: removed the glow/dot-grid pattern; body and registration page now use a plain light grey (#f5f7fa) with a faint blue wash at the top (`resources/scss/app.scss`).
- Test: `test_district_and_block_coordinates_via_form_and_excel`.
- Verified: build OK, view:cache OK, tests 35 passed.

## 32. Demo accounts panel on the login page (2026-11-17)
- `LoginController::show` passes active demo officers (mobile `90000000xx`, status Active) grouped by level; empty when `APP_ENV=production` or `DEMO_LOGINS=false` (new `config('inspection.demo_logins')`).
- `auth/login.blade.php`: "Demo accounts" card under the sign-in cards: password note (`Demo@1234`), officers grouped State / District / Block / Cluster with name, designation, mobile; tapping one fills mobile + password and focuses Sign in (or CAPTCHA). State Admin is not listed.
- Styles `.demo-*` in `resources/scss/app.scss`; `DEMO_LOGINS` added to `.env.example`; CLAUDE.md note.
- Test: `test_login_page_lists_active_demo_accounts_outside_production_only`.
- Verified: build OK, tests 36 passed; live `/login` lists 15 active demo officers.

## 33. Inspection form redesign: cleaner, friendlier, mobile-first (2026-11-17)
- Step card: new header (step icon, "Step X of 7", title, one-line guidance per step).
- Questions (`partials/question.blade.php`): numbered circle turns into a green tick once answered; short choice lists (Yes/No, Satisfactory/Unsatisfactory, ≤3 options) sit beside the question on tablet/desktop and become an equal-width segmented control on phones; number questions get − / + buttons (44 px) with auto-save; inline `style` widths replaced by classes.
- Visual group headings (presentation only, codes unchanged): Step 1 Person met / Building & basic facilities / Electricity & classrooms / Digital learning & labs; Step 2 Students / Teachers & tutors / Trained-untrained; Step 6 Teaching & SMC / Assembly & entitlements / Cleanliness, MDM & health / Safety & sports / Records & administration; Suggestions.
- Repeating sections (absentee, classroom, fund): list heading, titled add-form panel, full-width primary button on phones, segmented TLM/participation choices.
- Photos step: info note, cleaner required-photo slots (title + "Required · at least N"), Camera/Gallery buttons full-width on phones.
- Stepper: lighter pill track; on phones only step numbers show, with the label for the current step; thinner progress bar. Action bar: compact status ("N left in this step" / "Step complete").
- Files: `inspections/step.blade.php`, `inspections/partials/{question,stepper}.blade.php`, `resources/js/wizard.js` (answered state, − / + steppers, status text), `resources/scss/app.scss`.
- Verified: build OK, view:cache OK, tests 36 passed; live MySQL: all 7 steps of a draft return 200 as demo inspector 9000000031.

## 34. Inspection form: compact, aligned, no horizontal scrollbar (2026-11-17)
- Removed the page-wide horizontal scrollbar: `html, body, .app-main { overflow-x: clip }` (keeps sticky headers working); the step track no longer scrolls: steps share the width (numbers only on phones/tablets, label for the current step).
- Smaller type: question text .8125rem, choices .8rem, inputs .8125rem, step title .95rem, intro .72rem; smaller step header, stepper, action bar.
- Tighter spacing: question rows .6rem, group headings as light tinted bars, smaller form panels, photo slots and info card.
- Alignment: on tablet/desktop all short answers sit in one fixed 230 px column (numbers in a 150 px column), so buttons line up down the page; on phones Yes/No and number fields stay on the question's line (132 px), longer choices go below.
- Selected answers now filled (blue / green / orange / red with white text). Touch targets stay ≥ 42 px on touch screens (`pointer: coarse`).
- Files: `resources/scss/app.scss`, `inspections/partials/question.blade.php` (`q-inline-2`, `q-num`), `inspections/step.blade.php`.
- Verified: build OK, view:cache OK, tests 36 passed; live: all 7 steps 200.

## 35. Smooth dropdowns, easier fund entry, friendlier review, form validation messages (2026-11-17)
- **Dropdowns:** new enhanced select (`resources/js/app.js`, `[data-enhance-selects]`): styled button + animated option panel (opens upward near the bottom of the screen), check mark on the chosen option, keyboard support (arrows / Enter / Esc / type-to-jump), search box for lists > 8, bottom sheet on phones. The native `<select>` stays in the form (value, FormData, validation). Bootstrap dropdown menus (account menu etc.) now fade/slide in.
- **Fund step (5):** year chooser is now a row of year chips (saved years show a green tick); amounts in a table (Received / Expenditure / live Balance per head + totals; cards with labels on phones); warning when expenditure > received (non-blocking); Clear button; Save button shows "Save FY 2025-26" / "Update FY …" / "Saved"; values stay on screen after saving; saved years list with pills and Edit/Delete.
- **Validation:** forms use inline messages under each field (client-side required/format checks + server 422 errors mapped to fields), cleared as soon as the field is corrected; friendly server messages and field names (`InspectionController::ROW_MESSAGES/ROW_ATTRIBUTES`); fund amounts: max 2 decimals, not negative, ≤ 99,999 lakh, at least one amount per year. Questions: number/text checked before saving, server errors shown under the question.
- **Leaving a step with unanswered items:** dialog "N item(s) still to fill" listing them, with "Show me" (highlights and scrolls to the questions) or "Continue anyway". Links from the review page (`#q-CODE`) highlight the question.
- **Review page:** status banner with progress ring ("Ready to submit" / "N items still to complete"); missing items grouped by step with direct "Fix" links; collapsible per-step summary showing every answer, absentees, classrooms, fund years, suggestions and photo counts, with Edit buttons and Expand all; confirmation card; Submit enabled only after ticking; "Getting location… / Submitting…" states. Back link now goes to step 7.
- Files: `resources/js/{app,wizard}.js`, `resources/scss/app.scss`, `inspections/{step,review}.blade.php`, `InspectionController`.
- Tests: fund/absentee/classroom validation messages and review page assertions added to `InspectionFlowTest`. Verified: build OK, view:cache OK, tests 36 passed (395 assertions); live: steps 3/4/5/7 and review 200, fund without amounts returns the friendly 422 message.

## 36. Cleaner listings, page vs. card contrast, Find School with live (AJAX) search (2026-11-17)
- **Theme contrast:** page background is now a cool grey (#edf1f6) with a faint blue wash; cards, forms and filter bars stay white with a soft border/shadow, so forms clearly stand out from the page.
- **Listings (all tables / list pages):** lighter uppercase table headers, compact cells, row hover tint, no border on the last row, smaller action buttons; list rows with hover tint and a blue edge marker; rounded white filter bars; compact stat cards (stack on phones, lift on hover); pagination as rounded buttons; smaller empty states. CSS only, no markup changes on those pages.
- **Find School:** new header card with a large search box (clear button, spinner, tips) and filter row. **Live search**: results update 300 ms after typing (2+ letters of the name, or 3+ digits of the UDISE Code) via `fetch` with `X-Requested-With`; earlier requests are cancelled; skeleton loader; URL updated (`history.replaceState`); session-expiry reloads to login. Enter with the full 11-digit code still opens the school directly. District/Block filter changes reload the page (to refresh the lists below them); Cluster filter searches live.
- Results (`schools/_results.blade.php`, shared by page and AJAX): school cards with the matched text highlighted, UDISE chip, cluster/block, category, "Your inspection today", "Last inspected dd Mon yyyy" / "Not inspected yet", "Closed".
- `SchoolController::index`: AJAX returns `{html, total}` (no redirect); adds `mine_today` (withExists) and `last_inspected_on` (withMax). Still scoped by `JurisdictionService`.
- Test: `test_find_school_live_search_returns_scoped_results`. Verified: build OK, view:cache OK, tests 37 passed (404 assertions); live: /schools 200, AJAX by name and UDISE prefix return results, exact UDISE redirects to the school.

## 37. White form surfaces (2026-11-17)
- All cards, forms, filter boxes, the wizard header and inner form panels (teacher/classroom add forms, remarks boxes, review confirm box, Find School filters, empty states) now use a clean white background with a hairline border and soft shadow, so they stand out from the grey page.
- Inputs and selects: white with a clear grey border, darker border on hover, soft blue focus ring; disabled/read-only fields light grey. Info hints keep their light blue tint.
- Files: `resources/scss/app.scss`.
- Verification: `npm run build` OK.

## 38. Internet / ICT "is Functional" greyed out when the facility is No (2026-11-17)
- New questionnaire rule `enable_if`: the question stays visible but is disabled (greyed out, not answerable, not required) unless its parent answer matches. Applied to CND_18 "Internet is Functional" (needs CND_17 Internet Facility = Yes) and CND_20 "ICT Lab is Functional" (needs CND_19 ICT Lab = Yes). Codes unchanged.
- Server: `InspectionValidator::enabled()`; disabled questions are never required or listed as missing; `saveAnswer` stores no value for a disabled question and clears the child answer when the parent changes to No.
- Screen: question partial wraps the input in a `fieldset` disabled by default rendering; `wizard.js` toggles it live and clears the shown answer; SCSS `.q-block.is-disabled`.
- Files: `app/Questionnaire/{QuestionnaireDefinition,InspectionValidator}.php`, `app/Http/Controllers/InspectionController.php`, `resources/views/inspections/partials/question.blade.php`, `resources/js/wizard.js`, `resources/scss/app.scss`, `tests/Feature/InspectionFlowTest.php`.
- Verification: `npm run build`, `view:cache` OK; `php artisan test` 37 passed (413 assertions).

## 39. Edit absentee/classroom rows, nicer lists, fund sources in colour (2026-11-17)
- Fix: absentee and classroom rows could only be added or removed. New `PUT /inspections/{i}/teachers/{teacher}` and `PUT /inspections/{i}/classrooms/{classroom}` (`updateAbsentTeacher`, `updateClassroom`), sharing validation with add (`teacherData`, `classroomData`); row must belong to the inspection (404 otherwise). Rows now return `values` and `update_url`.
- Lists: each row is a card with initials / class badge, numbered title, coloured chips (type, leave reason, TLM Yes/No, participation), remarks line, and Edit + Remove buttons; a count badge sits beside each section heading; friendlier empty states.
- Add form: "Edit" loads the row into the form (title, icon and button change to "Update …", Cancel button, the row is marked "Editing").
- Fund step: heads grouped by source with coloured headers and row markers: Samagra Shiksha (SSA) blue (Annual School Grant, MDM Fund), Government green, Other Source orange. Saved-year cards show each head with an SSA / Govt / Other tag in the same colours. `InspectionFundPosition::SOURCES`, `SHORT`, `source()`.
- Files: `app/Http/Controllers/InspectionController.php`, `routes/web.php`, `app/Models/{InspectionAbsentTeacher,InspectionClassroomObservation,InspectionFundPosition}.php`, `resources/views/inspections/step.blade.php`, `resources/js/wizard.js`, `resources/scss/app.scss`, `tests/Feature/InspectionFlowTest.php`.
- Verification: `npm run build`, `view:cache` OK; `php artisan test` 37 passed (427 assertions); live steps 3, 4, 5 return 200.

## 40. Project pushed to GitHub (2026-11-18)
- Added remote `origin` = https://github.com/nathgunajit/school_inspection.git; `main` pushed and set to track `origin/main`.
- Stopped tracking the Word lock file `Documents/~$ODUCT REQUIREMENTS DOCUMENT.docx`; `.gitignore` now ignores Office lock files (`~$*`).
- `.env`, `vendor/`, `node_modules/` and `storage/app/private/*` stay ignored (no secrets or uploads pushed).
- Files: `.gitignore`, `ACTION.md`.
- Verification: `git push -u origin main` succeeded; `git status` up to date with `origin/main`.
## 41. Review page: photo gallery with viewer and clear question/answer display (Modification V2 items 1, 2) (2026-11-18)
- Photos (step 7): grouped by category with a count and a "Required · done / missing" marker for the 4 mandatory categories; 4:3 thumbnails (object-fit cover), caption, "From gallery" and geo-tag labels. Tapping opens a full-size viewer (modal) with previous / next, arrow keys and swipe.
- Answers: each question is a row with its number; the answer is a coloured pill (Yes/Satisfactory green, No/Unsatisfactory red, others amber/blue), numbers as large figures, text answers as a quoted block; "Not answered" (required) vs "Not applicable".
- Step 2 shown as figure tiles plus automatic totals (Total students = Present + Absent; Total teachers; Teachers absent = Total − Present).
- Steps 3 and 4 shown as the same cards as the wizard lists; step 5 as a year-wise fund summary (new partial `inspections/partials/fund-summary`) in ₹ lakh with Indian digit grouping, per-head lines by source colour and an all-years total.
- Helpers: `QuestionnaireDefinition::tone()` (shared with the question partial), `::totalStudents()`; `InspectionFundPosition::lakh()` (display only, stored values unchanged).
- Files: `resources/views/inspections/review.blade.php`, `resources/views/inspections/partials/{fund-summary,question}.blade.php`, `app/Questionnaire/QuestionnaireDefinition.php`, `app/Models/InspectionFundPosition.php`, `app/Http/Controllers/InspectionController.php` (photo categories for review), `resources/scss/app.scss`, `tests/Feature/InspectionFlowTest.php`.
- Verification: `npm run build`, `view:cache` OK; `php artisan test` 39 passed (520 assertions), 2 new (review page content, lakh format).

## 42. Readable input/placeholder sizes and responsive School Governance layout (Modification V2 items 3, 5, 9) (2026-11-18)
- Fields: one font size for every input and select (15px desktop, 16px phones, which also stops iOS zoom on focus); entered values darker and weight 500.
- Placeholders: same standard size, lighter (#9aa5b5) and normal weight, so they are clearly different from entered values.
- Numbers (question steppers, fund amounts): 17px, bold, tabular figures, 44px touch height.
- Governance (and all choice questions): option buttons wrap long labels instead of overflowing; the answer column is wider (up to 280px) on tablet/desktop. On phones only Yes/No stays beside the question; longer pairs (Satisfactory/Unsatisfactory, Conventional/Non-Conventional) and 3-option questions go full width below it. Textareas and suggestion inputs take the full width.
- `partials/question.blade.php`: new `$compact` rule (`q-inline-2` only for 2 options of ≤ 4 characters). Before, Satisfactory/Unsatisfactory was squeezed into 132px on phones.
- Files: `resources/scss/app.scss`, `resources/views/inspections/partials/question.blade.php`, `ACTION.md`.
- Verification: `npm run build`, `view:cache` OK; `php artisan test` 39 passed.

## 43. Automatic student and teacher totals on step 2 (Modification V2 items 6, 7) (2026-11-18)
- Step 2: a "Total students" panel after Absent shows Total = Present (TSI_01) + Absent (TSI_02) and the attendance %; a "Total teachers" panel after TSI_09 shows Total = Regular + Contractual + SSA + Provincialised + Newly accorded tutors (TSI_04–08), Present (TSI_09) and Absent = Total − Present, turning red when Present > Total.
- Both update live while typing and with the − / + buttons. Display only: no new question codes, nothing extra is stored.
- The same totals appear on the review page, the report page (`show`) and in the PDF (server side, `QuestionnaireDefinition::totalStudents()` / `totalTeachers()`).
- Files: `resources/views/inspections/{step,show,pdf}.blade.php`, `resources/js/wizard.js`, `resources/scss/app.scss`, `tests/Feature/InspectionFlowTest.php`.
- Verification: `npm run build`, `view:cache` OK; `php artisan test` 40 passed (527 assertions).

## 44. Larger fund figures in ₹ lakh, balance card and year-wise fund table (Modification V2 items 4, 8, 11) (2026-11-18)
- Figures: per-head balance and totals larger (18px/17px, bold, tabular), positive balance green, negative red with "−"; all amounts shown as ₹ … L with Indian digit grouping (e.g. 12,34,567.00). Stored values and the lakh inputs are unchanged.
- Balance card: highlighted "Balance for FY …" panel (Received / Expenditure / Balance) above Save; turns red when the balance is negative.
- Multiple financial years: new "Year-wise summary" table under the saved years (one row per FY plus an "All years" total); the open year is highlighted and tapping a row opens it. Switching year with unsaved amounts asks before discarding them, so each year is kept separately.
- Report page (`show`) now uses the shared `fund-summary` partial; PDF shows ₹ lakh in Indian format, a per-year total row and a year-wise summary when there is more than one year. Legacy (v1) fund rows are shown with their original amounts.
- Files: `resources/views/inspections/{step,show,pdf}.blade.php`, `resources/views/inspections/partials/fund-summary.blade.php`, `resources/js/wizard.js`, `resources/scss/app.scss`, `tests/Feature/InspectionFlowTest.php`.
- Verification: `npm run build`, `view:cache` OK; `php artisan test` 41 passed (540 assertions).

## 45. Subject master and AJAX subject autocomplete in Classroom Performance (Modification V2 item 10) (2026-11-18)
- Subject master: new `master_values` type `subject` (migration `2026_11_18_000005_add_subject_master_type` extends the type enum; existing rows untouched) seeded with 52 subjects taught in Assam Government schools (Assamese, English, Hindi, Bengali, Bodo, MIL, Mathematics, EVS, General Science, Social Science, Physics, Chemistry, Biology, History, Geography, Political Science, Economics, Logic & Philosophy, Education, Accountancy, Computer Science, Health & Physical Education, Work Education, Vocational Education, …). `MasterValueSeeder` stays idempotent.
- Endpoint `GET /subjects/suggest?q=` (`subjects.suggest`, officers with `conduct_inspections`, throttle 120/min): up to 10 names, names starting with the typed text first.
- Step 4 Subject field: suggestions appear while typing (250 ms debounce, cached per query, no page reload), matched text in bold, keyboard support (↑ ↓ Enter Esc) and 44px touch rows. Choosing fills the field, which stays editable; a "Not in the list? Keep … as typed" hint confirms free text is allowed (validation unchanged: `string|max:100`).
- Deploy: `php artisan migrate` then `php artisan db:seed --class=MasterValueSeeder`.
- Files: `database/migrations/2026_11_18_000005_add_subject_master_type.php`, `database/seeders/MasterValueSeeder.php`, `app/Http/Controllers/InspectionController.php` (`subjectSuggestions`), `routes/web.php`, `resources/views/inspections/step.blade.php`, `resources/js/wizard.js`, `resources/scss/app.scss`, `tests/Feature/InspectionFlowTest.php`.
- Verification: `php artisan test` 42 passed (552 assertions); local MySQL migrated and seeded (52 subjects); `npm run build` OK.

## 46. Standard 1rem font size for numeric inputs and placeholders (2026-11-18)
- Request: numeric values and placeholders were too large; use the standard `font-size: 1rem`.
- Numeric inputs (question −/+ steppers, fund amounts): 1.0625rem bold → 1rem, weight 500 (same as other fields), tabular figures kept.
- Placeholders and all fields: 1rem on every screen size (desktop was 0.9375rem). Placeholders stay lighter and normal weight.
- Display figures (totals, balances, review page) unchanged.
- Files: `resources/scss/app.scss` (`$field-fs`, `$num-fs`), `ACTION.md`.
- Verification: `npm run build` OK; `php artisan test` 42 passed.

## 47. Clearer form step tabs, all visible on every screen size (2026-11-18)
- Request: the form tabs should have easier-to-understand text and all of them should be shown on every screen.
- Tab names (new `tab` key in `QuestionnaireDefinition::steps()`, presentation only; step keys/codes unchanged): School Condition, Students & Teachers, Absent Teachers, Classroom Observation, Fund Details, School Governance, Photographs, Review & Submit.
- Each tab shows its number (✓ when done) and a status line: "You are here", "Done", "In progress · n%" or "Not started"; Review shows "Ready to submit" / "Check & submit". Status and the "n of 7 steps done" counter update live after each saved answer.
- Responsive, no horizontal scrolling and no hidden tabs: ≥1200px one row with number beside the name; 768–1199px one row with number above the name; phones two rows of four tiles (name kept, status by colour, current step labelled). On phones the tab bar scrolls away instead of staying fixed, to leave room for the form.
- Files: `resources/views/inspections/partials/stepper.blade.php`, `app/Questionnaire/QuestionnaireDefinition.php`, `resources/js/wizard.js`, `resources/scss/app.scss`, `tests/Feature/InspectionFlowTest.php`.
- Verification: `npm run build`, `view:cache` OK; `php artisan test` 42 passed (556 assertions).

## 48. Fix review page "Expand all" and photo viewer (Modification V3 item 8) (2026-11-18)
- Cause: the review page script was inline (`@push('scripts')`) and ran before the Vite module that sets `window.bootstrap`. The photo viewer called `bootstrap.Modal` at once; when photos were present this threw and the Expand-all handler was never attached.
- Fix: the script moved to a new Vite entry `resources/js/review.js` (imports `Collapse` and `Modal` from the shared bootstrap chunk). Expand all / Collapse all opens or closes every step; the button label follows the real state, also when steps are opened by hand (`shown.bs.collapse` / `hidden.bs.collapse`). Confirm + submit with location and the photo viewer work as before; `canSubmit` is passed as `data-can-submit` on the form.
- Also: `.review-confirm.is-checked` class as a fallback for `:has()` on older browsers.
- Files: `resources/js/review.js` (new), `resources/views/inspections/review.blade.php`, `vite.config.js`, `resources/scss/app.scss`, `Documents/Modification of the ApplicationV3.docx` (added).
- Verification: `npm run build` (review chunk shares bootstrap with app.js), `view:cache` OK; `php artisan test` 42 passed.

## 49. 20-minute session with idle warning; login locked after 3 wrong attempts for 20 minutes (Modification V3 items 5, 6) (2026-11-18)
- Session: `SESSION_LIFETIME=20` in `.env` and `.env.example` (was 30). Every page load, auto-save and AJAX request refreshes it.
- Idle warning (`app.js`, layout meta tags `session-lifetime`, `session-ping`, `session-expired-url`): 2 minutes before the end an orange panel counts down "You will be signed out in m:ss … Saved answers are kept" with **Stay signed in** (calls new `GET /session/ping`, auth, 204, throttle 30/min). Last activity is shared across tabs via localStorage. At 20 minutes the page goes to `/login?expired=1`, which shows "You were signed out after 20 minutes without activity".
- Login: `config/inspection.php` `login` now `captcha_after_failures` 2, `lock_after_failures` 3, `lock_minutes` 20 (env `LOGIN_CAPTCHA_AFTER`, `LOGIN_LOCK_AFTER`, `LOGIN_LOCK_MINUTES`, documented in `.env.example`). The wrong-password message says "After 3 wrong attempts the account is locked for 20 minutes" (same text whether or not the mobile exists, so accounts cannot be guessed).
- Tests: lock after 3 attempts, still locked at 19 min, open after 21 min; CAPTCHA from the 3rd attempt; session lifetime 20, meta tags, ping (204 signed in, redirect when signed out), expired notice. Replaces the old 5-attempt test.
- Files: `config/inspection.php`, `.env.example` (and local `.env`), `app/Http/Controllers/Auth/LoginController.php`, `routes/web.php`, `resources/views/components/layouts/app.blade.php`, `resources/js/app.js`, `resources/scss/app.scss`, `tests/Feature/RegistrationAndLoginTest.php`.
- Verification: `npm run build` OK; `php artisan test` 44 passed (579 assertions).

## 50. Block duplicate inspections of a school: own draft reopened, other officers' drafts and same-month completions blocked (Modification V3 items 3, 4) (2026-11-18)
- Decisions (user): a school completed by another officer is blocked for the **rest of that calendar month**; **both** own and other officers' drafts block a new start.
- New `app/Services/InspectionStartGuard.php`, rules in order (cancelled inspections never count):
  1. own open draft (draft/unlocked, any date) → reopened ("Your draft for this school from 12 Nov 2026 has been reopened"), never a second one;
  2. own submission today → that report (rule 5 unchanged);
  3. another officer's open draft → blocked: "This school is being inspected by Name (Designation). Their draft was started on …";
  4. submitted/unlocked by another officer this calendar month → blocked: "already inspected by Name (Designation) on … (SI/…). It can be inspected again from 1 Dec 2026".
- `InspectionController::start()` checks the guard inside a transaction with `lockForUpdate` on the school row (two officers cannot start the same school at the same moment); blocked attempts are audited as `inspection_start_blocked` (reason, inspection id, officer id).
- School page: new cards "Inspection in progress by another officer" (amber) and "Already inspected this month" (blue, reopen date, report link if the user may view it), no Start button; own older draft shows "You have a draft from …". Search results: badges "Draft by another officer" / "Inspected this month".
- CLAUDE.md domain rule 5 updated.
- Tests: own older draft reopened, another officer's draft blocks (cancelled does not), completed by another officer blocked for the month and open again next month, badges and cards shown. The absentee test now uses a school in another cluster for the "other inspection".
- Files: `app/Services/InspectionStartGuard.php` (new), `app/Http/Controllers/{InspectionController,SchoolController}.php`, `resources/views/schools/{show,_results}.blade.php`, `resources/scss/app.scss`, `CLAUDE.md`, `tests/Feature/InspectionFlowTest.php`.
- Verification: `npm run build`, `view:cache` OK; `php artisan test` 47 passed (605 assertions).

## 51. Redesigned profile page and header account menu (Modification V3 items 1, 2) (2026-11-18)
- Profile (`profile.blade.php`), same features and routes, new layout in the theme colours:
  - hero card with gradient band, large photo with the camera button (same photo form and JS), name, designation, level / status / jurisdiction chips, "Member since" and "Last sign-in", and quick actions (Change password, Change mobile, Sign out on phones);
  - "Personal details" tiles (Employee ID, Mobile, Email, Designation, Jurisdiction, Extra rights) and the State-Administrator lock note;
  - "Change mobile number" card with the current number, +91 prefix and a clear "Request pending approval" state;
  - "My inspections" counters (open drafts, submitted this month, submitted in total; officers who can inspect), linking to My Inspections;
  - "Jurisdiction history" as a timeline with the current posting highlighted.
- Header: account button is a white pill with photo, green "signed in" dot, greeting (wide screens), name, designation · level and a turning chevron; on phones only the photo. Dropdown: gradient head with greeting, name, designation and level, jurisdiction and mobile, menu items with icons and short descriptions (My Profile, Change Password, Sign out in red), "Signed in …" footer.
- `ProfileController::show` adds the activity counts.
- Files: `resources/views/profile.blade.php`, `resources/views/components/layouts/app.blade.php`, `app/Http/Controllers/ProfileController.php`, `resources/scss/app.scss`, `tests/Feature/RegistrationAndLoginTest.php`.
- Verification: `npm run build`, `view:cache` OK; `php artisan test` 47 passed (612 assertions).

## 52. Browser coverage check: Chrome, Edge, Firefox (Modification V3 item 7) (2026-11-18)
- Build: `autoprefixer` (dev dependency) via new `postcss.config.js`; `browserslist` in `package.json` (Chrome/Edge/Firefox ≥ 100, Safari/iOS ≥ 15, Chrome Android ≥ 100); `vite.config.js` `build.target` set to the same browsers. Verified the built CSS gets prefixes (e.g. `-webkit-backdrop-filter`).
- Fixes found in the code audit: number fields hide browser spin buttons everywhere (Firefox showed wide spinners over the "L" suffix of fund amounts); `:has()` fallbacks via JS classes (`.fund-bal.is-neg`, `.review-confirm.is-checked`) for Firefox < 121; standard `line-clamp` + `max-height` for the two-line tab labels; Firefox button focus inner border removed; date fields 44px high.
- New `BROWSER_SUPPORT.md`: supported versions, what was checked per feature (layout, camera, gallery, hashing, lakh format, idle warning, review page, PDF, Excel) and a 10-point manual checklist per browser (Chrome / Edge / Firefox) to tick before release.
- Note: real browsers cannot be run from this environment; the check is a code audit plus the automated server tests. The manual checklist is still to be done on devices.
- Files: `package.json`, `package-lock.json`, `postcss.config.js` (new), `vite.config.js`, `resources/scss/app.scss`, `resources/js/wizard.js`, `BROWSER_SUPPORT.md` (new).
- Verification: `npm run build` OK; `php artisan test` 47 passed (612 assertions).

## 53. Submitted report page redesigned, responsive on all screens (Modification V4 item 1) (2026-11-18)
- `inspections/show.blade.php` rebuilt:
  - gradient hero with status chip, inspection number, school name, UDISE and location, category / management tags, four key tiles (date, officer, photographs with required count, submitted time) and actions (Download PDF, Print, Continue editing for the officer while editable);
  - unlock notice and the State Admin unlock form as compact bars;
  - "Inspection details" tiles (officer, person met, started / submitted with coordinates and a distance-from-school badge, amber when over 500 m);
  - section links (sticky on desktop) to every step;
  - each step as a card using the same components as the review page: coloured answer pills, figure tiles with automatic totals, absentee / classroom cards, year-wise fund summary, suggestions, photo gallery by category with the full-size viewer and a download button per photo; remarks shown under the answer;
  - "At a glance" summary (students, teachers present / absent, absentee and classroom counts, fund balance in ₹ lakh, required photographs ✓/✗), documents list, "Download official PDF report"; print styles.
- Shared partials: `inspections/partials/step-answers.blade.php` (extracted from the review page; options `skipBasic`, `remarks`, `downloads`) and `inspections/partials/photo-viewer.blade.php`; both pages load `resources/js/review.js`.
- `ReportController::reportData()` adds the photo categories.
- Files: `resources/views/inspections/{show,review}.blade.php`, `resources/views/inspections/partials/{step-answers,photo-viewer}.blade.php`, `app/Http/Controllers/ReportController.php`, `resources/scss/app.scss`, `tests/Feature/InspectionFlowTest.php`, `Documents/Modification of the ApplicationV4.docx` (already committed).
- Verification: `npm run build`, `view:cache` OK; `php artisan test` 47 passed.

## 54. Official PDF header, watermark and footer (Modification V4 items 2, 3, 4) (2026-11-18)
- Header on every page: Government of Assam emblem (left) and Samagra Shiksha logo (right), centre "GOVERNMENT OF ASSAM", "Samagra Shiksha Abhiyan, Assam", "SCHOOL INSPECTION REPORT", "School Monitoring and Assessment System", blue/green rule and the reference line (inspection no · status · UDISE). Logos from `public/logo/` are embedded as data URIs (no file or remote access by DomPDF).
- Watermark on every page: "SCHOOL INSPECTION REPORT / SSA, ASSAM", diagonal, light blue at 7% opacity, so the content stays readable.
- Footer on every page: "Generated by: School Inspection & Monitoring System", "Generated Date & Time: DD-MM-YYYY HH:MM" (server time, Asia/Kolkata), "CONFIDENTIAL – FOR OFFICIAL USE ONLY" in red, and "Page X of Y" stamped after rendering (`ReportController::pdf()` renders, then `page_text`).
- Page margins widened for the taller header and footer; section colours aligned with the theme blue.
- Visual check: the template was rendered to HTML with live data and screenshotted in headless Chrome (header, watermark, footer as expected); the real PDF rendered without errors (1.36 MB with 4 photos). No PDF-to-image tool is available here, so the final PDF itself should be opened once to confirm.
- Files: `resources/views/inspections/pdf.blade.php`, `app/Http/Controllers/ReportController.php`.
- Verification: `php artisan test` 47 passed (PDF download returns `application/pdf`).

## 55. Database indexing and indexing report (Modification V4 item 5) (2026-11-18)
- Audit of all indexes and of every query the screens and jobs run; full report and action plan in new `DB_INDEXING.md`.
- Fix: 9 queries used `whereDate('inspection_date', …)` (compiled to `DATE(column)`, which cannot use an index) in the dashboard, start rules, school page, search badges, reports and export. They now compare the plain column; `Inspection::inspectionDate()` (accessor + mutator, replaces the `date` cast) always stores `Y-m-d`, so this is correct on MySQL and SQLite.
- New migration `2026_11_18_000006_add_performance_indexes` (adds only, skips existing, reversible): inspections (school/officer/district/block/cluster + status + inspection_date; status + started_at), schools (jurisdiction + status + name), inspection_answers (question_code), audit_logs (created_at; user_id + created_at; ip_address), user_jurisdictions (jurisdiction + end_date), registration_requests (approver_id + status), mobile_change_requests (status + created_at; user_id + status).
- EXPLAIN on local MySQL: the new indexes are chosen for the open-draft rule, expire-drafts job, Find School, users of a district, approval queue and question answers; for the rest they are candidates (small local tables are scanned instead, as expected).
- Action plan (in `DB_INDEXING.md`): run the migration on the server in a quiet hour; re-check EXPLAIN and enable the slow query log after the pilot; FULLTEXT on school name only if needed; review again before Phase 2.
- Files: `database/migrations/2026_11_18_000006_add_performance_indexes.php` (new), `app/Models/Inspection.php`, `app/Http/Controllers/{Dashboard,Inspection,Report,School}Controller.php`, `app/Services/InspectionStartGuard.php`, `tests/Feature/InspectionFlowTest.php`, `DB_INDEXING.md` (new).
- Verification: `php artisan migrate` on local MySQL OK; `php artisan test` 48 passed (631 assertions).

## 56. PDF report: school identity card and two-column basic information (2026-11-18)
- The plain school name and UDISE line at the top of the PDF is replaced by a school card: a blue band with "SCHOOL", the school name, and chips for management, category and status, followed by four colour-coded tiles: **UDISE Code** (blue, monospace), **District** (green), **Block** (orange) and **Cluster** (purple).
- "1. Basic Information" is now a bordered two-column grid with label cells and bold values: Inspection ID / Status, Inspecting officer / Designation, Inspection started / Submitted, Person met / Their designation. The start and submit device locations span the full width. Management and category moved into the school card.
- Built from tables only (DomPDF has no flexbox) in the theme colours.
- Files: `resources/views/inspections/pdf.blade.php`.
- Verification: a real PDF rendered without errors (inspection 3, 4 photos); the template, rendered to HTML with live data, was screenshotted in headless Chrome; `php artisan test` 48 passed.

## 57. PDF report: light, clean colour scheme (2026-11-18)
- The whole PDF now uses a white page with neutral greys and one blue accent.
  - The dark blue school band is gone; the school card is white with a thin blue left edge and outlined chips.
  - Section headings are blue text with a thin underline instead of a shaded bar.
  - Basic Information and the answer rows use thin light row lines.
  - Table headers are very light grey.
  - The header rule is a thin line and the footer line is grey.
  - The watermark is lighter (5% opacity).
- The required header, watermark and footer text are unchanged.
- Files: `resources/views/inspections/pdf.blade.php`.
- Verification: a real PDF rendered without errors; the HTML render was screenshotted in headless Chrome; `php artisan test` 48 passed.

## 58. PDF report: distinct background for the SCHOOL section (2026-11-18)
- The SCHOOL card now has its own light blue background (`#eaf2fc`), so it stands apart from the white page.
  - The UDISE / District / Block / Cluster tiles use a paler blue.
  - Borders are blue-grey, the "SCHOOL" label is blue, and the chips are white.
- The rest of the page stays white.
- Files: `resources/views/inspections/pdf.blade.php`.
- Verification: a real PDF rendered without errors; the HTML render was screenshotted; `php artisan test` 48 passed.

## 59. PDF report: print legibility (2026-11-18)
- All text and lines are now dark enough to stay clearly visible on paper, including black-and-white and low-toner printers.
  - Labels, question numbers, captions, the reference line and the footer: from light grey (#9ca3af / #6b7280) to dark grey (#374151) or near-black.
  - Answers and values: black.
- Small text is at least 7.5–8pt:
  - "SCHOOL" label and tile labels 7.5pt, bold.
  - Chips, header subtitle, footer and captions 8pt.
  - CONFIDENTIAL line 8.5pt.
- Lines are visible in print:
  - row lines #cbd5e1
  - table and photo borders #94a3b8
  - school card borders #7fa6d6
  - section underline and header rule solid blue
  - footer line grey #6b7280
- The watermark stays at 5% so it never covers the text. The light blue SCHOOL background is kept.
- Files: `resources/views/inspections/pdf.blade.php`.
- Verification: a real PDF rendered without errors; the HTML render was screenshotted; `php artisan test` 48 passed.

## 60. Load test: concurrent login and inspection form (V5 Step 2) (2026-11-18)
- **`LoadTestSeeder`** (local and testing only) creates:
  - 500 officers (mobiles `7000000001…`, password `Load@1234`), each with their own school
  - 5,000 schools
  - 10,000 submitted inspections with 650,000 answers
- **`tools/loadtest/loadtest.php`** is a `curl_multi` driver. Every virtual officer has their own cookies and CSRF token.
  - Login scenario: 3 requests.
  - Form scenario: start, step 1, 10 auto-saves, step 2, progress (17 requests).
  - Steps up from 10 users; stops when errors go above 1 % or P95 goes over the limit.
  - Records MariaDB connections, slow queries and lock waits.
  - Warms up Blade views first.
- **`LOADTEST_MODE`** (`config('inspection.loadtest')`) lifts the 10-per-minute login limit for a test from one machine. It is ignored in production.
- **Results:**
  - The server handles about 18 requests per second. 10 officers acting at the same instant get answers in under 1 s; 25 within 2 s.
  - At 100 officers there are no errors, but they wait 5–9 s.
  - Officers filling forms at a normal pace: about 250 at the same time. That supports about 2,500 registered officers on this laptop.
  - The database is not the bottleneck. The limit is PHP time per request, because OPcache is off.
- **Found:**
  - Windows Apache runs PHP in threads, and the `.env` loading is not thread-safe. This gave random 500 and 419 errors. `config:cache` is required on such a server.
  - Blade compilation collides between threads on Windows.
  - The buffer pool (16 MB) is too small for production.
- **Clean-up:** the database was backed up before seeding and restored afterwards (21 users, 24 schools, 5 inspections). `.env` was restored and the caches cleared.
- **Files:**
  - `database/seeders/LoadTestSeeder.php`
  - `tools/loadtest/loadtest.php`
  - `config/inspection.php`
  - `app/Providers/AppServiceProvider.php`
  - `tests/Feature/LoadTestModeTest.php`
  - `LOAD_TEST.md`
  - `.gitignore`
- **Verification:** both scenarios ran on Apache (results in `storage/loadtest/*.json`); `php artisan test` 51 passed.

## 61. Database indexing: check at volume and remove an unused index (V5 Step 2) (2026-11-18)
- **Volume check:**
  - Seeded the load-test volume on a backed-up database (10,006 inspections, 650,138 answers, 5,024 schools) and ran `ANALYZE TABLE`.
  - Ran `EXPLAIN` and timed 19 main queries.
  - Every screen and job query uses an index and takes under 7 ms.
  - The district, block and cluster area-report indexes are now chosen; at the small local volume MySQL had read the whole table.
- **Removed** `answers_question_code_idx` (migration `2026_11_18_000007`):
  - no query reads answers by question alone
  - it was 17.5 MB and added one more write to every auto-save
  - `down()` restores it
- **`DB_INDEXING.md`:**
  - new section 8 (volume results)
  - corrected the section 4 and 5 rows
  - action plan items 8–12 (deployment migrate + ANALYZE, buffer pool 1–2 GB, how Phase 2 question-wise reports should query)
- **Index test:** now checks the unique answer key, and that the removed index is gone.
- The database was restored from the backup and the new migration applied (21 users).
- **Files:**
  - `database/migrations/2026_11_18_000007_drop_unused_answers_question_code_index.php`
  - `tests/Feature/InspectionFlowTest.php`
  - `DB_INDEXING.md`
- **Verification:** `php artisan test` 51 passed.

## 62. Project document: database schema, functional flow and user type check (V5 Step 1) (2026-11-18)
- Reviewed the project and wrote **`PROJECT_DOCUMENT.md`**, for developers and users. It covers:
  - the system overview
  - terms
  - user levels, rights and the approval chain
  - flow charts (Mermaid) for:
    - registration and approval
    - sign-in security
    - the start rules
    - the 7-step form
    - the inspection life cycle
    - reports, PDF and Excel
    - administration
  - the database: ER diagram and all 32 tables, with keys and the rules the database enforces
  - a table of modules and the tables they read and write, with an auto-save example
  - a developer guide (layout, request path, rules, commands, testing)
  - statuses
- **User type check:** new `tests/Feature/UserTypeAccessTest.php` (5 tests, 99 assertions):
  - every level against the officer and admin screens
  - rights granted to one user
  - area visibility of a submitted inspection for the same and other district, block and cluster
  - drafts visible only to their officer
- **Files:** `PROJECT_DOCUMENT.md`, `tests/Feature/UserTypeAccessTest.php`.
- **Verification:** `php artisan test` 56 passed.

## 63. Inspection form document: screens, flow charts, code and database (2026-11-18)
- Wrote **`INSPECTION_FORM_DOCUMENT.md`**, a detailed explanation of the inspection form for users and developers:
  - the form at a glance: 7 steps, what each step records, where it is stored, and whether it is required
  - the screens, with **16 screenshots** in `docs/screenshots/`:
    - login, dashboard, Find School and the school page
    - steps 1–7, the missing-items dialog, review and the submitted report
    - steps 1 and 7 on a phone
  - flow charts (Mermaid):
    - end-to-end flow
    - start rules
    - auto-save sequence
    - photo pipeline
    - submit
    - life cycle
  - the database schema of the form: ER diagram, and every column of `inspections`, `inspection_answers`, `inspection_absent_teachers`, `inspection_classroom_observations`, `inspection_fund_positions`, `inspection_remarks` and `inspection_evidence`
  - the action → request → controller → tables matrix, and the progress JSON
  - the code walkthrough: files, how a question is defined, rendered and checked, the progress calculation, concurrency protections
  - security rules and the tests covering the form
- **How the screenshots were taken:**
  - headless Chrome against `php artisan serve --port=8010`, signed in as demo officer 9000000031
  - the database was backed up before and restored after (users 21, inspections 6)
  - evidence photos are blurred in the images
- **Found:** under the XAMPP sub-folder URL (`/SCHOOLINSPECTION/public`), the built CSS loads the Bootstrap Icons font from `/build/...`, so the icons do not show. They do show at a site root (artisan serve or a production vhost). Not changed; noted in the document, section 12.
- Files:
  - `INSPECTION_FORM_DOCUMENT.md`
  - `docs/screenshots/*.png`
  - `ACTION.md`

## 64. Fix: icons missing when the app is served from a sub-folder (2026-11-18)
- **Problem:** at `http://localhost/SCHOOLINSPECTION/public` every Bootstrap icon showed as an empty box. The built CSS loaded the icon font from the absolute path `/build/assets/bootstrap-icons-….woff2`, which resolves to `http://localhost/build/...` (404). This was found while taking the screenshots for #63.
- **Fix:** `vite.config.js` sets `base: './'` for production builds. The built CSS now uses `url(./bootstrap-icons-….woff2)`, relative to the CSS file, and the JS chunks import each other relatively. The dev server (`npm run dev`) is unchanged, and Blade `@vite` tags already use `asset()`.
- **Verified:**
  - `npm run build` succeeded.
  - No `/build/` absolute paths are left in `public/build/assets`.
  - Headless Chrome on the Apache sub-folder URL shows all the login page icons.
  - `php artisan test`: all tests pass.
- **Deploy:** run `npm run build` after pulling. `public/build` is not in git.
- Files:
  - `vite.config.js`
  - `INSPECTION_FORM_DOCUMENT.md` (section 12 note)
  - `ACTION.md`

## 65. New Module development plan (2026-11-18)
- **Request:** `Documents/Modification of the Application New Module.docx` asks for three new modules: an interactive Map View, map-based Inspection Scheduling with drag and drop, and an Automatic Inspection Rating system. The user asked for a development plan first, and then each module built, committed and pushed one at a time.
- **Done:** `NEW_MODULES_PLAN.md`, covering:
  - for each module: scope, data model (additive migrations only), services, routes, screens, access rules, workflow and tests
  - the delivery order: Map View (#66), Scheduling (#67), Rating (#68)
  - rules for every module: jurisdiction scoping, mobile layout, slow networks, audit logs, existing data preserved
- **Decisions:**
  - Leaflet and OpenStreetMap are bundled through npm (no CDN).
  - There is no external routing API. Distances are straight-line × a road factor, and route optimisation is nearest-neighbour + 2-opt.
  - Rating schemes are versioned, so old ratings keep the scheme they were calculated with.
  - Scoring was listed as Phase 2 in CLAUDE.md. It is now explicitly requested, so CLAUDE.md will be updated in #68.
- Files:
  - `NEW_MODULES_PLAN.md`
  - `Documents/Modification of the Application New Module.docx`
  - `Documents/Modification of the ApplicationV5.docx`
  - `ACTION.md`

## 66. New Module 1: Interactive Map View (2026-11-18)
- **Request:** New Module document, section 1. A GIS dashboard of the administrative hierarchy and inspection coverage across Assam (State → District → Block → Cluster → School).
- **Done:**
  - **Leaflet + OpenStreetMap,** bundled with Vite (`leaflet`, `leaflet.markercluster`; no CDN).
  - **`MapDataService`:** all data through `JurisdictionService`.
    - Area rows: schools, inspected (submitted in the period), in progress (draft or unlocked), pending, no-GPS, coverage %, and officers by level (active, with a current posting).
    - School rows: status, and the last submitted inspection with its officer.
    - Officer filter: only schools that officer inspected in the period.
    - An area without coordinates is placed at the average of its schools' GPS.
    - Results cached for 60 s (`MAP_CACHE_SECONDS`; 0 in tests).
  - **`MapController`:**
    - `GET /map`, `/map/areas/{district|block|cluster}?parent=` and `/map/schools?cluster=|q=`, with right `view_reports` and a 120/min throttle.
    - An area or officer outside the user's jurisdiction returns 403.
    - The map opens at the user's own level.
  - **Page:**
    - stat cards: registered officers, total schools, inspected, pending, coverage
    - filters: District, Block, Cluster, school search, officer, period (an offcanvas on phones)
    - breadcrumb drill-down
    - area circles sized by school count and coloured by coverage
    - school pins: green inspected, orange in progress, red not inspected
    - popups with details and "View report" / "Open school"
    - table: district-, block- or cluster-wise coverage and registered officers by level
    - "Map View" menu item
  - **Migration:** `clusters.latitude` / `longitude` (additive).
  - **Boundaries:** new command `php artisan map:boundaries` (`ImportMapBoundaries`) converts the KML files supplied in `public/KML/Assam` into `public/geo/assam-state.geojson` and `assam-districts.geojson`.
    - Douglas–Peucker simplification (about 150 m), coordinates rounded to 5 decimals.
    - The output is 240 KB, from 17 MB of KML: 50 district files, 126 constituency polygons.
    - The map always draws the state outline. At district level, district shapes are coloured by the coverage of the District Master row with the same name or code, and the rest are grey.
    - The raw `public/KML` folder is not committed; the generated GeoJSON is.
  - **Tile server:** configurable (`MAP_TILE_URL`, `MAP_TILE_ATTRIBUTION`).
- **Verified:**
  - `tests/Feature/MapViewTest.php` (9 tests) covers:
    - the page for every level
    - counts and coverage
    - pin status and last inspection
    - jurisdiction 403s, and the search scope
    - the officer filter and its scope
    - officer counts by level, inactive officers excluded
    - the breadcrumb and coordinates
    - input validation
    - KML → GeoJSON conversion (name, constituency, simplification, closed rings)
  - Full suite: 65 passed.
  - Headless Chrome on the Apache URL as the demo District officer (9000000011):
    - only North District is visible
    - drill-down to schools works, and pins and popups show
    - the phone layout works
    - as the State officer (9000000001), the whole of Assam shows with the state outline and district shapes
- Files:
  - `app/Services/MapDataService.php`
  - `app/Http/Controllers/MapController.php`
  - `app/Console/Commands/ImportMapBoundaries.php`
  - `public/geo/assam-state.geojson`, `assam-districts.geojson`
  - `routes/web.php`
  - `resources/views/map/index.blade.php`
  - `resources/js/map.js`, `map-common.js`, `leaflet-global.js`
  - `resources/scss/app.scss`
  - `resources/views/components/layouts/app.blade.php`
  - `vite.config.js`
  - `package.json` and lock file
  - `config/inspection.php`
  - `phpunit.xml`
  - `.env.example`
  - `database/migrations/2026_11_19_000001_add_coordinates_to_clusters.php`
  - `app/Models/Cluster.php`
  - `tests/Feature/MapViewTest.php`
  - `PROJECT_DOCUMENT.md` (4.8, tables, modules)
  - `CLAUDE.md`
  - `NEW_MODULES_PLAN.md`
  - `ACTION.md`

## 67. New Module 2: Map-based Inspection Scheduling with drag and drop (2026-11-18)
- **Request:** New Module document, section 2. Officers plan daily school visits on a map with drag and drop, with route optimisation and approval. District and Block officers assign and reassign plans, and the history of each plan is kept.
- **Done:**
  - **Tables (additive migration):**
    - `inspection_schedules`: one per officer and day, status, review and publish fields, start point, totals.
    - `inspection_schedule_items`: schools in visit order, with km, travel minutes, planned arrival, visit status and the linked inspection.
    - DB unique keys: (officer, date) and **(school, date)**, so a school cannot be on two officers' plans the same day.
  - **`ScheduleService`:**
    - Rules: date today or later (up to 92 days ahead); at most 8 schools a day; schools active and inside the officer's jurisdiction; not planned by another officer that day; not being inspected by another officer; not already inspected by another officer in the plan's month. Checked on save, on approval and on reassignment.
    - Draft → submit → approve (publishes) or send back with a note → published → completed. When `SCHEDULE_APPROVAL_REQUIRED=false`, the officer publishes directly.
    - Higher officers (their jurisdiction covers the officer, and their rank is higher) plan for officers below them (published at once), change, reassign (rules checked for the new officer), approve or reject. Cluster officers cannot plan for others.
    - Cancel frees the schools for that day. A visit can be skipped with a reason.
    - `markInspected()` is called from `InspectionController::submit`: today's visit on the officer's published plan becomes *inspected*. When nothing is left to visit, the plan is *Completed*.
    - Every step is audit-logged (`schedule_*`) and shown as the plan's history.
  - **`App\Support\RouteOptimizer`:**
    - Nearest neighbour + 2-opt on an open route.
    - Road km = straight line × 1.3; time from 25 km/h plus 60 minutes per visit, starting 09:30 (config `inspection.schedule`).
    - No external routing service. "Directions" opens the route in the phone's maps app.
  - **Screens:**
    - `/schedules`: Day, Week and Month calendar, filtered by officer and status.
    - `/schedules/plan`: the planner.
      - Leaflet map with status pins and the numbered route line.
      - "Available schools", nearest first, dragged into the "Day plan" (SortableJS, touch-friendly) and reordered there.
      - "Add to plan" from the map popup.
      - km and arrival time per stop, and totals.
      - "Optimise route" and "Start from my location".
      - Save draft / Submit for approval, or Assign & publish for higher officers.
    - `/schedules/approvals`: plans waiting for the user.
    - `/schedules/{id}`:
      - route map and visits in order
      - Start inspection on the plan day (with GPS)
      - skip a visit
      - approve or send back, reassign, cancel
      - history
    - "Inspection Schedules" menu item, and a "Today's plan" panel on the dashboard.
  - `schedule_date` is stored as plain Y-m-d (accessor), like `inspection_date`.
- **Difference from the plan:** there is no separate "approved" status. Approving a plan publishes it, so the statuses are draft, submitted, rejected, published, completed, cancelled.
- **Verified:**
  - `tests/Feature/InspectionScheduleTest.php` (10 tests) covers:
    - a draft with distances and times, and the calendar, plan and planner pages
    - past date, outside jurisdiction, the per-day limit, and a duplicate school for another officer
    - the month rule
    - submit → reject → resubmit → approve, and the audit trail
    - publishing directly without approval
    - assigning and reassigning by a higher officer, and a cluster officer cannot assign
    - submitting an inspection marks the visit; a skip completes the plan
    - cancel frees the schools
    - the planner school list's flags and scope
    - the optimiser's order and uncrossing
  - Full suite: 75 passed.
  - Headless Chrome on the Apache URL:
    - Arjun Saikia (9000000031) planned and submitted.
    - Rakesh Baruah (9000000011) saw it in Approvals, approved it, and it was published with its history.
    - The Week calendar shows the plan.
  - The database was backed up before the browser run and restored after it (users 21, inspections 6, plans 0).
  - During the run, demo Block officer 9000000021 was locked after failed sign-ins. The restore undid this, and the approval was done as the District officer instead.
- Files:
  - `database/migrations/2026_11_19_000002_create_inspection_schedule_tables.php`
  - `app/Enums/ScheduleStatus.php`
  - `app/Models/InspectionSchedule.php`, `InspectionScheduleItem.php`
  - `app/Services/ScheduleService.php`
  - `app/Support/RouteOptimizer.php`
  - `app/Http/Controllers/ScheduleController.php`
  - `app/Http/Controllers/InspectionController.php` (submit hook)
  - `app/Http/Controllers/DashboardController.php`
  - `routes/web.php`
  - `resources/views/schedules/*.blade.php`
  - `resources/views/dashboard.blade.php`
  - `resources/views/components/layouts/app.blade.php`
  - `resources/js/schedule-planner.js`, `schedule-route.js`
  - `resources/scss/app.scss`
  - `vite.config.js`
  - `package.json` and lock file (sortablejs)
  - `config/inspection.php`
  - `.env.example`
  - `tests/Feature/InspectionScheduleTest.php`
  - `PROJECT_DOCUMENT.md` (4.9, tables, modules)
  - `CLAUDE.md`
  - `NEW_MODULES_PLAN.md`
  - `ACTION.md`

## 68. New Module 3: Automatic Inspection Rating (2026-11-18)
- **Request:** New Module document, section 3. Automatic weighted scoring of every inspection, with section and overall scores, colour bands, strengths and weaknesses, a performance summary in the report, history per school, re-rating after authorised changes, and admin-configurable criteria.
- **Done:**
  - **Tables (additive migration):**
    - `rating_schemes`: versioned, one active.
    - `rating_criteria`: answer or metric, weight, points per answer.
    - `inspection_ratings`: one per inspection, with score, band, sections, details, strengths and weaknesses.
  - **`App\Rating\RatingCalculator`** (pure):
    - Score % = Σ(weight × points) ÷ Σ(weight of applicable items) × 100, per section and overall.
    - Not Applicable items are excluded from the denominator: hidden or disabled questions (`show_if` / `enable_if`), an answer not in the points list (e.g. "na"), or a metric with a zero denominator.
    - 7 metrics: student attendance, teacher attendance, trained teachers, authorised absences, TLM use, participation, fund utilisation.
    - Bands are configurable, and the boundaries are exact (89.99 = Very Good, 90 = Excellent).
    - Strengths, weaknesses, areas requiring improvement (sections below 60 %), and a summary paragraph.
  - **`App\Rating\DefaultScheme`:** version 1, with 59 criteria. Condition and governance answers weigh 1; metrics weigh 3. Default bands follow the document: 90/75/60/40 with green, blue, yellow, orange and red.
  - **`RatingService`:**
    - `rate()` is called from `InspectionController::submit`, so it runs on the first submit and on resubmit after a State Admin unlock. It is wrapped in try/catch, so a rating error never blocks a submission.
    - Old and new scores are audited (`inspection_rated`, `inspection_rerated`).
    - Legacy v1 inspections are not rated.
    - Version 1 is created on first use.
    - `saveNewVersion()`: settings are never changed in place.
  - **`php artisan ratings:recalculate [--all]`:** back-fill, or re-rate everything.
  - **Rating Settings (`/admin/rating`, `manage_masters`):**
    - Weights per item and points per answer, grouped by section; the band editor.
    - Saving creates version n+1, with an optional "re-rate all".
    - Version list with rated counts; "Recalculate all".
    - Validation: one band must start at 0, no duplicate starts, points 0–1, weights 0–100.
  - **Display:**
    - Report: score dial, band, section bars, summary, strengths, weaknesses, areas requiring improvement.
    - PDF: "School Performance Summary".
    - School page: rating history with the change since the previous rated inspection.
    - My Inspections and Inspections in Area: a rating column (`<x-rating-badge>`).
    - Excel export: "Rating score (%)" and "Rating" columns.
    - Menu: "Rating Settings".
  - CLAUDE.md no longer lists scoring as out of scope (New Module), and rule 11 was added: ratings are derived and schemes are versioned.
- **Verified:**
  - `tests/Feature/InspectionRatingTest.php` (9 tests) covers:
    - the formula and weights
    - N/A exclusion and "not rated"
    - band boundaries
    - metrics and their zero denominators
    - service rating of v2 only; report, list and PDF
    - admin save → version 2, old rating kept, then recalculate
    - 403 for non-admin levels
    - the recalculate command
    - default scheme codes and values exist in the questionnaire
  - `InspectionFlowTest` (unlock and resubmit) now also checks:
    - rated on submit
    - re-rated after the correction (score rises, `inspection_rerated` audited)
    - only one rating row
  - Full suite: 84 passed.
  - Browser (Apache URL, demo officer 9000000031):
    - the report of SI/2026/1801/000001 shows 66.7 % "Good", with section bars, summary, strengths and weaknesses
    - the PDF returns 200 application/pdf
    - The database was backed up and restored afterwards (ratings 0).
- **Deploy:**
  - `php artisan migrate`
  - `php artisan ratings:recalculate`, to rate inspections submitted before this module
  - `npm run build`
- Files:
  - `database/migrations/2026_11_19_000003_create_rating_tables.php`
  - `app/Rating/RatingCalculator.php`, `DefaultScheme.php`
  - `app/Models/RatingScheme.php`, `RatingCriterion.php`, `InspectionRating.php`
  - `app/Models/Inspection.php` (`rating()`)
  - `app/Services/RatingService.php`
  - `app/Console/Commands/RecalculateRatings.php`
  - `app/Http/Controllers/Admin/RatingController.php`
  - `app/Http/Controllers/InspectionController.php`, `ReportController.php`, `SchoolController.php`
  - `routes/web.php`
  - `resources/views/admin/rating/index.blade.php`
  - `resources/views/inspections/partials/rating.blade.php`
  - `resources/views/components/rating-badge.blade.php`
  - `resources/views/inspections/show.blade.php`, `pdf.blade.php`, `index.blade.php`
  - `resources/views/schools/show.blade.php`
  - `resources/views/components/layouts/app.blade.php`
  - `resources/scss/app.scss`
  - `tests/Feature/InspectionRatingTest.php`, `InspectionFlowTest.php`
  - `PROJECT_DOCUMENT.md` (4.10, tables, modules)
  - `CLAUDE.md`
  - `NEW_MODULES_PLAN.md`
  - `ACTION.md`

## 69. V6 module development plan (2026-11-19)
- **Request:** `Documents/Modification of the ApplicationV6.docx` lists 7 items: URL encryption, password encryption on submit, a login monitoring report for the State Admin, the district KML for districts, my location with distance and road route, the rating shown for schools, and a rating report.
- **Done:**
  - Agreed the meaning of the unclear items with the user:
    - URLs: encrypt the record IDs.
    - Passwords: RSA encryption in the browser before submit.
    - "District form the school": distance from the school, plus the road route (OSRM) and a Google Maps link.
    - District KML: show the officer's own district boundary.
  - Planned modules 4 to 10 in `NEW_MODULES_PLAN.md` ("V6 modules" table). Each is built one at a time, then committed and pushed with its own ACTION entry.
  - `.gitignore`: added `/public/KML`. The raw KML (17 MB) stays local; only the generated GeoJSON is committed, as CLAUDE.md requires.
  - Committed the V6 source document.
- Files:
  - `NEW_MODULES_PLAN.md`
  - `.gitignore`
  - `Documents/Modification of the ApplicationV6.docx`
  - `ACTION.md`

## 70. V6 Module 4: URL encryption of record IDs (2026-11-19)
- **Request:** V6 item "Security for URL Encryption". Record IDs must not be visible or guessable in URLs.
- **Done:**
  - **`App\Support\UrlId`:** deterministic, tamper-proof tokens.
    - `iv = HMAC-SHA256(Type|id)`, first 16 bytes. Then `AES-256-CBC(id)`, base64url encoded: 43 characters.
    - The keys are derived from `APP_KEY`.
    - Decoding recomputes the HMAC (`hash_equals`). A malformed or edited token, or a token of another model type, gives `null`.
  - **Trait `App\Models\Concerns\HasEncryptedRouteKey`:** `getRouteKey()` returns the token; `resolveRouteBinding()` (and the soft-delete variant) decodes it, so a number or a bad token returns 404. It also adds `Model::routeKeyFor($id)`.
    - Applied to Inspection, School, User, RegistrationRequest, InspectionSchedule, InspectionScheduleItem, InspectionEvidence, InspectionAbsentTeacher, InspectionClassroomObservation, InspectionFundPosition, MobileChangeRequest and ImportBatch.
  - **Raw-ID call sites fixed:**
    - `ScheduleController::skip` redirect
    - the row `update_url` and `delete_url` of teachers, classrooms and funds
    - the dashboard plan links
    - `schedules/show` (inspection link, start form)
  - **Admin location editor:** `PUT /admin/locations/{type}/{token}`; `whereNumber` removed. `whereNumber('schedule')` removed as well.
  - **Map JSON:** school rows and their last inspection carry a `key` (token); `map.js` uses it for the "Open school" and "View report" links.
  - **Unchanged:** database keys, foreign keys and the audit log stay numeric. Query-string filters stay numeric and are scoped by `JurisdictionService`.
- **Verification:**
  - New `UrlEncryptionTest` (4 tests):
    - round trip, determinism, type binding, tampering and malformed tokens
    - a numeric school or inspection URL gives 404; a cross-type token gives 404
    - officer and admin pages contain no numeric record URLs
    - map JSON `key`
    - the location update by token works, and by number gives 404
  - `MasterImportAndAuditTest` now uses tokens.
  - Full suite: 88 passed.
  - **Browser** (Apache URL, real MySQL data), as demo officer 9000000031 and District officer 9000000011:
    - dashboard, my inspections, school search, school page, step 1 of a draft, report and PDF (200 application/pdf), admin users and user page, approvals, area inspections, schedule approvals and the map all show only tokens
    - `/schools/1` gives 404, and an edited token gives 404
- Files:
  - `app/Support/UrlId.php`
  - `app/Models/Concerns/HasEncryptedRouteKey.php`
  - 12 models in `app/Models/`
  - `app/Http/Controllers/ScheduleController.php`, `Admin/LocationController.php`
  - `app/Services/MapDataService.php`
  - `resources/js/map.js`
  - `resources/views/dashboard.blade.php`, `schedules/show.blade.php`, `admin/locations/index.blade.php`
  - `routes/web.php`
  - `tests/Feature/UrlEncryptionTest.php`, `MasterImportAndAuditTest.php`
  - `CLAUDE.md` (rule 13)
  - `PROJECT_DOCUMENT.md` (4.2, 7.3)
  - `NEW_MODULES_PLAN.md`
  - `ACTION.md`

## 71. V6 Module 5: password encrypted in the browser before submit (2026-11-19)
- **Request:** V6 item "Password When Submitted Automatically Encrypted". The plain password must not travel in the request.
- **Done:**
  - **`App\Services\PasswordCrypto`** (singleton):
    - The RSA-2048 key pair is kept on the private disk (`storage/app/private/keys/password.key|.pub`), created on first use or by the new command `php artisan security:keys [--force]`.
    - A minimal `resources/openssl/minimal.cnf` is used for key generation, because XAMPP on Windows has no default `openssl.cnf`.
    - Per-session nonce; decryption with RSA-OAEP. OAEP uses SHA-1, the only OAEP hash PHP's openssl supports.
  - **`partials/password-key.blade.php`:** `<meta name="pw-key" content="<SPKI>" data-nonce="…">` in the portal and app layouts.
  - **`resources/js/password-encrypt.js`** (imported by `app.js`):
    - A document-level submit handler runs after the form's own handlers.
    - It encrypts `password`, `password_confirmation` and `current_password` as `enc:` + base64(RSA-OAEP(JSON {p, n})) into hidden copies. The visible inputs lose their name, so password managers still work.
    - It keeps the submitter's name and value; it restores the form on a bfcache restore; it shows a toast on failure.
    - It uses WebCrypto. In an insecure context (plain HTTP on a LAN IP) it loads `node-forge` on demand, as a separate chunk (new dev dependency).
  - **`DecryptPasswordFields`** (appended to the web middleware group):
    - It decrypts before validation, so the Form Requests and bcrypt are unchanged.
    - It refuses a wrong nonce, a damaged value, or plain text (when `inspection.security.require_encrypted_password` is on) with "Your password could not be sent securely…". A refused value is not counted as a failed login.
  - **Config:** `inspection.security` (`PASSWORD_ENCRYPTION`, `PASSWORD_ENCRYPTION_REQUIRED`).
    - `phpunit.xml` turns off the "required" switch for the older tests.
    - Load-test mode accepts plain passwords, because `tools/loadtest` posts plain text.
  - **`.env.example`:** production checklist and settings.
- **Verification:**
  - New `PasswordEncryptionTest` (6 tests):
    - meta key and nonce
    - encrypted login works; plain refused and not counted as a failure
    - wrong nonce or damaged value refused
    - encrypted change password works, and no plain text is in the audit log
    - plain accepted when not required
    - the key command keeps an existing pair
  - Full suite: 94 passed.
  - **Node against the live Apache login:** WebCrypto (RSA-OAEP SHA-1) and node-forge ciphertexts both log in; plain text is refused.
  - **Headless Chrome** (puppeteer-core, scratch only, not added to the project), demo officer 9000000033:
    - login reaches `/dashboard`; the POST body has `password=enc:…` (370 characters) and no plain password
    - change password Demo@1234 → Temp@98765 → Demo@1234: 3 encrypted fields each time, no plain text; the password is back to Demo@1234
    - with `isSecureContext` forced off, the forge chunk loads and login still works
  - The real `audit_logs` table gained the matching `login` and `password_changed` rows (append-only, left as is).
- **Deploy:**
  - `npm install && npm run build`
  - `php artisan security:keys`, then back up `storage/app/private/keys`
- Files:
  - `app/Services/PasswordCrypto.php`
  - `app/Http/Middleware/DecryptPasswordFields.php`
  - `app/Console/Commands/GeneratePasswordKeys.php`
  - `app/Providers/AppServiceProvider.php`
  - `bootstrap/app.php`
  - `config/inspection.php`
  - `phpunit.xml`
  - `resources/openssl/minimal.cnf`
  - `resources/views/partials/password-key.blade.php`
  - `resources/views/components/layouts/app.blade.php`, `portal.blade.php`
  - `resources/js/password-encrypt.js`, `app.js`
  - `package.json`, `package-lock.json`
  - `tests/Feature/PasswordEncryptionTest.php`
  - `.env.example`
  - `CLAUDE.md` (rule 14)
  - `PROJECT_DOCUMENT.md` (4.2)
  - `NEW_MODULES_PLAN.md`
  - `ACTION.md`

## 72. V6 Module 6: login user monitoring report for the State Administrator (2026-11-19)
- **Request:** V6 item "Login User Monitoring Report State Administrator user type".
- **Done:**
  - **Migration** `2026_11_19_000004_create_user_login_logs_table` (additive), model `UserLoginLog`.
    - One row per sign-in attempt: status `success` / `failed` / `locked` / `denied` plus a reason; session, IP, user agent; browser, platform and device (new `App\Support\UserAgent`, no dependency); `login_at`, `last_seen_at`, `logout_at`, `logout_reason`.
    - It is an operational table. The append-only `audit_logs` still receive `login` / `login_failed`.
  - **`LoginMonitorService`:**
    - `success()` (after session regenerate), `refused()`, `touch()` and `logout()`.
    - Wrapped so that a monitoring error never blocks a sign-in.
    - Report queries: `filters()`, `query()`, `summary()`, `onlineScope()`.
  - **`LoginController` records:**
    - unknown number and wrong password (`failed`)
    - the attempt that locks the account, and attempts while locked (`locked`)
    - pending, rejected and inactive accounts (`denied`)
    - success and sign-out
  - **`TouchLastSeen` web middleware:** `last_seen_at` at most once a minute per session. Online means not signed out and active within `session.lifetime`; otherwise the session is timed out.
  - **`Admin\LoginReportController`:** `/admin/login-report`, `/export`, `/users/{user}` under `can:state_admin`; "Login Monitoring" in the sidebar.
    - Tiles: sign-ins, officers signed in, online now, refused attempts, accounts locked now, never signed in.
    - Officers signed in by level against active officers, and the top refused numbers.
    - Sign-in log (25 per page; desktop table, phone list) with date, level, district, result and officer filters.
    - Per-officer history with devices; Excel export.
- **Verification:**
  - New `LoginReportTest` (5 tests):
    - sign-in, activity after 2 minutes, sign-out, duration and UA parsing
    - six refused cases with their reasons
    - summary numbers, every filter, validation, user page and export; 403 for State and District officers
    - online now
    - UA reader
  - Full suite: 99 passed.
  - **Headless Chrome against Apache/MySQL:**
    - demo officer 9000000034 on an Android UA signed in and out
    - 9000000035: one wrong password, then signed in
    - 4 rows recorded correctly (Chrome 120 / Android 13 / mobile, signed out; the failed one with reason)
  - The State Admin's password and mobile are no longer the defaults, so the report was rendered in-process as the State Admin on the real data (200; user page 200; export 200 xlsx) and screenshotted on desktop and phone.
  - **Fixes after the screenshots:**
    - The result label for `failed` is now "Failed", because the reason can be "Unknown mobile number".
    - Badges no longer wrap.
    - The "Online now" tile now has a colour.
  - The test attempt with 9999999999 shows in the log as an unknown number; no account was affected.
- **Deploy:** `php artisan migrate`
- Files:
  - `database/migrations/2026_11_19_000004_create_user_login_logs_table.php`
  - `app/Models/UserLoginLog.php`
  - `app/Support/UserAgent.php`
  - `app/Services/LoginMonitorService.php`
  - `app/Http/Middleware/TouchLastSeen.php`
  - `bootstrap/app.php`
  - `app/Http/Controllers/Auth/LoginController.php`, `Admin/LoginReportController.php`
  - `routes/web.php`
  - `resources/views/admin/login-report/{index,row,user}.blade.php`
  - `resources/views/components/layouts/app.blade.php`
  - `tests/Feature/LoginReportTest.php`
  - `PROJECT_DOCUMENT.md` (4.7, tables, modules)
  - `CLAUDE.md`
  - `NEW_MODULES_PLAN.md`
  - `ACTION.md`

## 73. V6 Module 7: district outline from the district KML (2026-11-19)
- **Request:** V6 item "Use District KML for District". Decision: officers see their own district's boundary.
- **Done:**
  - **`map:boundaries` now also writes `public/geo/districts/<slug>.geojson`** (one per district) and `index.json` (name, slug, bbox).
    - Each outline is the district's constituency polygons **dissolved**: edges shared by two polygons are dropped and the remaining edges are chained into rings. If a vertex has an odd number of outline edges, the command falls back to the raw polygons.
    - The outlines are simplified with `--detail` (default 0.0004°, about 40 m).
    - On the real KML: all 50 districts dissolved, 344 KB in total. `assam-districts.geojson` is unchanged.
  - **`--check`** lists every District Master row with its outline (set, by name, or a suggestion). **`--assign`** saves the suggestions, each audited as `district_boundary_assigned`.
  - **Migration** `2026_11_19_000005_add_boundary_name_to_districts` (additive).
  - **`DistrictBoundaryService`:**
    - `nameFor()`: `boundary_name`, else the name with case and punctuation ignored.
    - `urlFor()` / `urlForId()`.
    - `locate()`: bbox, then even-odd ray casting.
    - `suggest()`: from the headquarters, else the average of the district's schools.
  - **Location Master (districts):** a "Map boundary" select, validated against the outline list, and a chip showing the matched outline.
  - **Map:**
    - `/map/areas` and `/map/schools` return `outline`, the URL of the district in view (null at state level); district rows carry `boundary`.
    - `drawDistrictOutline()` in `map-common.js` draws a bold outline with an even-odd mask that greys the land outside, cached per page.
    - `map.js` draws it on every load and zooms to it in the district view. State-level shading now also matches the District Master through `boundary`.
  - **Schedules:** the planner and the route map draw the officer's district outline. The planner frames it until schools are drawn.
  - **Local demo data:** `php artisan map:boundaries --assign` linked North District to Kamrup Metro and South District to Darrang, from their schools' positions (audited; can be changed in the Location Master).
- **Verification:**
  - New `DistrictBoundaryTest` (5 tests), using fixture KML in a temp folder:
    - two adjacent constituencies dissolve into one 4-corner ring; index bbox
    - matching by `boundary_name` and by name; locate; suggest
    - `--check` changes nothing; `--assign` sets the names and audits them
    - map JSON `outline` and `boundary`; the planner page carries the outline
    - the Location Master select accepts only listed names, and empty clears the link
  - `MapViewTest` still passes. Full suite: 104 passed.
  - **Headless Chrome on Apache** (screenshots checked; no page errors):
    - District officer 9000000011: `/map` loads `kamrup-metro.geojson`, opens on the Kamrup Metro outline with the outside greyed, and shows Riverside and Hillview blocks
    - Cluster officer 9000000031: map and planner load the outline
    - State officer 9000000001: state view as before, no outline
- **Deploy:**
  - `php artisan migrate`
  - `php artisan map:boundaries`, then `php artisan map:boundaries --check` (and `--assign`, or set the boundaries in the Location Master)
  - `npm run build`
- Files:
  - `app/Console/Commands/ImportMapBoundaries.php`
  - `app/Services/DistrictBoundaryService.php`, `MapDataService.php`
  - `database/migrations/2026_11_19_000005_add_boundary_name_to_districts.php`
  - `app/Models/District.php`
  - `app/Http/Controllers/MapController.php`, `ScheduleController.php`, `Admin/LocationController.php`
  - `config/inspection.php`
  - `resources/js/map-common.js`, `map.js`, `schedule-planner.js`, `schedule-route.js`
  - `resources/views/admin/locations/index.blade.php`
  - `public/geo/districts/*`
  - `tests/Feature/DistrictBoundaryTest.php`
  - `PROJECT_DOCUMENT.md` (4.8, tables)
  - `CLAUDE.md`
  - `NEW_MODULES_PLAN.md`
  - `ACTION.md`

## 74. V6 Module 8: my location, distance from the school and road route (2026-11-19)
- **Request:** V6 item "Showing My Current Location in Map and district form the school if selected and road view". Agreed meaning: the distance from the school, plus the road route and a Google Maps link.
- **Done:**
  - **`map-common.js`:**
    - `createDirections()` adds a **My location** map button. It watches the GPS position, draws a blue dot with an accuracy circle, and centres the map once.
    - Errors get clear messages: insecure context (HTTP), permission blocked, timeout, unavailable.
    - `routeTo()` calls OSRM `route/v1/driving` (GeoJSON) with a timeout and draws a green route with a white casing, fitted to the view. If routing fails, it draws a dashed straight line.
    - Helpers: `haversineKm`, `fmtKm`, `fmtMinutes`, `directionsUrl` (Google Maps with origin and destination), plus `directionsPanelHtml()` and `bindDirectionsPanel()` for the distance and route panel.
  - **Map View:** every school popup (with GPS) shows the distance from me, **Show route** and **Google Maps**. The routing settings come from the map config.
  - **School page:** a new "Location & route" card (only when the school has coordinates), via the new Vite entry `resources/js/school-map.js`. It shows the school pin, the district outline (Module 7), a header "My location" button that frames both points, and the same panel.
  - **Config `inspection.map.routing`:** `MAP_ROUTING`, `MAP_ROUTING_URL` (default the public OSRM server), profile and timeout; added to `.env.example`.
  - **Privacy:** the position leaves the browser only when "Show route" is pressed.
  - **SCSS:** the locate button (on, busy), the school map and the panel.
- **Verification:**
  - New `SchoolDirectionsTest` (2 tests): the school page card and config (coordinates, routing) and no card without GPS; the map config carries the routing settings.
  - Full suite: 106 passed.
  - **Headless Chrome on Apache, simulated GPS 26.1445, 91.7362 (Guwahati)** as demo officer 9000000031:
    - school page: "Tap My location…" → "4.3 km from you (straight line)"; the Google link has the origin
    - Show route: OSRM 200 → "6.5 km by road · about 10 min by car", route drawn along the roads (screenshot)
    - Map View: table row → popup with the same panel and route
    - with `isSecureContext` forced off, the HTTPS message toast appears
    - no page errors
  - **Fix found in the screenshot:** the white casing was painted over the green line (canvas paints in creation order). The casing is now created first, and the line and dot are brought to the front.
- **Deploy:** `npm run build`. For production, set `MAP_ROUTING_URL` to a self-hosted or commercial OSRM server, and serve the app over HTTPS (location needs it).
- Files:
  - `resources/js/map-common.js`, `map.js`, `school-map.js`
  - `vite.config.js`
  - `resources/scss/app.scss`
  - `resources/views/schools/show.blade.php`
  - `app/Http/Controllers/SchoolController.php`, `MapController.php`
  - `config/inspection.php`
  - `.env.example`
  - `tests/Feature/SchoolDirectionsTest.php`
  - `PROJECT_DOCUMENT.md` (4.8)
  - `CLAUDE.md`
  - `NEW_MODULES_PLAN.md`
  - `ACTION.md`

## 75. V6 Module 9: rating shown for the school (2026-11-19)
- **Request:** V6 item "Rating Display the School".
- **Done:**
  - **`School::latestRatedInspection()`:** `hasOne()->ofMany(inspection_date, id)` over submitted inspections with a non-null score. Unlocked inspections drop out until they are resubmitted. `latestRating()` returns the `InspectionRating`.
  - **School page:** the gradient header shows the score (large), a band chip with its colour, and the inspection date, plus the change in points since the previous rated inspection.
  - **Find School results:** a score and band pill on every school (eager-loaded with `latestRatedInspection.rating`).
  - **Map View:**
    - School rows carry `rating` (the latest submitted inspection), shown in the popup and in a new table column.
    - **Colour schools by Status / Rating** switch: in rating mode the pins take the band colour and show the score inside; the legend lists the active scheme's bands plus "Not rated yet".
    - Area rows carry `rating`: the average score of the inspections submitted in the period (officer filter respected), banded with the active scheme (`MapDataService::areaRatings()`). It appears in the area table, popups and tooltips.
  - **SCSS:** rating pin and legend colours (generated from the rating colour map), header band chip, list pill.
- **Verification:**
  - New `SchoolRatingDisplayTest` (3 tests):
    - the newest submitted rating wins; an unlocked inspection falls back to the earlier one
    - the school page, list and map JSON (school row and area average: 1 rating this month, 2 over two months) and the map page switch
    - a school without a rating shows none
  - Full suite: 109 passed.
  - The local database had no rating yet: its one submission predates Module 3. I ran the documented `php artisan ratings:recalculate`, which rated it 66.68 % "Good" with scheme v2 (derived data only).
  - **Headless Chrome on Apache,** as demo officer 9000000031: the school header shows "66.7% · Good · 18 Nov 2026"; Find School shows the pill; the map in rating mode shows a yellow "67" pin with the band legend. No page errors.
  - **Fix found in the screenshot:** the rating pill was unreadable on the blue header and repeated the score. It was replaced by a white band chip with a colour dot.
- Files:
  - `app/Models/School.php`
  - `app/Services/MapDataService.php`
  - `app/Http/Controllers/SchoolController.php`, `MapController.php`
  - `resources/views/schools/show.blade.php`, `schools/_results.blade.php`, `map/index.blade.php`
  - `resources/js/map.js`
  - `resources/scss/app.scss`
  - `tests/Feature/SchoolRatingDisplayTest.php`
  - `PROJECT_DOCUMENT.md` (4.10)
  - `CLAUDE.md`
  - `NEW_MODULES_PLAN.md`
  - `ACTION.md`

## 76. V6 Module 10: rating report (2026-11-19)
- **Request:** V6 item "Rating Report".
- **Done:**
  - **`RatingReportService`:**
    - **Base query:** `JurisdictionService::inspections()` joined with `inspection_ratings`; submitted, scored, in the period.
    - **Filters:** district, block, cluster (all on `inspections.*`, so a filter outside the user's area simply returns nothing), band, school category, scheme version, sort.
    - **`summary()`:** rated, schools, average (banded), low and high; the band distribution (every active band, zeros included, plus bands only found in older versions); section averages from `sections` JSON, with N/A sections left out.
    - **`areas()`:** one level below the scope (district → block → cluster): rated, schools, average and band, a count per band.
    - **`schools()`:** each school's latest rated inspection in the set, the previous rating (any earlier date, same jurisdiction) and the change.
    - Sorting and paging (25 per page); filter lists limited to the user's area; Excel rows.
  - **`RatingReportController`:** routes `reports.ratings` (page), `.export` (Excel, also needs `export_excel`) and `.pdf`, under `can:view_reports`. Both exports are audited as `rating_report_exported`. "Rating Report" is in the sidebar.
  - **Page:**
    - tiles; band bars in the scheme colours with count and share (a click filters by that band)
    - section average bars (one hue, as one measure, following the dataviz rule) with values beside every bar and tooltips
    - area table with drill-down links; best and lowest five
    - school table: rating, previous, change arrows, sort select; phone list
  - **PDF:** official header and footer (like the inspection report), "Page X of Y", tiles, band and section tables with bars, area table, up to 500 schools.
- **Verification:**
  - New `RatingReportTest` (4 tests):
    - state view: period default, average, bands, sections, district rows, previous rating older than the period and the change, wider period
    - a District officer sees only their district (a filter for another district shows "No rated inspections"); a Cluster officer has no area table
    - band, cluster, sort, validation
    - Excel and PDF responses and audit; without `export_excel` the Excel gives 403; without `view_reports` the page and PDF give 403
  - Full suite: 113 passed.
  - **Headless Chrome on Apache,** as State officer 9000000001: the page shows 1 rated inspection, 66.7 % Good, the band bars, six section averages, North District and the school row. PDF 200 (read back: one page, header, tables, bars); Excel 200 xlsx. No page errors.
  - **Fix from the PDF:** empty bands drew a sliver bar; bars now draw only above zero.
- Files:
  - `app/Services/RatingReportService.php`
  - `app/Http/Controllers/RatingReportController.php`
  - `routes/web.php`
  - `resources/views/reports/ratings/index.blade.php`, `pdf.blade.php`
  - `resources/views/components/layouts/app.blade.php`
  - `resources/scss/app.scss`
  - `tests/Feature/RatingReportTest.php`
  - `PROJECT_DOCUMENT.md` (4.10)
  - `CLAUDE.md`
  - `NEW_MODULES_PLAN.md` (all V6 modules done)
  - `ACTION.md`

## 77. Project document updated with all V6 modules (2026-11-19)
- **Request:** update the project document with the current development (all V6 modules).
- **Done (`PROJECT_DOCUMENT.md`):**
  - **Header:** the scope now covers the New Modules and V6; date 19-11-2026; reading guide for section 9; related documents `NEW_MODULES_PLAN.md` and the V6 docx.
  - **Section 1:** the overview list adds login monitoring, district outlines, my location and road route, scheduling and the rating report, and URL / password security. The overview diagram adds the map tiles, the OSRM routing server and KML → `public/geo`.
  - **3.2 Rights:** rows for the login monitoring report, the Map View / schedules / rating report, and the rating report Excel.
  - **4.6 Reports:** the Rating Report and its Excel and PDF in the flow chart.
  - **5 Schema:** 38 tables. The ER diagram gains the schedules, ratings and `user_login_logs`. Notes on what V6 keeps outside the database: RSA keys, district outlines, URL token keys.
  - **6 Modules:** rows for URL encryption, password encryption, district outlines, my location and route, school rating display, and the rating report.
  - **7 Developer guide:**
    - code layout: new services, `app/Support`, `app/Models/Concerns`, `app/Rating`, middleware, commands, JS and views, `public/geo`, new tests
    - rules 10 (encrypted password fields) and 11 (ratings derived and versioned)
    - commands: `ratings:recalculate`, `security:keys`, `map:boundaries --check|--assign`
    - testing: 113 tests and the password-encryption test switch
  - **8 Statuses:** schedule, sign-in attempt and school rating rows.
  - **New section 9, "Modification V6":** a module table (item → what was built → what users see → section), a diagram, the new settings, a deploy checklist and known limits.
- **Verification:** the counts in the document match the code (113 tests, 38 tables); the schedule statuses were checked against `App\Enums\ScheduleStatus`.
- Files:
  - `PROJECT_DOCUMENT.md`
  - `ACTION.md`

## 78. V7 review and plan (2026-11-19)
- **Request:** review `Documents/Modification of the ApplicationV7.docx`, plan it, and build it one module at a time with a commit and push each.
- **V7 asks for:**
  1. Employee ID and identity proof not mandatory
  2. Mobile must start with 6–9
  3. Check DB and UI validation (skip what is already done)
  4. Grey dropdown placeholders and a standard file-size label
  5. Designation "Other" with a text box
  6. Uploads not lost when the form has errors
  7. A more visible privacy checkbox
  8. A cleaner rating card on the inspection view
- **Found:**
  - The server already checks mobile 6–9 on register and profile, but the UI and login/reset do not.
  - `employee_id` is NOT NULL.
  - The grey placeholder style exists only for the custom select widget.
  - The size hints are hard-coded.
- **Plan:** modules V7-1 … V7-5 in `NEW_MODULES_PLAN.md`.
- Files:
  - `NEW_MODULES_PLAN.md`
  - `ACTION.md`
  - `Documents/Modification of the ApplicationV7.docx`

## 79. V7-1: Employee ID and identity proof optional (2026-11-19)
- **Migration `2026_11_19_000006_make_employee_id_nullable_on_users`:** `users.employee_id` is nullable and still unique (several NULLs allowed); empty strings become NULL. `id_proof_path` was already nullable.
- **`RegisterRequest`:** `employee_id` and `id_proof` are `nullable`; format, length, unique and file type/size rules are kept.
- **`RegisterController`:** stores the identity proof only when a file is sent. A re-application without a new file keeps the earlier proof; a new file replaces and deletes the old one.
- **Admin user edit:** Employee ID is optional, with the same format rule as registration.
- **Views:**
  - register: "(optional)" labels and no `required`; Employee ID has a pattern
  - approvals/show and users/show: "Not provided" where empty
  - the approval page explains that no identity proof was uploaded instead of a broken viewer
  - the user list hides the empty "· ID"
- **Tests:** `RegistrationV7Test` (3 tests): register without either field; two officers without an ID; a duplicate or badly formatted ID is still refused; the approver sees "Not provided"; the ID-proof route returns 404; a re-application keeps the proof; the admin can clear the ID. Full suite: 116 passed.
- **Local DB:** MySQL backed up to the scratchpad, then migrated. `employee_id` is now `varchar(40) NULL UNI`.
- **Browser:** the register page shows "(optional)" on both fields.
- Files:
  - `database/migrations/2026_11_19_000006_make_employee_id_nullable_on_users.php`
  - `app/Http/Requests/RegisterRequest.php`
  - `app/Http/Controllers/Auth/RegisterController.php`
  - `app/Http/Controllers/Admin/UserController.php`
  - `resources/views/auth/register.blade.php`
  - `resources/views/admin/approvals/show.blade.php`
  - `resources/views/admin/users/show.blade.php`
  - `resources/views/admin/users/index.blade.php`
  - `tests/Feature/RegistrationV7Test.php`

## 80. V7-2: Mobile 6–9 everywhere, and the validation audit (2026-11-19)
- **Mobile (6, 7, 8 or 9 first):**
  - **UI:** new `resources/js/mobile-input.js` (imported in `app.js`) for every `input[data-mobile]`: register, login, forgot and reset password, profile mobile change.
    - digits only, at most 10
    - a wrong first digit is refused as it is typed, with "Mobile number must start with 6, 7, 8 or 9."
    - a short number shows "must be 10 digits (n entered)"
    - the submit is blocked before password encryption runs
    - inputs carry `pattern="[6-9][0-9]{9}"`
    - the old digits-only handler in `register.js` was removed
  - **Server:** registration and profile already had `regex:/^[6-9]\d{9}$/` (kept, clearer message). **Added** to login, forgot password and reset password.
  - **Database:** migration `2026_11_19_000007_add_mobile_check_constraints` adds `CHECK (mobile REGEXP '^[6-9][0-9]{9}$')` on `users.mobile` and `mobile_change_requests.new_mobile`.
    - MySQL/MariaDB only; skipped on SQLite tests.
    - It refuses to run if existing rows are invalid; the local DB had 0.
    - Verified: `UPDATE users SET mobile='5123456789'` fails with error 4025.
- **Audit of the other registration fields:**

  | Field | Already correct (skipped) | Fixed |
  |---|---|---|
  | name | required, max 120 = column | **New:** min 3, letters / spaces / `. ' -` only (server regex, UI pattern; also the admin edit) |
  | email | `email:rfc`, max 150 = column | UI `maxlength="150"` |
  | password | strength rule, confirmation | max 64 (register, change, reset; UI maxlength), within the 214-byte RSA limit; hint text updated |
  | designation, level, state, district, block, cluster | DB `exists` and hierarchy check | — |
  | Employee ID | format, max 40 = column, unique | — |
  | photo, ID proof | type and size | — |
  | captcha, privacy | — | — |
- **Tests:** `RegistrationV7Test` gains 2 tests: a mobile starting 5 or 0, short or non-digit, is refused on register, login, forgot and profile; 6–9 is accepted; name, email and password limits. Full suite: 118 passed.
- **Browser (login page):**
  - typing "5" leaves the field empty with the message
  - submitting 5 digits is blocked with "(5 entered)"
  - a real login (9000000031) still reaches the dashboard
- Files:
  - `resources/js/mobile-input.js`
  - `resources/js/app.js`
  - `resources/js/register.js`
  - `resources/views/auth/{login,register,forgot-password,reset-password}.blade.php`
  - `resources/views/profile.blade.php`
  - `app/Http/Controllers/Auth/{LoginController,PasswordController}.php`
  - `app/Http/Controllers/{ProfileController,Admin/UserController}.php`
  - `app/Http/Requests/RegisterRequest.php`
  - `database/migrations/2026_11_19_000007_add_mobile_check_constraints.php`
  - `tests/Feature/RegistrationV7Test.php`

## 81. V7-3: Form polish: grey dropdown placeholders, standard file size, "Other" designation, highlighted consent (2026-11-19)
- **Dropdown placeholders:** a native `.form-select` still on its empty "Select …" option is shown in the same grey (`#9aa5b5`, normal weight) as text placeholders. It uses CSS `:has(> option[value=""]:checked)`, so the location cascade's programmatic changes follow without JS. This applies app-wide; the open list keeps dark option text.
- **Standard file size:**
  - New `App\Support\FileSize::human()` ("1 MB", "1.5 MB", "500 KB", binary units like Laravel's `max:`) and `types()` ("JPG / PNG").
  - The register chips and the server messages (`photo.max`, `id_proof.max`) are now built from `config('inspection.employee_photo|id_proof')` instead of hard-coded "1 MB / 2 MB".
- **"Other" designation:**
  - The master value `designation / other` already existed.
  - Migration `2026_11_19_000008_add_designation_other_to_users` adds `users.designation_other` (120).
  - Register and admin edit: choosing Other reveals a required text box (generic `select[data-other-value]` toggle in `app.js`; the box is disabled and not sent otherwise).
  - `RegisterRequest` / `UserController`: required when Other; letters and `. , ( ) / & ' -` only; max 120. Switching away clears it.
  - `User::designation_label` returns the typed text for Other, else the master label. It replaced `designation?->label` in 13 views (17 places) and in the inspection Excel export, the start-guard message, the map officer list and the login report. Eager loads now include `code`.
- **Privacy consent:**
  - The checkbox sits in a highlighted panel (`.reg-consent`): tinted blue with an accent bar and a shield icon, a bigger 1.35 rem checkbox, a bold label and a hint line.
  - It turns green when ticked.
  - Submitting unticked stops the submit, turns it red with a short shake (none with reduced motion) and scrolls to it.
- **Fix during the check:** the mobile and consent submit checks only call `preventDefault` (no `stopImmediatePropagation`), so all problems are flagged together; the password encryptor already skips prevented submits.
- **Tests:** `RegistrationV7Test` gains 2 tests: Other requires text, rejects markup, is trimmed and shown on the approval page; a normal designation ignores the text; the admin switching away clears it; FileSize formats; chips and messages follow config.
  - The test class now bypasses the register throttle, and every successful registration asserts the redirect to login, so a 429 can no longer pass silently.
  - Full suite: 120 passed.
- **Browser (register):**
  - empty designation and district text `rgb(154,165,181)`, the same as placeholders
  - Other shows the required box
  - an unticked submit flags the panel red, a ticked one turns it green
- Files:
  - `app/Support/FileSize.php`
  - `app/Models/User.php`
  - `app/Http/Requests/RegisterRequest.php`
  - `app/Http/Controllers/Auth/{RegisterController,LoginController}.php`
  - `app/Http/Controllers/Admin/{UserController,LoginReportController}.php`
  - `app/Http/Controllers/ReportController.php`
  - `app/Services/{MapDataService,LoginMonitorService,InspectionStartGuard}.php`
  - `database/migrations/2026_11_19_000008_add_designation_other_to_users.php`
  - `resources/views/auth/register.blade.php`
  - `resources/views/admin/users/show.blade.php`
  - 13 views using `designation_label`
  - `resources/js/{app,register,mobile-input}.js`
  - `resources/scss/app.scss`
  - `tests/Feature/RegistrationV7Test.php`

## 82. V7-4: Uploaded files are kept when the registration form has errors (2026-11-19)
- **Problem:** a browser cannot refill a file input, so after any validation error (duplicate mobile, wrong CAPTCHA …) the photo and ID proof had to be chosen again.
- **New `App\Services\RegisterUploadStash`:**
  - On a failed submit (`RegisterRequest::failedValidation`), each file that passed its own rules is stored on the private disk in `register-tmp/` under a random name.
  - It is referenced only in the visitor's session (`register_uploads.<field>`: path, name, size, mime).
  - A new file replaces the kept one; a file that failed its own rules is not kept.
- **Next submit:**
  - The form carries a hidden `keep_photo` / `keep_id_proof` flag. The photo is no longer `required` while one is kept.
  - `RegisterController::store` moves the kept file to `employee-photos/` or `id-proofs/` (`claim()`), then clears the session.
  - Pressing Remove sets the flag to 0, and the kept file is deleted on the next submit.
- **Preview:** new guest route `register.kept` (`/register/kept/{photo|id_proof}`) serves only the file named in the visitor's own session, with `no-store` and `nosniff`.
  - `register.js` shows the kept file in the upload zone (thumbnail or PDF icon, name, size, "kept from your last attempt") with a green solid border (`.upload-zone.is-kept`).
  - Cancelling the file picker keeps it.
- **Clean-up:** `php artisan register:purge-tmp [--hours=24]` deletes kept files of abandoned forms; scheduled daily at 01:00 in `routes/console.php`.
- **Tests:** `RegistrationV7Test` gains 3 tests:
  - kept after a duplicate-mobile error, previewable, then used and moved by the next submit with no files
  - Remove makes the photo required again and deletes the file
  - an invalid file is not kept
  - another session gets 404, and an unknown field is not routed
  - the purge removes only old files
  - Full suite: 123 passed.
- **Browser:** submitted with an existing mobile and a wrong CAPTCHA. Both errors were shown and the photo zone showed the kept photo (served by `/register/kept/photo`, 300 px). The local `register-tmp` was emptied afterwards with `register:purge-tmp --hours=0`.
- Files:
  - `app/Services/RegisterUploadStash.php`
  - `app/Http/Requests/RegisterRequest.php`
  - `app/Http/Controllers/Auth/RegisterController.php`
  - `routes/web.php`
  - `routes/console.php`
  - `resources/views/auth/register.blade.php`
  - `resources/js/register.js`
  - `resources/scss/app.scss`
  - `tests/Feature/RegistrationV7Test.php`

## 83. V7-5: Rating card on the inspection report redesigned (2026-11-19)
- **Request:** make the school performance rating on the inspection view more attractive, elegant and clean.
- **New `resources/views/inspections/partials/rating.blade.php`** (same data as before; the PDF is unchanged):
  - **Hero:**
    - a panel tinted in the band colour
    - a larger score dial
    - an "Overall rating" kicker with the band pill and weighted points
    - the **change since the previous rating** (↑ green / ↓ red, in points, with the previous date), or "First rated inspection of this school"
  - **Band scale:** the scheme bands as a segmented bar, with the current band solid, a ▼ marker at the score, the band thresholds, and a named legend (colour is never the only cue).
  - **Summary:** shown in a soft quote box.
  - **Section tiles:** title, % (tabular figures), a thin bar in the band colour, and the band name; N/A tiles are muted.
  - **Strengths, weaknesses, improvement areas:** tinted cards with an icon header, a count, and icon rows. "Show all n" opens items past 5.
  - **Print:** the full lists are shown and the toggles are hidden.
- **New `Inspection::previousRating()`:** the rated, submitted inspection of the same school just before this one (earlier date, or the same date with a lower id). It excludes drafts, cancelled and soft-deleted inspections. `ReportController::reportData` passes `previousRating`, and the scheme is loaded with its `bands`.
- **SCSS:** `.rating-card .rt-*`; `.rt-hero.rating-<band>` sets the colour.
- **Fixes during the check:**
  - Bootstrap `.collapse` was overridden by `display:flex` on the list rows; `li.collapse:not(.show)` is now hidden.
  - The change line now wraps cleanly.
- **Tests:** new `RatingCardTest`. The previous-rating link, the hero, scale and section markup, and the "+n pts since last inspection" text with the date are checked. The first inspection shows "First rated inspection". A cancelled earlier inspection does not count. Full suite: 124 passed.
- **Browser:** inspection #5 was rendered in-process as the State Admin (desktop 1400 px and phone 390 px). A second render used an in-memory previous rating (view composer; nothing saved) to check the change badge.
- Files:
  - `resources/views/inspections/partials/rating.blade.php`
  - `app/Models/Inspection.php`
  - `app/Http/Controllers/ReportController.php`
  - `resources/scss/app.scss`
  - `tests/Feature/RatingCardTest.php`

## 84. Project document and CLAUDE.md updated for V7 (2026-11-19)
- **`PROJECT_DOCUMENT.md`:**
  - **Header:** now covers V7; the reading guide points to section 10.
  - **4.1 Registration flow:** the Other designation, Employee ID and ID proof optional, mobile 6–9, consent, and files kept on error.
  - **5 Schema:** `users` gains `designation_other`, nullable `employee_id` and the mobile CHECK.
  - **7 Tests:** 124 tests.
  - **New section 10, "Modification V7":** a module table, the validation audit table (already in place vs added), the kept-upload flow diagram, a deploy checklist (migrate, build, scheduler for `register:purge-tmp`) and known limits.
- **`CLAUDE.md`:** layout notes for V7 (`designation_label`, `mobile-input.js`, `FileSize`, `RegisterUploadStash`, `previousRating`).
- **Local data:** MySQL is migrated with the 3 V7 migrations. A backup taken before V7 is in the session scratchpad. One demo login (9000000031) was used, which added login-log rows. The kept upload from the browser check was purged. No other data changed.
- Files:
  - `PROJECT_DOCUMENT.md`
  - `CLAUDE.md`
  - `ACTION.md`

## 85. T1: Trained/untrained and male/female must equal Total teachers (2026-11-19)
- **Request:** on step 2 (Teacher/Student Information), trained and untrained teachers were not checked against Total teachers, and neither were male and female. The user chose a **blocking** rule.
- **Rule:**
  - When Total teachers (TSI_04–08) and all four breakdown fields are entered, Male trained + Male untrained + Female trained + Female untrained (TSI_10–13) must equal Total teachers. This one check covers both Trained + Untrained and Male + Female.
  - Teachers present (TSI_09) more than Total teachers is now blocking too (it was only a warning).
- **Server:**
  - `QuestionnaireDefinition`: `TEACHER_TOTAL_CODES`, `TEACHER_BREAKDOWN_CODES`, `teacherBreakdown()`.
  - `InspectionValidator::teacherConsistency()` feeds `missing()[2]` (codes TSI_10 / TSI_09), so the step badge, the "still to fill" modal, the review page and Submit all block. The message reads, for example: "Trained (7) + Untrained (2) = 9 and Male (4) + Female (5) = 9, but Total teachers is 10."
  - `warnings()` keeps only the absentee-rows hint.
- **UI:**
  - A new live box on step 2 after TSI_13 shows Trained, Untrained, Male and Female. It turns red ("Does not match Total teachers (10)") or green ("Matches"); it is filled by `wizard.js` `recalc()`.
  - "Show me" now shows the rule's own message instead of "This question needs an answer".
  - The report (`step-answers`) and the PDF show the trained/untrained and male/female totals.
- **Tests:**
  - New `TeacherTotalsTest` (5 tests): the sums; a mismatch blocks the step and Submit and is shown on the review page; fixing a number clears it; present > total blocks; no error until the numbers are entered; the box is on the page.
  - `InspectionFlowTest`: the complete-all fixture now has a consistent breakdown, and "present > total" is asserted as blocking.
  - Full suite: 129 passed.
- **Browser:** demo officer 9000000031, draft #8, step 2. 9 vs 10 showed the red box ("10 left in this step"); after correcting it, the box turned green. MySQL was backed up first and restored afterwards (draft #8 has no TSI answers again).
- Files:
  - `app/Questionnaire/QuestionnaireDefinition.php`
  - `app/Questionnaire/InspectionValidator.php`
  - `resources/views/inspections/step.blade.php`
  - `resources/views/inspections/partials/step-answers.blade.php`
  - `resources/views/inspections/pdf.blade.php`
  - `resources/js/wizard.js`
  - `resources/scss/app.scss`
  - `tests/Feature/TeacherTotalsTest.php`
  - `tests/Feature/InspectionFlowTest.php`
  - `NEW_MODULES_PLAN.md`

## 86. T2: Working without network: keep on the device, sync automatically (2026-11-19)
- **Request:** "If network is not available, what should happen?" The app is a responsive web application (browser on desktop and phone).
  - **Before:** an answer saved with no network was lost ("will be saved when you edit it again"); photos waiting to upload were lost on reload.
- **Built:**
  - **New `resources/js/offline-queue.js`:**
    - IndexedDB store `si-offline` with `ops` (unsent requests, in order; a newer save of the same answer replaces the older one) and `photos` (blob and stamp data), per inspection
    - `syncAll()` replays them in order: it stops while offline, drops a change the server refuses (422/403/404) with a message, and sends the officer to sign in on 401/419
    - the banner: offline / sending / all saved
  - **`wizard.js`:**
    - `queue(fn, onInvalid, op)` keeps a failed save on the device when there is no network, and later saves join that queue so the order is kept
    - ops for answers, remarks, suggestions, new absent-teacher and classroom rows (`row_uid`) and fund years
    - provisional rows are shown as "Saved on this device · waiting for network"
    - kept answers are put back on screen when the page opens
    - sync on `online`, every 20 s and on load
    - editing or removing a saved row needs the network (clear message)
  - **`camera.js`:**
    - each photo is written to the device before upload and removed after the server confirms it
    - with no network it is shown as "On this device · waiting for network" right away (no 30 s retry loop)
    - it is restored and uploaded after a reload
  - **`review.js`:** Submit is locked while anything is still on the device. It syncs and then refreshes, and retries every 20 s.
  - **Server:**
    - migration `2026_11_19_000009_add_row_uid_to_inspection_rows` (nullable `row_uid`, unique per inspection, on absent teachers and classroom observations)
    - `InspectionController::addRow()` uses `createOrFirst`, so a replayed row is stored once
    - answers, fund years, suggestions and photos were already safe to replay
  - **Config:** `SI.inspectionKey` on the step page and `data-inspection-key` / `data-evidence-url` on the submit form.
  - **SCSS:** `.offline-bar`, `.rs-item.is-pending`, `.save-indicator.offline`.
- **Tests:** new `OfflineReplayTest` (2 tests):
  - a repeated answer is stored once
  - a repeated row with the same `row_uid` (any case) is stored once; without one, rows are added as before
  - a bad `row_uid` gets 422; the same uid in another inspection is a separate row
  - the pages carry the offline key
  - Full suite: 131 passed.
- **Browser (demo officer 9000000031, draft #8; MySQL backed up and restored afterwards):**
  1. Offline on step 2, two answers: "Saved on this device" and the banner "No network. 2 changes saved on this device".
  2. The tab was closed and reopened online: the value came back and was sent (DB had TSI_01 = 77, TSI_02 = 3).
  3. Offline gallery photo on step 7: "On this device · waiting for network".
  4. The review page with the server unreachable: Submit was locked with "1 photo saved on this device still to send…".
  5. Back online, the photo uploaded.
  6. Offline absent-teacher row: a dashed pending row, then after sync one real row with its `row_uid`.
  7. The test photos' files were deleted and the DB restored, then `row_uid` migrated again.
- **Docs:** `PROJECT_DOCUMENT.md` section 11 (rules table, offline behaviour table, flow, limits, deploy) and `CLAUDE.md` notes.
- Files:
  - `resources/js/offline-queue.js`
  - `resources/js/{wizard,camera,review}.js`
  - `resources/views/inspections/{step,review}.blade.php`
  - `resources/scss/app.scss`
  - `app/Http/Controllers/InspectionController.php`
  - `app/Models/{InspectionAbsentTeacher,InspectionClassroomObservation}.php`
  - `database/migrations/2026_11_19_000009_add_row_uid_to_inspection_rows.php`
  - `tests/Feature/OfflineReplayTest.php`
  - `PROJECT_DOCUMENT.md`
  - `CLAUDE.md`
  - `NEW_MODULES_PLAN.md`

## 87. Teacher breakdown: less than or equal to Total teachers (2026-11-19)
- **Request:** "Trained / untrained teachers is less or equal to total teacher". The T1 rule (#85) required the breakdown to **equal** Total teachers; it now requires it to be **less than or equal**.
- **Rule now:**
  - Male trained + Male untrained + Female trained + Female untrained (TSI_10–13) ≤ Total teachers (TSI_04–08). Trained + Untrained and Male + Female are the same four numbers, so both pairs are covered.
  - Equal or fewer is accepted, for example when the training status of some teachers is not known.
  - More than the total is still **blocking**: step 2 stays incomplete and Submit is refused.
  - Teachers present (TSI_09) ≤ Total teachers is unchanged (blocking).
- **Changed:**
  - `InspectionValidator::teacherConsistency()`: `sum > total` instead of `sum ≠ total`. The message reads "Trained (9) + Untrained (3) = 12 and Male (5) + Female (7) = 12, which is more than Total teachers (10). They must be less than or equal to Total teachers."
  - **Live box (`wizard.js`, `step.blade.php`):** "Each pair must be less than or equal to Total teachers"; green "Within Total teachers (n)" or red "More than Total teachers (n)".
  - **Report and PDF:** "more than Total teachers" / "Total (not more than Total teachers)".
  - **Comments:** `QuestionnaireDefinition`.
- **Tests:** `TeacherTotalsTest`:
  - more than the total blocks the step and Submit, and the review page shows the message
  - less (9 of 10), zero and equal are accepted
  - one above the total (11) blocks
  - Full suite: 132 passed.
- **Browser:** demo officer 9000000031, draft #8, step 2. 12 vs 10 showed red "More than Total teachers (10)"; 8 vs 10 showed green "Within Total teachers (10)". MySQL was backed up and restored.
- Files:
  - `app/Questionnaire/InspectionValidator.php`
  - `app/Questionnaire/QuestionnaireDefinition.php`
  - `resources/js/wizard.js`
  - `resources/views/inspections/step.blade.php`
  - `resources/views/inspections/partials/step-answers.blade.php`
  - `resources/views/inspections/pdf.blade.php`
  - `tests/Feature/TeacherTotalsTest.php`
  - `PROJECT_DOCUMENT.md` (11.1)
  - `CLAUDE.md`
  - `NEW_MODULES_PLAN.md`

## 88. Technical documentation set from `Documents/Documentation.docx` (2026-11-19)
- **Request:** review the brief `Documents/Documentation.docx` and produce the documentation it asks for, based on the code, the database and the UI, with gaps and recommendations.
- **Method:** read-only. The source, migrations, routes (`route:list`, 102 routes), views and JS were reviewed, and the live schema was scanned through `information_schema` (38 tables, 53 FKs, 10 CHECKs; structure only, no row data). No application code, data or configuration was changed.
- **Added** (`documentation/`):
  - `README.md` (index)
  - `Database_Architecture_and_Schema.md` (full column tables generated from the live schema)
  - `Project_File_Structure_and_Technical_Architecture.md`
  - `School_Inspection_Mechanism.md` (full questionnaire v2 inventory)
  - `User_Roles_and_Permissions.md`
  - `UI_Pages_and_Application_Modules.md`
  - `System_Architecture_and_Workflow.md` (Mermaid diagrams)
  - `Technical_Audit_and_Recommendations.md`
  - `Future_Recommendations_Gaps_and_Roadmap.md`
  - `School_Inspection_Technical_Documentation.docx`: all chapters combined, with the screenshots, and the diagrams written out as steps. Built with docx-js from a scratch script; the project has no new dependency.
- **Main findings** (details in the audit):
  - No security headers (CSP/HSTS/frame-ancestors).
  - `retention_years` is not applied.
  - The pending approvals count runs on every page.
  - The rating export uses `view_reports`, not `export_excel`.
  - `questionnaire_version` defaults to 1 in the DB.
  - Unused npm package `@fontsource-variable/inter`.
  - The raw KML is in `public/`.
  - `CLAUDE.md` wording: "six-step", `OtpService`.
  - No CI, backups or monitoring in the repository.
  - The brief itself is wrong on port 8000 (it is 8010), jQuery, `config/permission.php`, `routes/api.php`, and the role names.
- **Verification:**
  - Every file path, class and route name cited in the documents was checked to exist; the only ones missing are the items the brief expected and the documents state are absent.
  - No credentials, demo passwords or personal data appear in the documents.
  - The `.docx` XML parses: 8 chapters, 84 tables, 17 images, no zip folder entries. It was not rendered visually, because Word and LibreOffice are not installed.

## 89. "Product Details" folder and one combined Word document (2026-11-19)
- **Request:** put all the documents into one Product Details folder and make a single Word document.
- **Moved** (`git mv`, history kept) into `Product Details/`:
  - the technical set (formerly `documentation/`)
  - `PROJECT_DOCUMENT.md`, `INSPECTION_FORM_DOCUMENT.md`, `NEW_MODULES_PLAN.md`, `DB_INDEXING.md`, `LOAD_TEST.md`, `BROWSER_SUPPORT.md`
  - Root `README.md`, `CLAUDE.md` and `ACTION.md` stay at the root.
- **Copied:** `Documents/*` into `Product Details/Requirements/`, without the Office lock file. `Documents/` stays the master copy, because code comments cite "PRD x.y".
- **Single Word file:** `Product Details/School_Inspection_Product_Details.docx`. It replaces `School_Inspection_Technical_Documentation.docx` and contains:
  - a title page and a table of contents
  - Part A, the technical documentation (A1–A8)
  - Part B, the project documents (B1–B6, with their screenshots)
  - Part C, an appendix table of the requirement files
  - Mermaid diagrams written out as numbered steps
- **Builder:** `tools/docs/build-product-docx.cjs` with its own `package.json` (docx, marked). Its `node_modules` is git-ignored, and the application's dependencies are unchanged. Rebuild with `cd tools/docs && npm install && npm run build`.
- **References updated:**
  - `CLAUDE.md`: the New Modules line, and a new "Product documents" section.
  - Comments that named the moved files, now pointing to `Product Details/...`: `AppServiceProvider`, `config/inspection.php`, two migrations, `LoadTestSeeder`, two tests, `tools/loadtest/loadtest.php`, `vite.config.js`, `postcss.config.js`.
  - Screenshot links in `INSPECTION_FORM_DOCUMENT.md` (now `../docs/screenshots/`).
  - The folder README and the file tree in A2.
  - Older ACTION.md entries are left unchanged, as the history of that time.
- **Verification:**
  - `php -l` is clean on every PHP file touched (comment-only changes).
  - The `.docx` XML parses: 15 chapters plus the appendix, 141 tables, every image resolved, no zip folder entries.
  - It was not rendered visually, because Word and LibreOffice are not installed.

## 90. Report: installed packages and libraries with use cases (2026-11-19)
- **Added:** `Product Details/Installed_Packages_and_Libraries.md`, also chapter **A9** of the combined Word file and listed in the folder README. It covers:
  - the runtime and the PHP extensions each package needs
  - Composer packages: 6 production and 7 development direct, 129 installed
  - npm packages: 14 direct, 157 installed, split into bundled libraries and build tools
  - the docs builder
  - the browser APIs used, and the external services
  - a table from each use case to the packages behind it
  - recommendations
- **Facts checked:**
  - Versions come from `composer.lock` and `package-lock.json`.
  - Where each package is used was found by grepping `app/`, `resources/` and `tests/`.
  - `composer audit` and `npm audit` found no vulnerabilities.
  - `composer outdated --direct` lists laravel/framework 12.73 (patch), sail 1.71 and tinker 3.2 (major); `npm outdated` lists nothing.
  - Installed but unused: `@fontsource-variable/inter`, `laravel/sail`, `mockery/mockery`.
- **Also:** the new `Documents/Modification of the ApplicationV8.docx` was copied to `Product Details/Requirements/`, and the Word file was rebuilt (`tools/docs`). It now has 154 tables, A9 and the V8 row in the appendix, and its XML parses.
- The Word file was locked by another program at first. It was not among the documents open in Word, and the lock was released before the rebuild.
- A PDF render through Word automation failed (COM interface error; Word shows "Product Activation Failed"). The hidden Word instance that the attempt started was stopped.

## 91. Modification V8: action taken (2026-11-19)
- **Request:** `Documents/Modification of the ApplicationV8.docx` (8 items; also copied to `Product Details/Requirements/`). Decisions taken with the user:
  - item 3: chosen values look like the other fields, and "All …" stays placeholder grey;
  - item 2: a strip on top of the map with live counts;
  - item 7: a new Daily report tab.

| # | Request (V8) | Action taken | Files | Verified by |
|---|---|---|---|---|
| 1 | Map: school details popup more elegant and attractive | Redesigned the school popup: header coloured by status, name and UDISE, status and rating chips, icon rows, a "Last inspection" box, the directions panel, and side-by-side Open school / View report. The area popup has the same style, with count tiles, a coverage bar, rating and officers | `resources/js/map.js`, `resources/scss/app.scss` | `npm run build`; the JS builds |
| 2 | Map: inspected / not inspected colour key on top | `.map-keybar` above the map: Inspected · In progress · Not inspected with live counts and the period; it switches to the rating bands with "Colour by Rating" | `resources/views/map/index.blade.php`, `map.js` (`renderStats()`), `app.scss` | `MapViewTest` (`map-keybar`, `data-key="inspected"`) |
| 3 | Map: filter dropdown selected item colour like the other fields | `.map-filters`: chosen values use the same colour and weight as the date and School inputs; the empty "All …" option stays placeholder grey; disabled selects are muted | `app.scss` | build |
| 4 | Map: filter selects small, as standard | All map filters use `form-select-sm` / `form-control-sm` (34 px), and the Reset button is `btn-sm`. Phones keep 16 px text (no iOS zoom) | `map/index.blade.php`, `app.scss` | `MapViewTest` (`form-select form-select-sm`) |
| 5 | Rating Report: attractive, clean, elegant; bands and average by section | A donut of the band shares with the average in the middle and a filterable legend (count, share, bar). Section rows have the step icon, percent, band pill and a band-coloured bar. Tiles wrap their text inside the box. `RatingReportService::sections()` now adds `band`/`color` per section | `resources/views/reports/ratings/index.blade.php`, `app/Services/RatingReportService.php`, `app.scss` | `RatingReportTest` (donut, `conic-gradient`, `sec-score`, band of each section equals `RatingCalculator::band()`) |
| 6 | Remove the device location (start) and (end) from the PDF | Removed the "Device location (start / submit)" rows and the unused `$loc` helper. The data is still stored and shown on the web report; photo GPS stamps are unchanged | `resources/views/inspections/pdf.blade.php` | `InspectionFlowTest`: the PDF HTML, captured with a view composer, has no "Device location"; the PDF still downloads |
| 7 | Daily Login Report with the current login status | New tab `/admin/login-report/daily` with Excel export (`state_admin`). One row per active officer for a date: first sign-in, last activity, sign-ins, minutes online, refused attempts, and the current status (Online now / Signed out / Timed out / Not signed in (today) / Never signed in). Tiles, filters (date ≤ today, level, district, status, officer), phone list cards. Tabs "Sign-in log / Daily report" | `app/Services/LoginMonitorService.php` (`DAILY_STATES`, `dailyFilters()`, `daily()`, `dailySummary()`, `dailyMinutes()`), `app/Http/Controllers/Admin/LoginReportController.php` (`daily`, `dailyExport`), `routes/web.php`, `resources/views/admin/login-report/daily.blade.php`, `tabs.blade.php` | `LoginReportTest::test_daily_report_shows_every_officer_with_the_current_status` (all five states, counts, minutes, filters, date validation, export, 403 for officers) |
| 8 | Login Monitoring: top card text inside the box; clean design | `<x-stat-card>` has new optional `wrap` and `hint` props (off by default, so other pages are unchanged): the label wraps onto 2 lines inside the box, with a hint line, coloured edge and equal heights; the grid is `col-6 col-md-4 col-xl-2`. Added the missing `.tone-orange` icon colour | `resources/views/components/stat-card.blade.php`, `resources/views/admin/login-report/index.blade.php`, `app.scss` | `LoginReportTest` (page renders); build |

- **Documents updated:**
  - `Product Details/PROJECT_DOCUMENT.md` (new section 12)
  - `UI_Pages_and_Application_Modules.md` (daily route, PDF note)
  - `School_Inspection_Mechanism.md` (PDF note)
  - `CLAUDE.md` (login monitoring: daily tab, `wrap` tiles, PDF)
  - The combined Word file was rebuilt (`tools/docs`).
- **Verification:**
  - `php artisan config:clear && php artisan test`: **133 passed** (1,669 assertions).
  - `npm run build` passed.
  - No migration is needed.
- **Not verified:** a visual check in a browser. The Claude in Chrome tools were not available in this session. Please look at the Map View popup, the colour key and the filters, the Rating Report, the Login Monitoring tiles and the Daily report tab on a desktop and a phone.

## 2026-11-20 · Fast caching: Redis (in memory) with file fallback, cached approvals badge

- **Question:** which cache is best for speed?
  - **OPcache** keeps compiled PHP in memory. It is the biggest gain (LOAD_TEST finding 3).
  - **Redis** keeps the application cache and sessions in memory, shared by all threads and servers.
  - When Redis is missing, the **file** cache is the fastest option.
- **Done:**
  - `config/cache.php`:
    - New `failover` store (`redis` → `file`), now the default (`CACHE_STORE=failover`).
    - Without the phpredis extension, `failover` and `redis` resolve to `file`, so no request waits on Redis.
  - `config/session.php`: the default driver is `redis` when phpredis is loaded, else `file` (was `database`). Sessions use Redis DB 0 and the cache uses DB 1.
  - `config/database.php`: redis `timeout` and `read_timeout` (`REDIS_TIMEOUT`, 0.5 s), so a dead Redis fails over quickly.
  - Audit P1: `ApproverResolver::pendingCountFor()` caches the badge count per approver for 60 s. `flushPendingCounts()` bumps `approvals:pending:version` on every `RegistrationRequest` saved or deleted (`booted()`).
    - Used by the layout and `DashboardController`.
    - The Approvals page stays live.
  - New `php artisan cache:status` (`app/Console/Commands/CacheStatus.php`): shows the cache store, the session driver, Redis ping, OPcache and whether the config is cached.
  - `.env` / `.env.example`:
    - `CACHE_STORE=failover`
    - `SESSION_DRIVER` is now automatic
    - `REDIS_TIMEOUT`
    - production checklist lines for Redis and OPcache
- **Docs:** `LOAD_TEST.md` (findings 5, recommendations 2 and 6), `Technical_Audit_and_Recommendations.md` (P1, P3, R6, R8), `Future_Recommendations_Gaps_and_Roadmap.md` (T5, quick fix), `CLAUDE.md`. Word file rebuilt.
- **Verification:**
  - New `PendingApprovalCountCacheTest`, 4 tests:
    - the second call runs no query
    - a new registration refreshes the count
    - approve and reject refresh the count
    - the layout badge shows the count
  - Full suite: **137 passed** (1,679 assertions).
  - `cache:status` locally shows file / file (phpredis not loaded).
  - `php artisan serve`: the login page returns 200 and session files are written.
- **Not done:**
  - OPcache was not enabled in `D:\xampp\php\php.ini`. It is a system file, so it was left for the user's approval.
  - Redis and phpredis are not installed locally.
  - The failover with a live Redis was not tested.

## 2026-11-20 · One active officer per cluster (user request)

- **Request:** an officer cannot register for a cluster that already has an active officer.
  - Show the existing officer and ask the applicant to contact the higher officials.
  - If the existing officer is not active, create the user as usual.
- **Done:**
  - New `app/Services/AreaOfficerGuard.php`:
    - `holder()` and `holderFor()` find the Active or Locked officer of the same level whose current jurisdiction is that cluster.
    - `message()` builds the standard text: name, designation, masked mobile, and whom to contact.
  - `config/inspection.php` `single_officer_levels` (`SINGLE_OFFICER_LEVELS`, default `cluster`; can be extended to block or district).
  - `RegisterRequest::after()`:
    - errors on `cluster_id` and `area_taken`
    - audit `registration_blocked_area_taken`
    - the re-applying rejected user is excluded
  - `auth/register.blade.php`: warning box "Officer already registered".
  - `ApprovalController`:
    - `show` disables Approve and explains why.
    - `approve` refuses the request (flash error).
    - Reject still works.
  - `UserController`: `toggleStatus` (re-activate) and `transfer` are refused into an occupied cluster.
  - `admin/approvals/show.blade.php`: warning box.
  - Docs: `PROJECT_DOCUMENT.md` section 13, `CLAUDE.md` domain rule 15, Word file rebuilt.
- **Verification:**
  - New `OneOfficerPerClusterTest`, 5 tests:
    - registration refused, the message names the officer and masks the mobile, no user created, audited
    - allowed when the existing officer is Inactive, Rejected or Pending
    - other clusters and the Block level are unaffected
    - approval blocked; reject still works
    - re-activation and transfer refused
  - Full suite: **142 passed** (1,708 assertions).
- **Found in the local MySQL data (not changed):** Hillview Cluster 1 already has 3 active Cluster officers (Test User, GUNAJIT NATH, Rohit Nath). The demo pending officer Amit Phukan (Riverside Cluster 1, where Arjun Saikia is active) can no longer be approved; this is the intended behaviour.

## 2026-11-20 · Duplicate-officer rule: Cluster level only (user clarification)

- **Request:** only Cluster-level officers are prevented from being added twice in the same area. Other users (State, District, Block) can register and be approved as usual.
- **Done:**
  - `AreaOfficerGuard` is now fixed to the Cluster level: `appliesTo()` is true only for `UserLevel::Cluster`.
  - The configurable level list was removed: `config('inspection.single_officer_levels')` / `SINGLE_OFFICER_LEVELS` no longer exist, so the rule cannot be extended by an `.env` edit.
  - `RegisterRequest` always reports the error on `cluster_id`.
  - Approval, re-activation and transfer are unchanged; they return no holder for non-cluster levels.
- **Files:** `app/Services/AreaOfficerGuard.php`, `config/inspection.php`, `app/Http/Requests/RegisterRequest.php`, `tests/Feature/OneOfficerPerClusterTest.php`, `CLAUDE.md` (rule 15), `Product Details/PROJECT_DOCUMENT.md` (section 13), Word file rebuilt.
- **Verification:**
  - The test now registers a second Block, District and State officer for an area that already has an active officer at each level.
  - All three are accepted (Pending) and approved without error.
  - Cluster duplicates are still refused.
  - Full suite: **142 passed** (1,721 assertions).
