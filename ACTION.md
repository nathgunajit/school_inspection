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

## 2026-11-20 · Absent Teachers and Fund Details: "Done" only after the officer acts (user request)

- **Problem:** the Absent Teachers (step 3) and Fund Details (step 5) tabs showed **Done** on a new draft. Both steps had no required items, so `InspectionValidator::progress()` counted them as complete.
- **Done:**
  - **Step 3:**
    - New question code `ABS_00`, "No absent teacher today (all teachers present)" (multi, option `yes`).
    - The step is Done when at least one absent teacher is added **or** `ABS_00` is ticked. Before that it shows "Not started".
    - Both together is a missing item.
    - UI (`step.blade.php`, `wizard.js` `syncAllPresent()`): the switch sits above the list. Ticking it hides the add form and shows "All teachers present". While rows exist, the switch is locked. It saves through the existing replay-safe answer endpoint.
  - **Step 5:** at least one financial year is required. The step shows "Not started" until one is saved. The intro and empty texts no longer say "optional".
  - `InspectionValidator`:
    - `allTeachersPresent()`
    - missing items `ABSENTEE`, `ABS_00` and `FUND`
    - one required item counted for steps 3 and 5, as for step 4
  - Reports:
    - `step-answers.blade.php` and the PDF say "All teachers present (no absent teacher)" when ticked.
    - `ABS_00` is not listed as a separate question row.
    - Old submitted inspections still say "No absent teachers recorded".
  - The rating is not affected: the scheme uses explicit codes.
- **Files:**
  - `app/Questionnaire/QuestionnaireDefinition.php`, `app/Questionnaire/InspectionValidator.php`
  - `resources/views/inspections/step.blade.php`, `resources/views/inspections/partials/step-answers.blade.php`, `resources/views/inspections/pdf.blade.php`
  - `resources/js/wizard.js`, `resources/scss/app.scss`
  - `tests/Feature/StepCompletionTest.php` (new), `tests/Feature/InspectionFlowTest.php` (`completeAll()` adds a fund year)
  - `CLAUDE.md`, `Product Details/INSPECTION_FORM_DOCUMENT.md`, Word file
- **Verification:**
  - New `StepCompletionTest`, 5 tests:
    - a new draft has steps 3 and 5 at 0% and cannot be submitted
    - ticking or unticking `ABS_00`
    - a row instead of the tick
    - a row together with the tick is refused
    - saving and removing a fund year
    - the stepper shows "Not started"
  - Full suite: **147 passed** (1,753 assertions).
  - `npm run build` passed.
- **Note:** open drafts now ask for these two steps before Submit. Submitted inspections are unchanged.
- **Not verified:** a visual check in the browser.

## 2026-11-20 · Fix: "All teachers present" was shown twice on step 3

- **Problem:** on the Absent Teachers step, "No absent teacher today (all teachers present)" appeared twice. The generic question loop (every step except 2) printed it, and the new `#all-present` box printed it again.
- **Fix:** `resources/views/inspections/step.blade.php`: the generic loop now skips step 3 (`@elseif ($step !== 3)`). Only the switch above the absentee list remains, with its lock hint.
- **Verification:**
  - `StepCompletionTest` asserts that `id="q-ABS_00"` appears exactly once on step 3.
  - Full suite: **147 passed** (1,754 assertions).

## 2026-11-20 · Dashboard: Total schools in the officer's area (user request)

- **Request:** the dashboard shows the total number of schools in the cluster for a Cluster officer, and likewise for the other levels.
- **Done:**
  - `DashboardController`:
    - `stats['schools']`: active schools from `JurisdictionService::schools()`, so each level sees only its own area.
    - `stats['schools_inspected_month']`: distinct schools in the same area with a submitted inspection this month.
  - `dashboard.blade.php`:
    - The new first tile reads "Total schools in your cluster / block / district / state", with the hint "N inspected this month".
    - It links to Find School for inspecting officers.
    - The grid is now 5 tiles: the first is full width on phones, then 2 per row; one row on desktop.
  - `app.scss`: `.kpi-hint`.
  - `UI_Pages_and_Application_Modules.md` (dashboard row), Word file rebuilt.
- **Verification:**
  - New `DashboardSchoolCountTest`, 2 tests:
    - Cluster, Block, District and State Admin counts match the active schools of their area.
    - The tile label is shown.
    - Inspected this month counts submitted inspections only, not drafts, and not other clusters.
  - Full suite: **149 passed** (1,771 assertions).
  - `npm run build` passed.
- **Not verified:** a visual check in the browser.

## 2026-11-20 · Small, standard filter inputs: Rating Report, Inspections in My Area, My Inspections (user request)

- **Done:**
  - The filter forms on the three pages use the class `filter-bar filter-sm`.
  - Every field is `form-control-sm` / `form-select-sm` (34 px): From and To dates, District, Block, Cluster, Officer, School, Status, Band, Category and Version.
  - The Apply, Go and Clear buttons are `btn-sm`.
  - The look is shared with the Map View filters (V8): the SCSS selector is now `.map-filters, .filter-sm`, so the rules are not copied.
    - small grey labels
    - a chosen value in normal text, "All" in placeholder grey
    - 16 px text on phones, so iOS does not zoom
  - Tighter gutters and padding.
  - Other `filter-bar` pages (admin users, audit, schools, schedules, login report) are unchanged.
- **Files:** `resources/views/inspections/index.blade.php` (My Inspections and Inspections in My Area), `resources/views/reports/ratings/index.blade.php`, `resources/scss/app.scss`, new `tests/Feature/SmallFiltersTest.php`.
- **Verification:**
  - `SmallFiltersTest`: on each of the three pages, the form has `filter-sm`, small fields and small buttons, and no full-size field is left in the filter form.
  - Full suite: **150 passed** (1,786 assertions).
  - `npm run build` passed.
- **Not verified:** a visual check in the browser.

## 2026-11-20: Attractive, clean date picker on every date field
- Added `flatpickr` (bundled). New `resources/js/date-picker.js` (imported in `app.js`) enhances every `<input type="date">`:
  - shows the date as "20 Nov 2026" with a blue calendar icon
  - rounded calendar card with soft shadow, theme blue for the selected day, outlined today, greyed disabled days, Today / Clear buttons, short open animation
  - `min` / `max` respected; the original input keeps `Y-m-d` and still fires `change`, so forms, `data-autosubmit`, the wizard answer save and the Map View filters work unchanged
  - same look on phones (native picker disabled)
- `map.js`: Reset re-syncs the From / To pickers (`fp-sync` event).
- Styles at the end of `resources/scss/app.scss`.
- Files: package.json, package-lock.json, resources/js/date-picker.js, resources/js/app.js, resources/js/map.js, resources/scss/app.scss
- Verified: `npm run build` OK; `php artisan test` 150 passed. Not checked visually in a browser.

## 2026-11-20: Rating Settings page redesigned (elegant, attractive)
- `/admin/rating` restyled; same fields and routes, so saving and versioning are unchanged.
  - Blue hero card: active version, item count, total weight, rated count, and a fold-out "How the score is calculated" with the formula.
  - Section cards with a coloured icon, item count, weight-share bar; can be folded.
  - Each item: label, code chip, Answer / Calculated ratio tag, weight box, and point chips tinted red (0) to green (1), updated live.
  - Bands: live colour preview bar (0-100%), large swatches, "%" suffix; the swatch follows the colour select.
  - Re-rate option as a switch, large Save button with an orange dot when there are unsaved changes; versions as a timeline.
  - Stacks cleanly on phones.
- Files: resources/views/admin/rating/index.blade.php, resources/scss/_rating-settings.scss (imported in app.scss), resources/js/rating-settings.js (imported in app.js), tests/Feature/InspectionRatingTest.php
- Verified: `npm run build` OK; `php artisan test` 150 passed. Not checked visually in a browser.

## 2026-11-20: Excel Upload district-wise, school count report, easier upload
- School Master upload is now district-wise: pick a district; the file needs only `udise_code`, `school_name`, `cluster_code` (District and Block columns removed; taken from the cluster, which must be in the chosen district). Old files with district/block codes still work.
- Check before save: "Check file" runs all rules without saving and shows rows found / new / updated / errors, per-block counts and the first 20 errors; then Confirm and save or Cancel.
- District template download with a second sheet listing that district's cluster and block codes.
- New District report tab: blocks, clusters, schools, active, closed, with GPS, last upload per district; tap a district for its blocks; Export Excel (Districts + Blocks sheets).
- History shows the district and a per-block added / updated / rejected summary.
- Page redesign: Upload / District report / History tabs, 1-2-3 steps, drag and drop box with file name, size and type check.
- Migration `2026_11_20_000001_add_district_to_import_batches` (district_id, summary); run on MySQL.
- Files: app/Http/Controllers/Admin/ImportController.php, app/Services/MasterImportService.php, app/Services/SchoolCountReport.php (new), app/Exports/SchoolTemplateExport.php (new), app/Models/ImportBatch.php, routes/web.php, resources/views/admin/imports/index.blade.php, resources/js/imports.js (new), resources/scss/_imports.scss (new), CLAUDE.md, tests/Feature/DistrictSchoolImportTest.php (new)
- Verified: `npm run build` OK; `php artisan test` 155 passed. Not checked visually in a browser.

## 2026-11-20: Excel Upload corrects District / Block / Cluster names without changing IDs
- Location Master upload matches by code and corrects the name (and status / coordinates); the record ID and every linked school, user, jurisdiction and inspection stay the same.
- An existing district, block or cluster can no longer be moved to another parent by a wrong `parent_code`: the row is rejected, naming the current parent.
- Location uploads go through Check file first: Name corrections table (old name struck through, new name), new locations, unchanged rows; then Confirm or Cancel.
- "Current locations (to correct names)" download: all existing states, districts, blocks and clusters with their codes, ready to edit and upload again.
- Each rename is audited (`location_renamed`: id, code, old, new); History shows "N renamed" with the list.
- Checked local data: 24 schools, all linked to the right block and district for their cluster (no mismatches).
- Files: app/Services/MasterImportService.php, app/Http/Controllers/Admin/ImportController.php, resources/views/admin/imports/index.blade.php, resources/js/imports.js, resources/scss/_imports.scss, CLAUDE.md, tests/Feature/LocationRenameImportTest.php (new)
- Verified: `npm run build` OK; `php artisan test` 158 passed.

## 2026-11-20: Rating Settings, Rating bands text fixed
- Band names and colour names were cut off (four fields squeezed on one line in the narrow sidebar) and names inside the coloured bar were clipped.
- Each band is now a small card: line 1 swatch + full-width Label; line 2 From % + live "to N %" + Colour, with small captions.
- The bar shows colour only; a legend below lists each band name with its range (e.g. "Excellent 80 – 100 %"), highest first, long names truncated with a tooltip.
- Field names unchanged, saving unaffected.
- Files: resources/views/admin/rating/index.blade.php, resources/scss/_rating-settings.scss, resources/js/rating-settings.js, tests/Feature/InspectionRatingTest.php
- Verified: `npm run build` OK; `php artisan test` 158 passed. Not checked visually in a browser.

## 2026-11-20 — Branded page loader + slow / offline internet warning
- Loader `<x-page-loader />` (both layouts): Samagra Shiksha logo in a spinning blue/orange ring, "School Inspection", "Samagra Shiksha, Assam", dot pulse. Inline critical CSS (shows before app.css on slow networks), appears only after 250 ms, hides on `load`, 12 s safety, re-shows on normal link click / form submit (skips downloads, exports, new tabs, `data-no-loader`), bfcache safe, reduced-motion aware.
- `resources/js/network-status.js`: banner `#si-net-banner` — slow (Network Information API 2g / downlink < 0.5 / rtt > 1500, or page load > 8 s), offline, back online (3 s). Slow warning dismissible per session.
- Files: components/page-loader.blade.php, layouts/app + portal, js/network-status.js, js/app.js, scss/_page-loader.scss, scss/app.scss, tests/Feature/PageLoaderTest.php, CLAUDE.md.
- Result: build OK; 160 tests, all pass (one known flaky assertion in InspectionFlowTest — random photo name can end in "p.jpg" — passed on re-run).

## 2026-11-20 — Loader only when slow or heavy; double load and double submit fixed
- Full splash now shows only: page still loading after 1.5 s (slow internet), slow connection detected, or a form/link marked `data-loader="heavy"` (Excel check + confirm, Recalculate all, rating save, rating report, login reports) with its own message.
- Double load fixed: the leaving page hands off to the next one (`sessionStorage si-loader-handoff`), so one continuous loader instead of two flashes.
- Standard cases: thin top progress bar after 600 ms; submit button disabled with a spinner (stops double submits); reset on back/forward, after 4 s for downloads, 20 s safety.
- Files: components/page-loader.blade.php, js/network-status.js, imports/rating/report/login-report views, tests/Feature/PageLoaderTest.php, CLAUDE.md.
- Result: build OK, 161 tests pass.

## 2026-11-20 — Processing look for Login and every submit button
- New `resources/js/button-busy.js` (`setBusy` / `clearBusy` / `releaseAll`, `window.SI_BUSY`): ring spinner + processing text + light sweep, width kept, disabled + aria-busy. Text from `data-loading-text`, else from the label (Sign in → "Signing in…", Save → "Saving…", Approve → "Approving…" …).
- Login / register / password forms: `password-encrypt.js` now fires `si-form-sending`, so the button changes at once (before, its preventDefault skipped the busy state); `fail()` releases it.
- All other submits via `network-status.js`; Start inspection, Submit inspection (review.js) and Excel Check file use the same look. Styles `_button-busy.scss`.
- Result: build OK, 162 tests pass.

## 2026-11-20 — Math CAPTCHA on the login page
- Every sign-in asks for the answer to a small sum (add, subtract or multiply), drawn as a jittered SVG image with noise lines (`/login/math-captcha`, throttled 60/min, no-store); the sum text is never in the page HTML.
- `App\Support\MathCaptcha`: answer kept in the session as an HMAC; checked once, a new sum after every check (no replay); refresh button for a new sum. Checked before the password, so a wrong sum does not count as a failed password attempt. The image CAPTCHA after 2 failed attempts and the 3-failure lock are unchanged.
- Config `inspection.login.math_captcha` (`LOGIN_MATH_CAPTCHA`, default true; false in phpunit.xml).
- Files: app/Support/MathCaptcha.php, Auth/LoginController.php, routes/web.php, config/inspection.php, .env.example, phpunit.xml, partials/math-captcha.blade.php, auth/login.blade.php, scss/_button-busy.scss, tests/Feature/LoginMathCaptchaTest.php.
- Result: build OK, 165 tests pass.

## 2026-11-20 — Modification V9 (`Documents/Modification of the ApplicationV9.docx`)
1. **Person met / designation** removed from the form: `BAS_PERSON_*` are `hidden` (codes kept). Report, PDF and Excel show them only for older inspections.
2. **Absent teachers ≤ Total teachers − Present** (blocking): `InspectionValidator::absentLimit()`; `addAbsentTeacher` refuses one more row (422; a replay of a saved `row_uid` is still accepted); the counter shows "added / allowed"; the Add button rests at the limit.
3. **Total teachers box** shows only the total (no Present / Absent) and sits above the teacher fields. The report and PDF dropped the "Teachers absent" figure.
4. **TSI_05–TSI_09 default 0**: not required, the box shows 0 with "Enter 0 if none", counted as 0 in totals (`QuestionnaireDefinition::withDefaults()`), stored as 0 at submit.
5. **Absent teacher list alignment**: rows on a fixed grid (avatar | details | actions), actions centred; on phones they sit under the details with 40 px buttons.
6. **Fund details optional**; amounts formatted on blur (`.2` → `0.20`, `5` → `5.00`), errors shown at once for more than 2 decimals or more than 99,999.
7–10. **Ratings hidden**: switch `RATING_DISPLAY` (`config('inspection.rating.display')`, default false).
   - Ratings are still calculated at submit.
   - With the switch off, ratings are hidden from the report, lists, school page, map (JSON and UI), sidebar and Excel. `/reports/ratings*` returns 404.
   - The rating section of the inspection PDF and the Rating Report PDF are removed (route, method and view).
   - Rating Settings stays for admins.
11. **Profile header**:
   - gradient band with rings, dot pattern, SSA logo watermark and a greeting
   - larger avatar with a colour ring
   - pill buttons (44 px)
   - a quick-facts strip: submitted, this month, member since, last sign-in
- Files:
  - app/Questionnaire/{QuestionnaireDefinition,InspectionValidator}.php
  - app/Http/Controllers/{InspectionController,RatingReportController,MapController,ReportController}.php
  - routes/web.php
  - config/inspection.php
  - .env.example
  - views:
    - inspections/{step,show,pdf,index}.blade.php
    - inspections/partials/{question,step-answers}.blade.php
    - components/{rating-badge,layouts/app}.blade.php
    - schools/show.blade.php
    - map/index.blade.php
    - reports/ratings/index.blade.php (pdf.blade.php deleted)
    - profile.blade.php
  - resources/js/{wizard,map}.js
  - resources/scss/app.scss
  - tests:
    - tests/Feature/ModificationV9Test.php (new)
    - updated: StepCompletion, RatingReport, InspectionRating, RatingCard, SchoolRatingDisplay, SmallFilters, InspectionFlow
  - CLAUDE.md
  - Product Details/INSPECTION_FORM_DOCUMENT.md, the rebuilt School_Inspection_Product_Details.docx, and Requirements/ (V9 copy)
- Verified: `npm run build` OK; `php artisan test` 171 passed. Not checked visually in a browser.

## 2026-11-20 — FLN TLM list on the classroom observation (`Documents/FLN TLM list_2026-27.docx`)
- Step 4 (Classroom performance): when **Use of TLM by teacher = Yes**, the officer ticks the TLM items used. Several can be ticked.
  - The list is filtered by the class picked: that class's items plus any "All classes" items.
  - At least one tick is required when the class has items. This is checked in the browser and on the server (422 `tlm_items`).
  - With TLM = No, the ticks are cleared.
  - A class without items needs none.
  - An item of another class, or an inactive item, is refused. A row keeps an inactive item it already has.
  - An offline replay (same `row_uid`) does not duplicate the row or its ticks.
- New master **`tlm_items`**, with the link table `inspection_classroom_tlm`.
  - The migration seeds the document's list: Class I with 6 items, Class II with 5 (code, variety 1, "One copy per child").
- New **TLM Master** admin page (`/admin/tlm-items`, `manage_masters`, sidebar link): filter by class, search, add, edit, make inactive or active. Every change is audited (`tlm_item_created`, `_updated`, `_activated`, `_deactivated`).
- The saved row card, the report, the review and the PDF show the items used ("Yes: Karjyapatra (Literacy) · NIPUN AXOM G1L4, …").
- Files:
  - database/migrations/2026_11_20_000002_create_tlm_items.php
  - app/Models/{TlmItem,InspectionClassroomObservation}.php
  - app/Http/Controllers/{InspectionController,ReportController,Admin/TlmItemController}.php
  - routes/web.php
  - views: inspections/{step,pdf}.blade.php, inspections/partials/step-answers.blade.php, admin/tlm/index.blade.php, components/layouts/app.blade.php
  - resources/js/wizard.js
  - resources/scss/app.scss
  - tests/Feature/TlmItemsTest.php (new, 8 tests)
  - CLAUDE.md
  - Product Details/{INSPECTION_FORM_DOCUMENT,Database_Architecture_and_Schema}.md, the rebuilt School_Inspection_Product_Details.docx, and Requirements/ (TLM list copy)
- Verified:
  - `php artisan test`: 179 passed
  - `npm run build`: OK
  - `php artisan migrate` on local MySQL: 11 items
  - Not checked visually in a browser.

## 2026-11-20 — TLM Master shows only Class, Name and Code
- The TLM Master list, the add form and the edit form now show only **Class, Name and Code**. Variety, order and remarks are removed from the screen.
- Those values stay in `tlm_items`. An edit keeps the stored values, and a new item gets variety 1 and order 0.
- Files:
  - resources/views/admin/tlm/index.blade.php
  - app/Http/Controllers/Admin/TlmItemController.php
  - Product Details/INSPECTION_FORM_DOCUMENT.md (and the rebuilt docx)

## 2026-11-20 — TLM Master: full add form again; the list is a table of Class, Name and Serial no / Code
- The change above is reverted for the forms.
  - The add and edit forms again have class, name, serial no / code, variety, order and remarks.
  - Only the **list** is simplified: a table with Sl., Class, Name of item and Serial no / Code ("NA" when there is none). Inactive items are marked.
- The edit menu opens with fixed positioning, so the scrolling table does not clip it.
- Files:
  - resources/views/admin/tlm/index.blade.php
  - app/Http/Controllers/Admin/TlmItemController.php
  - Product Details/INSPECTION_FORM_DOCUMENT.md (and the rebuilt docx)

## 2026-11-20 — Step 2 trained/untrained default 0, TLM list for all classes, cleaner lists and review tables
- **Step 2:** TSI_10–13 (male / female, trained / untrained) are optional and default to 0.
  - They are stored as 0 at submit, and `teacherBreakdown()` counts a blank as 0.
  - The breakdown box is now a 2×2 grid (Trained, Untrained, Male, Female), each with its formula, plus the sum and a check against Total teachers.
  - In the browser, any blank optional count is treated as 0.
- **Step 4 TLM:** with "Use of TLM" = Yes, every active TLM item is shown, grouped by class (Class I, Class II, …, All classes).
  - Each group shows how many items are ticked.
  - At least one tick is required for any class. Unknown or inactive items are refused, and a row keeps the items it already has.
- **Design:**
  - Absent teachers, Classrooms observed and Saved years are a single rounded table on wide screens: header strip, Sl., avatar / class badge, coloured chips, amounts aligned right, row hover and editing accent.
  - On phones each row is a labelled card.
  - The classroom row lists the TLM items used as green chips.
  - The review and report pages show absent teachers and classroom performance as tables that stack into cards on phones.
- Files:
  - app/Questionnaire/QuestionnaireDefinition.php
  - app/Http/Controllers/InspectionController.php
  - resources/views/inspections/step.blade.php
  - resources/views/inspections/partials/step-answers.blade.php
  - resources/js/wizard.js
  - resources/scss/app.scss
  - tests: TlmItems, TeacherTotals, ModificationV9, InspectionFlow, OfflineReplay
  - CLAUDE.md
  - Product Details/INSPECTION_FORM_DOCUMENT.md (and the rebuilt docx)
- Verified:
  - `php artisan test`: 179 passed
  - `npm run build`: OK
  - Not checked visually in a browser.

## 2026-11-20 — Cleaner inspection PDF, report view page and absent-teacher list; standard field size
- **PDF** (`inspections/pdf.blade.php`), with the same content:
  - two-tone header rule and reference chips (ID, status, UDISE)
  - a cleaner school card
  - numbered section badges
  - zebra question tables with a green / amber / red answer dot
  - dark-blue table headers with Sl., and pills for leave reason, TLM and participation (TLM items on a second line)
  - Step 2 as four figure boxes (students, teachers, trained / untrained, male / female)
  - framed photos
  - a signature block with the date
  - It is still DomPDF-safe (tables only).
- **Report view** (`inspections/show.blade.php`):
  - hero with a dotted gradient, number chip and UDISE / location chips
  - detail items with icon bubbles
  - one accent colour per step card
  - the jump bar highlights the section in view (`review.js`)
  - "At a glance" KPI tiles with icons, and a fund spent bar
  - gradient PDF button
- **Absent teachers (step 3):**
  - the avatar ring colour shows the teacher type
  - unauthorised leave is shown in red
  - remarks appear as a quote
  - compact icon actions
  - a tally of reasons under the list
- **Field size standard:** 14 px text and 40 px height for every field and button on desktop / tablet; 16 px and 44 px on phones (`$field-fs`, `$num-fs`, `$input-btn-padding-y`).
- Files:
  - resources/views/inspections/{pdf,show,step}.blade.php
  - resources/js/{wizard,review}.js
  - resources/scss/app.scss
  - CLAUDE.md
- Verified:
  - `php artisan test`: 179 passed
  - `npm run build`: OK
  - a sample PDF rendered without errors
  - Not checked visually in a browser or PDF viewer.

## 2026-11-20 — Rating Report: State Administrator only
- `/reports/ratings` and `/reports/ratings/export` are now for the **State Administrator only**. Every other officer (State, District, Block, Cluster) gets 403, and the sidebar link shows only for the State Admin.
- The State Admin sees the report even when `RATING_DISPLAY=false`. That switch still hides ratings on the other pages (report aside, lists, school page, map, Excel).
- Files:
  - app/Http/Controllers/RatingReportController.php
  - resources/views/components/layouts/app.blade.php
  - tests: RatingReport, ModificationV9
  - CLAUDE.md
  - Product Details/INSPECTION_FORM_DOCUMENT.md (and the rebuilt docx)
- Verified: `php artisan test`: 180 passed.

## 2026-11-20 — Inspection Coverage Report with an admin-configurable coverage period
- New report **`/reports/coverage`** (sidebar "Coverage Report", `view_reports`, jurisdiction-scoped).
  - **District-wise / Block-wise / Cluster-wise** tabs, ordered by unique schools visited (high → low). Other orders: visit %, inspection %, total schools, not visited, name.
  - Each table has a total row and drills down to the schools of a cluster, with not-visited schools first.
- Two measures:
  - **Visit Coverage:** at least one *verified* visit (location within the radius of the school) in the period.
  - **Inspection Coverage:** a *submitted* inspection within the prescribed inspection cycle.
  - Also shown: repeat visits, unverified visits, not visited, due in cycle, officers active, last visit, and Good / Moderate / Low / Critical bands.
- **Configurable period** (`coverage_settings`, `/reports/coverage/settings`):
  - The State Admin sets the state default: Daily, Weekly, Monthly, Quarterly or Custom range, plus the inspection cycle (monthly / quarterly / half-yearly / yearly) and the verification radius.
  - A District officer with Manage users sets their own district's period and cycle, or goes back to the state default.
  - Every change is audited.
  - Viewers can move ‹ › between periods or pick another one.
- **Excel** (audited `coverage_report_exported`): Summary, District / Block / Cluster-wise, Schools not visited, Due in cycle.
- Files:
  - database/migrations/2026_11_20_000003_create_coverage_settings.php
  - app/Models/CoverageSetting.php
  - app/Support/CoveragePeriod.php
  - app/Services/CoverageReportService.php
  - app/Http/Controllers/CoverageReportController.php
  - app/Providers/AppServiceProvider.php (gate `configure_coverage`)
  - config/inspection.php (`coverage`)
  - routes/web.php
  - resources/views/reports/coverage/{index,settings}.blade.php
  - components/layouts/app.blade.php
  - resources/scss/app.scss
  - tests/Feature/CoverageReportTest.php (7 tests)
  - CLAUDE.md
  - Product Details/PROJECT_DOCUMENT.md (and the rebuilt docx)
- Verified:
  - `php artisan test`: 187 passed
  - `npm run build`: OK
  - `php artisan migrate` (local)
  - the service ran against local MySQL with monthly, quarterly and weekly periods
  - Not checked visually in a browser.

## 2026-11-20 — Inspection PDF: alignment issues found and fixed
- Found by rendering every page with DomPDF's GD backend (same layout engine as the PDF) and reading the page images.
- Fixes in `resources/views/inspections/pdf.blade.php`:
  - **Section badge numbers** sat at the bottom of the blue square. The number is now drawn in the table cell itself, centred.
  - **Row numbers and Basic Information labels** sat higher than the text beside them (smaller font, top aligned). They now use the same font size, so the baselines line up. The label column is wider (21 %).
  - **Step 2 numbers** were pushed to the far right. They now sit in the same answer column as every other step.
  - **Fund tables:** the Received / Expenditure / Balance headers were left-aligned over right-aligned amounts (a CSS specificity bug). They are now right-aligned. Each year's table, including its **Total row**, stays together on one page.
  - **Classroom table:** the narrow "Use of TLM" column made the TLM item list a tall strip. The items now sit on a full-width "TLM used:" line under the record, and the columns have balanced fixed widths.
  - **Every data table** has fixed column widths and a `thead`, so the header repeats when a table continues on the next page.
  - **Section headings** were left alone at the bottom of a page (e.g. "School Governance", "Photographs of School"). Each heading is now kept with its first rows, its first fund year, a short absent / classroom table, or the first row of photos. A pair of photos never splits across pages.
  - **Tone dot:** an empty inline-block became a coloured ● character on the text baseline.
  - **Header:** the extra short green rule is removed, and the reference chips sit on their own row (no negative margin).
  - **Signature:** the date and the officer name are bottom-aligned on the same line.
- Verified:
  - `php artisan test`: 187 passed
  - the real PDF renders
  - page images were checked for a 5-page inspection with absentee, classroom, TLM, fund and 4 photos

## 2026-11-21 — Modification V10: ECCE TLM list, Step 2 trained / untrained totals, absent teachers on one line
- Source: `Documents/Modification of the ApplicationV10.docx` (3 points) and `Documents/TLM -2025-26-ECCE (1).docx`.
- **1. ECCE TLM next to FLN:**
  - Migration `2026_11_21_000001_tlm_lists_ecce`: `tlm_items.list` (fln / ecce) and `tlm_items.section`. The unique key is now (list, class, name). It seeds 13 ECCE items (2 "TLM for students", 11 "TLM for schools", all classes).
  - Step 4 shows two headed lists, FLN TLM (by class) then ECCE TLM (by group), both for every class (`renderTlm()`). At least one tick across both when TLM = Yes; replay-safe as before.
  - ECCE items print as "ECCE · name" on the review, report and PDF (`TlmItem::fullLabel()`).
  - TLM Master: list filter, List column, List + Group fields; the duplicate check is per list.
- **2. Step 2 only total trained / untrained** (choice: replace the 4 inputs with 2):
  - New codes `TSI_14` Total trained and `TSI_15` Total untrained (optional, default 0). `TSI_10`–`TSI_13` are hidden with no default (codes kept, never reused).
  - `teacherBreakdown()` / `legacyTeacherBreakdown()` / `shownBreakdownCodes()`: older inspections keep the male / female split on the report and the PDF (4th box only for them).
  - Validation message on `TSI_14` ("Trained + Untrained ... more than Total teachers"); live box shows Trained, Untrained and the sum.
  - Rating metric `trained_teachers` = TSI_14 / (TSI_14 + TSI_15); older inspections keep the old formula (same result), so no new scheme version.
- **3. Absent teachers on one line:** wizard row grid (Type and Reason size to their chip, no wrapping, Type now shown on phones too, remarks shortened with full text on hover), report table `.rv-absent` / `.rv-nowrap`, PDF `.dt.abs` (Name 30 %, Type 18 %, Reason 24 %, no wrap).
- Files: `app/Models/TlmItem.php`, `app/Http/Controllers/Admin/TlmItemController.php`, `app/Http/Controllers/InspectionController.php`, `app/Questionnaire/QuestionnaireDefinition.php`, `app/Questionnaire/InspectionValidator.php`, `app/Rating/RatingCalculator.php`, `resources/js/wizard.js`, `resources/scss/app.scss`, `resources/views/admin/tlm/index.blade.php`, `resources/views/inspections/{step,pdf}.blade.php`, `resources/views/inspections/partials/step-answers.blade.php`, the migration, tests (`ModificationV10Test` new; `TeacherTotalsTest`, `TlmItemsTest`, `ModificationV9Test`, `InspectionRatingTest`, `InspectionFlowTest` updated), docs (`CLAUDE.md`, `Product Details/*.md`, Requirements copies, rebuilt docx).
- Verified:
  - `php artisan test`: 193 passed, 1 failed. The failure is `LoginMathCaptchaTest` (looks for "Solve the sum"), caused by an uncommitted label change in `resources/views/partials/math-captcha.blade.php` that is not part of V10. That file was left out of this commit.
  - `php artisan migrate` (local MySQL): 13 ECCE + 11 FLN items.
  - `npm run build`: OK.
  - Not checked visually in a browser.

## 2026-11-21 — Roll back V10 Step 2: male / female trained / untrained counts restored
- Request: roll back the previous update to the Student Information section (Step 2, V10 item 2, commit `5702100`). V10 items 1 (ECCE TLM) and 3 (absent teachers on one line) stay.
- Step 2 is back to its `d1991a8` form: `TSI_10`–`TSI_13` (male trained / untrained, female trained / untrained, optional, default 0), the 2×2 Trained / Untrained / Male / Female box, the error on `TSI_10`, and the rating `trained_teachers` = (TSI_10+TSI_12)/(TSI_10..13).
- `TSI_14` / `TSI_15` stay as hidden codes with no default (codes are never removed or reused). One local inspection was saved with them. `QuestionnaireDefinition::totalsOnlyBreakdown()` / `shownBreakdownCodes()` and `teacherBreakdown()['split']` keep such an inspection readable on the report (Trained / Untrained) and the PDF (3 boxes), and its rating uses them. A submit that stores blank TSI_10..13 as 0 does not hide them.
- Files:
  - `app/Questionnaire/QuestionnaireDefinition.php`, `app/Questionnaire/InspectionValidator.php`, `app/Rating/RatingCalculator.php`
  - `resources/views/inspections/{step,pdf}.blade.php`, `resources/views/inspections/partials/step-answers.blade.php`, `resources/js/wizard.js`, `resources/scss/app.scss`
  - tests: `TeacherTotalsTest`, `ModificationV9Test`, `InspectionFlowTest` restored from `d1991a8` (+ split / totals-only cases); `ModificationV10Test` rewritten; `InspectionRatingTest` comment
  - docs: `CLAUDE.md`, `Product Details/INSPECTION_FORM_DOCUMENT.md`; `School_Inspection_Mechanism.md`, `PROJECT_DOCUMENT.md` and `NEW_MODULES_PLAN.md` restored; rebuilt docx
- Verified:
  - `php artisan test`: 192 passed, 1 failed. The failure is `LoginMathCaptchaTest`, caused by the uncommitted login label change in `resources/views/partials/math-captcha.blade.php`, which is not part of this change and stays out of the commit.
  - `npm run build`: OK.
  - Not checked visually in a browser.

## 2026-11-21 — Login math CAPTCHA label: "Enter the correct answer"
- The login CAPTCHA label changed from "Solve the sum" to "Enter the correct answer".
- Files: `resources/views/partials/math-captcha.blade.php`, `tests/Feature/LoginMathCaptchaTest.php`
- Verified: `php artisan test`: all pass.

## 2026-11-21 — Step 2: total trained / untrained only, must equal Total teachers
- Request: in Teacher's & Student's Information, under Trained / Untrained teachers, show only Total trained and Total untrained after the four counts (no male / female), and validate them as equal to Total teachers.
- The officer still enters TSI_10–13. The live box shows Total trained (Male trained + Female trained) and Total untrained (Male untrained + Female untrained), with "Equal to / Less than / More than Total teachers".
- `InspectionValidator::teacherConsistency()`: blocking error on `TSI_10` when Trained + Untrained ≠ Total teachers (was "more than"). Blank counts are 0, so they must be filled when Total teachers > 0.
- Report tile and PDF (now 3 boxes) show Trained / Untrained only. `teacherBreakdown()` and the rating are unchanged; no codes changed.
- Files: `resources/views/inspections/{step,pdf}.blade.php`, `resources/views/inspections/partials/step-answers.blade.php`, `resources/js/wizard.js`, `resources/scss/app.scss`, `app/Questionnaire/InspectionValidator.php`; tests `TeacherTotalsTest`, `ModificationV9Test`, `ModificationV10Test`; docs `CLAUDE.md`, `Product Details/{INSPECTION_FORM_DOCUMENT,School_Inspection_Mechanism,PROJECT_DOCUMENT}.md`, rebuilt docx.
- Verified: `php artisan test` 193 passed; `npm run build` OK. Not checked visually in a browser.

## 2026-11-21 — Step 4 & Review: FLN / ECCE TLM shown as distinct coloured groups
- Request: make the Review and Classroom Performance sections more attractive, with ECCE and FLN clearly told apart.
- Colours everywhere: FLN blue (`bi-book-half`), ECCE orange (`bi-balloon-heart`).
- `InspectionClassroomObservation::tlmGroups()` (ticks per list, FLN first, items without the "ECCE · " prefix) and `tlmSummary()` ("Yes · FLN 2 · ECCE 1"); `toClientArray()` adds `tlm_groups`, and `tlm` uses the summary. `tlm_items` / `tlmText()` are unchanged.
- Step 4 picker: each list is a card with a coloured header band, icon, full name and a live "n ticked" pill; ticked chips use the list colour.
- Step 4 saved rows: Use of TLM shows Yes/No plus "FLN n" / "ECCE n" badges; the ticks show as coloured group blocks (falls back to `tlm_items` for older payloads).
- Review / report: summary strip (observed, using TLM, FLN items, ECCE items, satisfactory); each record is a `<tbody>` with a TLM-groups row under it; on phones each record is one card.
- PDF: FLN / ECCE tags on the Use of TLM cell, and one tagged line per list under the record.
- Files: `app/Models/InspectionClassroomObservation.php`, `resources/js/wizard.js`, `resources/scss/app.scss`, `resources/views/inspections/partials/step-answers.blade.php`, `resources/views/inspections/pdf.blade.php`, `tests/Feature/TlmItemsTest.php`; docs `CLAUDE.md`, `Product Details/INSPECTION_FORM_DOCUMENT.md`, rebuilt docx.
- Verified: `php artisan test` 194 passed; `npm run build` OK. Not checked visually in a browser.

## 2026-11-21 — Step 3 driven by the Step 2 absent count; Review expand / collapse restyled
- Request: Step 2 takes Total teachers and Teachers present; show the absent-teacher section only when someone is absent and require their details; otherwise step 3 needs nothing. Make the Review expand / collapse buttons more attractive.
- Decisions (asked): exactly Total − Present absent rows are required (blocking); `TSI_09` Present is now required (no default).
- `QuestionnaireDefinition`: `TSI_09` `$num0` → `$num`; `ABS_00` `'hidden' => true` (code kept, older drafts / reports still read it).
- `InspectionValidator`: step 3 rule = "step 2 first" (unknown), "remove the rows" (0 absent but rows), "add all N (Y added)", or "only N absent"; the step 3 warning is gone; step 3 progress counts one item per absent teacher.
- Step 2: new live "Teachers absent today" box under TSI_09 (Total, Present, Absent, next-step hint); Next jumps to step 4 when none is absent.
- Step 3: state card (waiting for step 2 / all present + Continue / N absent with "added / N" pill); the ABS_00 box is removed; the form is hidden when 0.
- Review / report / PDF: "All teachers present (Total T, Present P)" when 0 absent; "x of N absent teacher(s) recorded" above the table; step meta "All teachers present".
- Review: Expand all / Collapse all is a pill button with icons (`.rv-toggle-all`); each step has a round Show / Hide toggle (`.review-caret`) and an open step gets a blue accent (`.review-step.is-open`, `review.js`).
- Files: `app/Questionnaire/{QuestionnaireDefinition,InspectionValidator}.php`, `resources/views/inspections/{step,review,pdf}.blade.php`, `resources/views/inspections/partials/step-answers.blade.php`, `resources/js/{wizard,review}.js`, `resources/scss/app.scss`; tests `StepCompletionTest` (3 new), `InspectionFlowTest`, `ModificationV9Test`; docs `CLAUDE.md`, `Product Details/INSPECTION_FORM_DOCUMENT.md`, rebuilt docx.
- Verified: `php artisan test` 195 passed; `npm run build` OK. Not checked visually in a browser.

## 2026-11-21: Modification V11 (`Documents/Modification of the ApplicationV11.docx`)
How each point was read (asked): item 1 means the bottom bars auto-hide; item 2 is a blank form plus a network remark with an admin list; item 3 uses names from the same school's past records; item 4 copies steps 1, 2, 5 and 6.

| # | V11 point | Action taken |
|---|---|---|
| 1 | Review section: hide the button section automatically while scrolling | The phone bottom nav and the wizard Back / Next bar slide away while scrolling down, and come back on scroll up, after a 700 ms pause, at the page bottom, or while a modal is open (`resources/js/app.js`, `body.bars-hidden` in `app.scss`, reduced-motion safe). Applies to every step and the Review page. |
| 2 | PDF form to download when the school has no internet; remark on the internet issue so the admin can raise it | New printable **blank inspection form** `GET /schools/{school}/blank-form` (`SchoolController::blankForm()`, `inspections/blank-form.blade.php`, jurisdiction check as on the school page, audited `blank_form_downloaded`). It is linked from the school page and the step 1 officer card. New optional step 1 question `NET_01` "Internet / network problem at the school"; Yes needs a remark. New **Reports → Network Issues** list (`/reports/network-issues`, `NetworkIssueController`, `view_reports`, jurisdiction-scoped, date and district filters). Logos moved to `App\Support\PdfLogos` (also used by the report PDF). |
| 3 | Auto-suggest the teacher name if already recorded for the same school | `GET /inspections/{inspection}/teacher-names?q=` returns up to 10 distinct names from the school's other non-cancelled inspections (absent teachers and classroom teachers), prefix matches first, then the most recent. The step 3 and step 4 name boxes use the existing autocomplete (`data-ac`, person icon, no "keep as typed" line). Free text is still allowed. |
| 4 | Next visit pre-filled from the previous inspection; absentee, classroom and photos blank; some step 2 figures fresh (e.g. Teachers present) | `App\Services\InspectionPrefill` runs only when a new draft is created. It copies steps 1, 2, 5 and 6 answers and remarks, plus fund years, from the school's latest Submitted / Unlocked inspection (current questionnaire version). Never copied: `TSI_01`, `TSI_02`, `TSI_09`, `NET_01`, absentees, classrooms, suggestions, photos. Migration `2026_11_21_000002` adds `inspections.prefilled_from_id`; the wizard banner reads "Filled from the last visit on …"; audited `inspection_prefilled`. The source inspection is never changed, and a reopened draft is not filled again. |

- Files:
  - `app/Questionnaire/QuestionnaireDefinition.php`, `app/Http/Controllers/{InspectionController,SchoolController,ReportController,NetworkIssueController}.php`
  - `app/Services/InspectionPrefill.php`, `app/Support/PdfLogos.php`, `app/Models/Inspection.php`, migration
  - `routes/web.php`
  - `resources/views/inspections/{blank-form,step}.blade.php`, `resources/views/schools/show.blade.php`, `resources/views/reports/network/index.blade.php`, sidebar in `components/layouts/app.blade.php`
  - `resources/js/{app,wizard}.js`, `resources/scss/app.scss`
  - tests: new `ModificationV11Test` (4 tests); `InspectionFlowTest` and `ModificationV9Test` answer `NET_01 = no`; the rating tests clear pre-filled answers before rating exact answers
  - docs: `CLAUDE.md`, `Product Details/INSPECTION_FORM_DOCUMENT.md`, a copy of the V11 docx in `Product Details/Requirements/`, rebuilt docx
- Verified: `php artisan test` 199 passed; `npm run build` OK; migration run on local MySQL. Not checked visually in a browser.

## 2026-11-21: Blank form PDF ("No internet"): Class Room Performance layout fixed
- Problem: section 4 broke across pages. The heading was left alone on a page, the next page was a full-height blue header block with the labels scattered ("Use of TLM · items (FLN" / "/ ECCE)"), and the rows were cramped on the page after.
- Cause: `page-break-inside: avoid` on whole tables. When the table did not fit, DomPDF stretched the header row instead of moving the table.
- Fix (`resources/views/inspections/blank-form.blade.php`):
  - Data tables use a repeating `<thead>`, and only rows avoid breaking.
  - Section 4 always starts a new page.
  - The classroom table was redesigned for hand-filling. Each of the 5 records has a details row (name, class, subject, ☐ Yes / ☐ No for TLM, FLN / ECCE item lines in blue / orange, ☐ Satisfactory / ☐ Not satisfactory) and a full-width Remarks row, with zebra per record and short single-line headers.
  - Absentee Type column is now tick boxes, and the reason codes are shown as chips.
  - Step 2 total boxes are aligned with the answer column, tick boxes are aligned with their labels, and the answer column is wider (no wrap in "Method of Teaching").
  - The signature stays on the photos page.
- Verified: rendered the PDF and checked every page (section 4 fits on one page); `php artisan test` passed.

## 2026-11-21: Modification V12 (`Documents/Modification of the ApplicationV12.docx`)
How the points were read (not asked, best reading of the document): items 3 and 4 mean the previous visit's data, including classroom performance, is fetched first and then edited. Item 8 covers what leads to "Not Found" after a session or draft has expired.

| # | V12 point | Action taken |
|---|---|---|
| 1 | Same CRCC, 2nd inspection: Teachers present default 0, Teachers Absentee Form blank, validate present against total | `InspectionPrefill::ZERO` stores `TSI_09 = 0` in the pre-filled draft. Absentees are never copied. The existing rule still applies: Present > Total is blocking, and step 3 needs Total − Present rows. The step 2 banner says "Teachers present starts at 0 … The Teachers Absentee Form starts blank". |
| 2 | All teachers present today: remove the list | When a step 2 save makes Present exactly equal to Total teachers, `InspectionController::saveAnswer` deletes the absentee rows. It is audited `absent_teachers_cleared`, the response carries `absent_removed`, and the wizard shows a toast. Present typed above Total removes nothing. |
| 3 | Edit option: previous data fetched first | Covered by the V11 pre-fill (steps 1, 2, 5, 6 copied, then edited), now extended to step 4 (item 4). The step 4 banner explains that last visit's rows can be edited or removed. |
| 4 | Class room performance: previous data fetched | `InspectionPrefill::apply()` copies the classroom rows (teacher, class, subject, TLM used, participation, remarks) with their TLM ticks into the new draft (fresh rows, `row_uid` null). The source is untouched, and the rows are edited with the normal step 4 form. |
| 5 | Inspection view page: not all tabs visible | Cause: a later `.rp-nav` rule (`nowrap`, hidden scrollbar) clipped the tabs past the column width. The bar now wraps onto a second line, the Photographs tab is always shown (with "No photos uploaded yet."), and `.min-w-0` is a real utility. |
| 6 | Excel Upload: all districts, then show the upload district-wise | New "All districts (one file)" choice: the cluster decides each school's district. Preview and History show a District-wise table (New / Updated / Errors / Total) with blocks listed under their district. The batch stores `summary['districts']` + `all_districts`. The District report "Last upload" includes all-district files. All-districts template (`?district=all`). UDISE+ headings (`udise_sch_code`, `clcode`) are read. The size limit is `IMPORT_MAX_KB` (50 MB). |
| 7 | TLM and Location: three-dot Edit form does not open properly | Cause: the whole edit form sat inside the dropdown. Clicks on labels or gaps closed it, the transform animation fought Popper's position, and the tall menu was clipped or covered. Now the three-dot menu has Edit / Make inactive, and Edit opens a modal per row outside the table. Edit errors go to the `edit` error bag, the modal opens again with the message, and the Add form is no longer filled by a failed edit. Dropdowns fade only. |
| 8 | After expired: Not Found page | Guest and user redirects use `route()` (the hard-coded `/login` was a 404 when the app runs in a sub-folder, e.g. XAMPP). A 419 on a form returns with a warning (logout goes to sign-in). After sign-in, background URLs (photo, PDF, export, JSON) are not used as the return page. An expired (cancelled) draft opens My inspections with the reason, and a submitted one opens its report. `/submitted` redirects. Friendly 403 / 404 / 419 / 429 / 500 pages offer Dashboard / Sign in. |

- Files:
  - `app/Services/{InspectionPrefill,MasterImportService,SchoolCountReport}.php`
  - `app/Http/Controllers/{InspectionController,Auth/LoginController,Admin/ImportController,Admin/TlmItemController,Admin/LocationController}.php`
  - `bootstrap/app.php`, `config/inspection.php`
  - `resources/views/inspections/{step,show}.blade.php`, `resources/views/admin/{tlm,locations,imports}/index.blade.php`, `resources/views/errors/*`
  - `resources/js/{app,wizard,imports}.js`, `resources/scss/app.scss`
  - tests: new `ModificationV12Test` (5 tests); `StepCompletionTest`, `ModificationV11Test`, `DistrictBoundaryTest` and `MasterImportAndAuditTest` updated
  - docs: `CLAUDE.md`, `Product Details/INSPECTION_FORM_DOCUMENT.md`, V12 docx copy in `Product Details/Requirements/`, rebuilt docx
- Verified: `php artisan test` 204 passed; `npm run build` OK. Not checked in a browser. The real state-wide UDISE file (22 MB, about 168 MB of sheet XML) was not test-uploaded and may need more PHP memory / time.

## 2026-11-23: School Master with the full UDISE+ 2025-26 columns
Reviewed `Documents/SchoolMaster_UDISE 202526.xlsx`: 1 sheet, 44,197 schools, 21 columns, no duplicate or bad UDISE codes, all with lat/lng, and all 4,607 clusters already in the Location Master. Before this change the importer kept only name / management / category / address / GPS and did not read the `cluster lcode` heading.
- New `schools` columns (migration `2026_11_23_000001_add_udise_fields_to_schools`, all nullable): `school_type`, `area_type`, `medium`, `assembly_constituency`, `lok_sabha`, `streams` (json), `pincode`, plus an index on `area_type`.
- `MasterImportService`:
  - UDISE heading aliases and the `SCHOOL_UDISE_COLUMNS` (in the templates and error file)
  - `"N - Label"` parsing; Category / Management mapped by UDISE number (`UDISE_CATEGORY` / `UDISE_MANAGEMENT`)
  - master ID cache; a missing column keeps the stored value
  - bulk insert via the query builder (one preloaded UDISE map instead of a query per row); `set_time_limit(0)`
- `MasterValueSeeder`: new categories `pr_to_hsec`, `upr_to_hsec`, `sec_hsec` and managements `kgbv`, `self_based`.
- `School` model: constants and `school_type_label` / `area_type_label` / `medium_label` / `streams_label`.
- UI:
  - admin school form (type, rural / urban, medium, assembly, Lok Sabha, pincode, HS streams)
  - school page details
  - inspection PDF chips
  - map popup line (type · area · medium)
- Files:
  - `database/migrations/2026_11_23_000001_add_udise_fields_to_schools.php`, `database/seeders/MasterValueSeeder.php`
  - `app/Models/School.php`, `app/Services/{MasterImportService,MapDataService}.php`, `app/Http/Controllers/Admin/SchoolMasterController.php`
  - `resources/views/{admin/schools/form,schools/show,inspections/pdf}.blade.php`, `resources/js/map.js`
  - `tests/Feature/SchoolUdiseImportTest.php` (3 new)
  - docs: `CLAUDE.md`, `Product Details/Database_Architecture_and_Schema.md`, rebuilt docx
- Verified:
  - `php artisan test`: 207 passed.
  - Loaded the real file into local MySQL (batch #3, All districts): 44,188 added, 9 updated, 0 rejected, 35 districts, about 100 s.
  - The category and management counts match the Excel; 1,465 schools have HS streams and 1,892 are urban; no extra master values were created.

## 2026-11-23: School import format, All districts default, AC / PC Master
- **Excel format**: one column order for every school template and the error file (`MasterImportService::SCHOOL_COLUMNS` / `SCHOOL_GROUPS`).
  - Order: school (udise_code, school_name), then location (district_code, block_code, cluster_code), then details (category, management, school_type, area_type, medium, **ac**, **pc**, streams, address, pincode, status), with **latitude / longitude last**.
  - Header groups are coloured (`ArrayExport` header fills).
  - Workbook sheets: Schools, Clusters, AC list, PC list, Value lists.
  - Uploads are still read by heading name, so old files and the raw UDISE+ export import unchanged.
- **Upload page**: All districts is preselected. The preview and History list any AC / PC added from the file.
- **AC / PC Master**:
  - Tables `assembly_constituencies` (code AC18002, name, pc_id) and `parliamentary_constituencies` (code PC1801, name), each with its own code (migration `2026_11_23_000002_create_constituencies`).
  - `schools.ac_id` / `pc_id` replace the free-text `assembly_constituency` / `lok_sabha`.
  - Seeded by `ConstituencySeeder` from `database/data/assam_constituencies.php`, built from the UDISE file: 126 ACs, each in exactly one of 14 PCs.
  - The file has no PC codes. PC codes follow the ECI numbering (PC1801 Kokrajhar … PC1814 Jorhat), because the delimitation-2023 AC numbers are not grouped by PC. They can be edited.
  - Admin screen `/admin/constituencies` (`Admin\ConstituencyController`, view `admin/constituencies/index`, sidebar "AC / PC Master"): AC / PC tabs, search, PC filter, add, Edit modal, make inactive. Audited `ac_*` / `pc_*`; moving an AC to another PC moves its schools.
  - Import: `ac` is matched by code, "NAME-ACcode", number or name; `pc` by code or name. Unknown ones are added.
  - Admin school form: AC select grouped by PC (the PC follows the AC). The school page shows "DOTMA (ST) · AC18002" and "KOKRAJHAR (ST) · PC1801".
- Data:
  - The local `schools` table was found empty (the 12:48 import was removed outside this session).
  - Migrated, seeded the AC / PC master, and re-imported `Documents/SchoolMaster_UDISE 202526.xlsx` with All districts (batch #4): 44,197 added, 0 rejected, 0 new AC / PC, all schools linked to an AC and a PC.
  - The PC counts match the Excel `LS` column (DHUBRI 4,489; DARRANG-UDALGURI 3,908; KOKRAJHAR (ST) 3,680).
- Files:
  - `database/migrations/2026_11_23_000002_create_constituencies.php`, `database/data/assam_constituencies.php`, `database/seeders/{ConstituencySeeder,DatabaseSeeder}.php`
  - `app/Models/{AssemblyConstituency,ParliamentaryConstituency,School}.php`, `app/Exports/ArrayExport.php`, `app/Services/MasterImportService.php`
  - `app/Http/Controllers/{Admin/ConstituencyController,Admin/SchoolMasterController,SchoolController}.php`, `routes/web.php`
  - `resources/views/admin/{constituencies/index,schools/form,imports/index}.blade.php`, `resources/views/schools/show.blade.php`, `resources/views/components/layouts/app.blade.php`
  - tests: `SchoolUdiseImportTest` (8 tests), `DistrictSchoolImportTest`, `MasterImportAndAuditTest`
  - docs: `CLAUDE.md`, `Product Details/{Database_Architecture_and_Schema,UI_Pages_and_Application_Modules}.md`, rebuilt docx
- Verified: `php artisan test` 211 passed. Not opened in a browser.

## 2026-11-23: Modification V13 (`Documents/Modification of the ApplicationV13.docx`)
1. **Demo users**: the new `DemoUserSeeder` places 4 officers per level (State, District, Block, Cluster), plus one Pending and one Rejected, on the current Location Master (the first 2 districts, 2 blocks each, the first cluster of each block), with genders set.
   - `php artisan users:reset-demo --force` (`App\Services\DemoUserReset`, refused in production) deletes every user except the State Admin, with their inspections and evidence files, schedules, jurisdictions and requests. It is audited `demo_users_reset`.
   - `DemoLocationSchoolSeeder` no longer overwrites a real Location Master.
   - Run on local MySQL: 25 users removed (their jurisdictions pointed at deleted demo districts), 18 demo officers created on KOKRAJHAR / DHUBRI; State Admin id 1 kept.
2. **School Master list**:
   - Filters: district, block, cluster, AC, PC, management, category, rural / urban, type and status.
   - Shows the total and matching counts, plus columns for type · area · medium and AC / PC.
   - Excel export of the filtered list in the upload column order (`App\Exports\SchoolListExport`). A full-state export checked on MySQL: 44,197 rows, about 150 s, about 640 MB.
   - The existing name / AC / district indexes cover the list (EXPLAIN checked), so no migration was needed.
3. **Remove master data**:
   - `App\Services\MasterDeleteGuard` adds Delete to the Location (district / block / cluster), AC / PC, School and TLM menus. A record is deleted only when unused; otherwise it is refused with the reason. Audited `*_deleted`.
   - Remove schools of a district (typed confirmation; schools with inspections or schedules are kept), audited `schools_removed`.
4. **Audit log and cache clean-up** (`App\Services\HousekeepingService`):
   - `audit:purge` writes entries older than `AUDIT_RETENTION_DAYS` (30) to `private:audit-archive/*.xlsx`, then deletes them, and logs `audit_purged`.
   - `cache:purge` clears the cache and stale cache files, and logs `cache_cleared`.
   - Both are scheduled monthly (the 1st, 02:00 / 02:15). The Audit Log page has a Clean up card with archive downloads.
   - CLAUDE.md rule 7 is reworded for this single exception.
5. **Import timeout**: `ImportController::longRunning()` sets `IMPORT_TIMEOUT` (default 0 = no limit), `IMPORT_MEMORY` (2048M), `ignore_user_abort` and longer MySQL session timeouts. The loader text says 'keep this page open', and production php.ini / Apache values are in `.env.example`.
6. **Rating map view**: the State Admin always sees ratings on `/map`. The Rating Report has a Map view button (`/map?colour=rating` preselects Colour by Rating).
7. **Default photo by gender**:
   - Migration `2026_11_24_000001_add_gender_to_users`: `users.gender`, required at registration and editable by the admin.
   - `<x-user-avatar>` shows `public/img/avatar-male.svg` / `avatar-female.svg` when no photo is uploaded.
- Files:
  - `app/Services/{DemoUserReset,MasterDeleteGuard,HousekeepingService}.php`, `app/Exports/{SchoolListExport,AuditArchiveExport}.php`
  - controllers `Admin/{SchoolMaster,Location,Constituency,TlmItem,AuditLog,Import,User}Controller`, `Auth/RegisterController`, `MapController`, `Requests/RegisterRequest`, `Models/User`
  - `routes/{web,console}.php`, `config/inspection.php`, `.env.example`
  - views `admin/{schools/index,locations/index,constituencies/index,tlm/index,imports/index,audit,users/show}`, `auth/register`, `components/user-avatar`, `map/index`, `reports/ratings/index`; `resources/js/map.js`
  - seeders `DemoUserSeeder`, `DemoLocationSchoolSeeder`; `public/img/avatar-{male,female}.svg`
  - tests: new `ModificationV13Test` (11 tests); `ModificationV9Test` (map rule); registration payloads now send `gender`
  - docs: `CLAUDE.md`, `Product Details/{Database_Architecture_and_Schema,UI_Pages_and_Application_Modules}.md`, `Requirements/` copy of V13, rebuilt docx
- Verified: `php artisan test` 222 passed (11 new). Pages not opened in a browser.

## 2026-11-23: Location IDs from 1 and codes shown with names
- **Renumber**: `php artisan locations:renumber --force` (`App\Services\LocationRenumber`, `routes/console.php`). Two passes per level (offset, then CASE map) inside one transaction with foreign keys off (MySQL) / deferred (SQLite). It updates schools, user_jurisdictions, inspections, import_batches, coverage_settings, blocks and clusters, rewrites the `import_batches.summary` id keys, resets AUTO_INCREMENT, flushes the cache and audits `locations_renumbered`. A second run changes nothing.
- **MySQL run**: before it, the tables were dumped to the session scratchpad (`before_renumber.sql`). Took 15 s. Districts 36–70 → 1–35, blocks → 1–147, clusters → 1–4607. The md5 checksum of every school's UDISE + district / block / cluster codes and of every jurisdiction's codes is identical before and after.
- **Codes shown**: `App\Models\Concerns\HasCodeLabel` (`label` = "NAME (code)", `labelFor()`) on District / Block / Cluster. Views (schools, School Master, inspections report / PDF / blank form / step, reports, filters on every list, locations, imports), `UserJurisdiction` area line, Excel exports (inspections, ratings, coverage), coverage / rating area rows, map area rows (`label`), breadcrumbs and schedule planner now show the code. Narrow selects / eager loads gained `code`.
- Files: `app/Services/LocationRenumber.php`, `app/Models/Concerns/HasCodeLabel.php`, `app/Models/{District,Block,Cluster,UserJurisdiction}.php`, `app/Services/{MapDataService,CoverageReportService,RatingReportService,ScheduleService}.php`, controllers (Report, RatingReport, Coverage, NetworkIssue, Schedule, Admin/{Import,LoginReport,SchoolMaster}), `resources/js/map.js`, about 25 Blade views, tests `LocationRenumberTest` (new) and `MapViewTest`, `CLAUDE.md`, `Product Details/Database_Architecture_and_Schema.md`.
- Verified: `php artisan test`, all passing. Pages not opened in a browser.

## 2026-11-23: Map school icons, paper form follows the questionnaire, step 2 male / female totals
- **Map**: zoomed out, grouped schools show a school icon with the count instead of a plain number bubble (`clusterIcon()` in `resources/js/map.js`, `.si-cluster` in `app.scss`), coloured by the main status (or the rating band with Colour by Rating). Clustering stops at zoom 16, so close up every school has its own pin.
- **Paper form PDF** (`inspections/blank-form.blade.php`, new partial `inspections/partials/blank-calc.blade.php`): How to fill strip, group headings as in the app, questions numbered per section, "Answer only if …" with option labels, hints, labelled remarks lines, larger boxes, page numbers, shaded calculated boxes. Driven by new `QuestionnaireDefinition::GROUPS`, `STEP_INTROS`, `PAPER_INTROS`, `CALC_BOXES` (`calcBoxes()`); the wizard now reads `GROUPS` / `STEP_INTROS` too.
- **Step 2**: the trained / untrained box shows Total male and Total female as well (`wizard.js`); Teachers absent today moved after it and, like Total teachers, is highlighted (`.auto-total.is-highlight`). Review / report: "Trained · Untrained · Male · Female" plus an absent tile; inspection PDF: four boxes incl. Teachers absent today.
- Tests: `TeacherTotalsTest` (male / female cells, absent box order and highlight, review totals), `ModificationV11Test::test_blank_form_follows_the_questionnaire`; `InspectionFlowTest` and `ModificationV10Test` updated for the male / female totals and the absent tile.
- Docs: `CLAUDE.md`, `Product Details/INSPECTION_FORM_DOCUMENT.md`, docx rebuilt.
- Verified: `php artisan test`, all passing; `npm run build`; the paper form rendered with DomPDF (8 pages) and checked as HTML in headless Chrome. The map and wizard not opened in a browser.

## 2026-11-23: Paper inspection form PDF: aligned boxes, print-readable text
- **Problem** (seen in the DomPDF output): tick boxes sat above their labels (inline-block spans with negative vertical-align), three-option answers ran off the page, each question was its own table so columns drifted, and much text was 6.4–7.2 pt light grey on pale fills, which fades on office / mono printers.
- **Fix** (`inspections/blank-form.blade.php`, new partials `inspections/partials/blank-opts.blade.php`, `blank-question.blade.php`; `blank-calc.blade.php` now outputs rows):
  - Tick box + label and number boxes are table cells. Options sit side by side only when short (Yes / No), otherwise one per line.
  - One table per section with fixed columns (widths on a first spacer row; DomPDF ignores `<colgroup>`). Totals are rows of the same table, so their number box lines up with the question boxes. A group heading is kept on the page of its first question (nested block).
  - Print-safe: body 9.5 pt, nothing under 8 pt, dark text (#111827 / #1f2937), darker borders and lines, solid dark-blue group headings, shaded and bordered total rows; override rules stop outer cell styles from leaking into nested boxes.
- Verified: rendered with DomPDF (9 pages) and every page looked at as an image (throwaway pdf-to-img in the session scratchpad, not in the project). `ModificationV11Test` checks the cell layout. `php artisan test`, all passing.

## 2026-11-23: Camera waits for the geo tag before Use photo
- `resources/js/camera.js`: location state `geoState` (locating / ok / failed). While locating, the camera header and the live stamp line show a spinner "Getting location…", and after a shot **Use photo** is disabled with the same spinner (`syncUseButton()`). The raw picture is kept, and when the location arrives it is stamped again with the coordinates (`restampShot()`) and Use photo is enabled. If the location fails (no GPS, denied, 15 s timeout), Use photo works as before with the required reason. The click handler also refuses while locating.
- Docs: `CLAUDE.md` (rule 6).
- Verified: `npm run build`; `php artisan test`, all passing. Not tried on a phone camera (no camera in this environment).

## 2026-11-23: Find School lists schools to visit first
- `SchoolController::index`: new `visits` count (Submitted / Unlocked inspections) and order active first, then visits ascending (never inspected first), then oldest last inspection, then name. Already inspected schools move down the list.
- `schools/_results.blade.php`: "Not inspected yet" is highlighted (`.school-tag.todo`, `app.scss`); inspected schools show "Inspected N times · last DD Mon YYYY"; the list header says the order.
- Test: `InspectionFlowTest::test_find_school_lists_not_inspected_first_then_least_visited`. Docs: `CLAUDE.md`, `Product Details/PROJECT_DOCUMENT.md`, docx rebuilt.
- Verified: `php artisan test`, all passing; the ordering query on MySQL for a district of 1,733 schools takes about 0.1 s (index `insp_school_status_date_idx`).

## 2026-11-23: Inspection PDF shows limited school data; a school may have more than one medium
- **PDF** (`inspections/pdf.blade.php`): the school card no longer prints School type (Boys / Girls / Co-ed) or Rural / Urban; it shows "Medium: …" with management, category and status.
- **Medium** (`School::MEDIUMS`, `normaliseMedium()`, `mediums()`, `medium_label`): stored as a list "Assamese, Bodo". Known languages are matched first ("Bishnupriya Manipuri" stays one), other text is split on capitals / commas / slashes / spaces, duplicates dropped.
  - Migration `2026_11_23_000003_normalise_school_medium`: column widened to 120 and every stored value normalised. MySQL: run 23-11-2026 in 8 s, 44,197 schools keep a medium, "Assamese, Bodo" = 1,207 (was "AssameseBodo" 1,203 + "Assamese,Bodo" 4). Backup of id + medium before the run: session scratchpad `before_medium.tsv`.
  - Import (`MasterImportService`) normalises UDISE `Med_Final_25`. School Master form: tick boxes per language plus "Other medium" (`SchoolMasterController::validated()`). School Master list: Medium filter (`medium like %X%`, also used by the Excel export).
- Tests: new `SchoolMediumTest`; `SchoolUdiseImportTest` now expects "Assamese, Bodo". Docs: `CLAUDE.md`, Database doc, docx rebuilt.
- Verified: `php artisan test`, all passing. The School Master form not opened in a browser.

## 2026-11-23: PDF background (SSA logo) and equal step 2 boxes in the report
- New partial `inspections/partials/pdf-watermark.blade.php`: faint SSA logo (opacity .07) with the title and "SSA, ASSAM", position fixed and centred, so it repeats on every page behind the content. Used by the paper form ("SCHOOL INSPECTION FORM", `blank-form.blade.php`) and the inspection report ("SCHOOL INSPECTION REPORT", `pdf.blade.php`, replacing the rotated text watermark).
- Report: the alternate row shading is see-through (rgba) so the logo shows; Teacher's & Student's Information boxes are now the table cells (equal width and height, fixed label / number / note rows so the numbers line up; "Absent today" label fits one line), edges aligned with the rows below.
- Verified: both PDFs rendered with DomPDF (report from inspection 2 on MySQL, read-only; paper form) and checked as page images; `ModificationV11Test` checks the background; `php artisan test`, all passing.

## 2026-11-23: Sidebar collapsed by default, highlighted Expand button, styled name popup
- **Default** (`components/layouts/app.blade.php` inline script): the desktop sidebar opens as the icon rail unless `localStorage['si-sidebar'] === 'full'` (the user pressed Expand). Phones unchanged.
- **Expand button** (`app.scss`, `html.sidebar-mini .sidebar-toggle`): white 36 px button with the blue chevron, a soft pulse ring (`@keyframes si-pulse`, off on hover and for reduced motion) and a green ring on hover. Its label / `aria-expanded` follow the state (Expand menu / Collapse menu).
- **Name popup** (`app.js`, `.si-nav-tip`): one body-level tooltip placed beside the hovered or focused rail icon (the sidebar clips overflow); blue gradient with an arrow, slide-in, green dot on the current page, orange count for Pending Approvals. The native `title` is removed while in the rail, so only one tooltip shows.
- Tests: new `SidebarDefaultTest`. Docs: CLAUDE.md, UI doc, docx rebuilt.
- Verified: `npm run build`, `php artisan test` 232 passed. Not checked in a browser.

## 2026-11-23: My Schools, each officer's own school list (Cluster, Block, District, State)
- **Page** `schools.mine` (`/my-schools`, `SchoolController::mine`, `resources/views/schools/mine.blade.php`), sidebar link after Find School (Find School now lights only on its own pages). Lists every school in the officer's jurisdiction through `JurisdictionService::schools()` without a search, in the Find School order (not inspected first, then fewest visits, then oldest visit; closed last).
  - Tiles: My schools, Inspected, Not inspected yet (links set the filter), Inspected this month. Scope chip ("Cluster · NAME (code)" … "Whole state").
  - Filters: name / UDISE, district / block / cluster per level, inspected yes / no. Location IDs only narrow the scope (an outside cluster lists nothing).
  - Excel `schools.mine.export` (`App\Exports\MySchoolsExport`, chunked; `export_excel`, throttle 6/min, audited `my_schools_exported`).
- `SchoolController` refactor: shared `listQuery()`, `filterOptions()`; Find School unchanged. Find School hero has a "See all my schools" link.
- Tests: new `MySchoolsTest` (4). Full suite 236 passed. MySQL (read-only timing as the State Admin, 44,197 schools): 0.76 s for the full list, 0.14 s for a search.
- Docs: CLAUDE.md, UI doc, docx rebuilt. Not opened in a browser.

## 2026-11-23: Paper form absentee table fills its page; 45° background on every PDF
- **Blank paper form** (`resources/views/inspections/blank-form.blade.php`): section 3 Teachers Absentee Form now prints 12 empty rows (`$absentRows`) instead of 6. The table starts on page 4 and ran onto page 5 with three rows, leaving the rest of page 5 empty before section 4's page break. Rendered on MySQL with the longest school name: 13 rows fit with the legend still on page 5 and 14 push a new page, so 12 keeps one spare row. The PDF still has 10 pages.
- **Background** (`inspections/partials/pdf-watermark.blade.php`, used by the paper form and the inspection report, the only two PDFs): the SSA logo, title and "SSA, ASSAM" block is turned 45° (`transform: rotate(-45deg)`, bottom-left to top-right) about its centre, so it stays centred. Checked with `pdftotext`: the watermark text is now diagonal (dropped by `-nodiag`).
- Tests: `ModificationV11Test` checks the rotation and the 12 rows. Full suite 236 passed.
- Docs: CLAUDE.md, INSPECTION_FORM_DOCUMENT.md, docx rebuilt. The rendered pages were not viewed as images (no PDF rasteriser here).

## 2026-11-23: Excel / PDF download of the School, District, Block and Cluster Masters
- **Location Master** (`Admin\LocationController::export`, route `admin.locations.export`, `/admin/locations/{type}/export/{excel|pdf}`, `manage_masters`, throttle 10/min): the State / District / Block / Cluster list with the screen's filters (search, parent; shared `listQuery()`), with parent labels, coordinates, block / cluster / school counts (`withCount`; new `District::clusters()` through blocks) and status. Download menu next to the search bar. Audited `master_exported` (master, format, filters, rows).
- **School Master PDF** (`SchoolMasterController::exportPdf`, `admin.schools.export-pdf`): the filtered list (UDISE, name, cluster, block, district, category, management, medium, status) with the filters printed. Limited to `inspection.master_pdf_max_rows` (`MASTER_PDF_MAX_ROWS`, 3000); a larger list goes back with a warning to filter or use Excel. Export PDF buttons next to Export Excel. Audited `school_list_exported` (format pdf).
- **PDF layout** (`App\Support\MasterPdf`, `admin/exports/master-pdf.blade.php`): A4 landscape, Government of Assam / SSA header with both logos, standard 45° watermark, records and filter chips, repeating heading row, Page X of Y. Rows are split into tables of 300: a single 4,607-row table ran DomPDF out of 2 GB memory.
- Verified on MySQL (read-only script): all 4,607 clusters 133 s, 201 pages, 634 MB; NAGAON's 2,188 schools 68 s, 169 pages; text checked with `pdftotext`. Exports raise `memory_limit` to `IMPORT_MEMORY` and the time limit to 300 s.
- Tests: new `MasterExportTest` (5); full suite 241 passed. Docs: CLAUDE.md, UI doc, `.env.example`, docx rebuilt.

## 2026-11-23: Paper form, Class Room Performance on a single page of its own
- `resources/views/inspections/blank-form.blade.php`: section 4 now prints 7 observation rows (`$classRows`, was 5) and section 5 Fund Received & Expenditure starts a new page, so section 4 fills exactly one page (before, Fund began under it on the same page). Rendered on MySQL: 7 rows keep the table and the Classes legend on page 6, 8 run onto page 7. The form has 9 pages.
- Tests: `ModificationV11Test` checks the 7 rows and the page break before Fund. Docs: CLAUDE.md, INSPECTION_FORM_DOCUMENT.md, docx rebuilt.

## 2026-11-23: My Schools, PDF download and a read-only Excel with the official header
- **Excel** (`App\Exports\MySchoolsExport`): rows 1–5 carry the header (GOVERNMENT OF ASSAM, Samagra Shiksha Abhiyan Assam, MY SCHOOLS, area · officer · records · generated time, filters), with the Assam and SSA logos; headings in row 6 (blue), data from row 7, frozen heading, auto filter, borders, landscape print. The sheet is protected and the workbook structure locked (password `EXCEL_PROTECT_PASSWORD`, else random per file), so the file cannot be edited; selecting and filtering still work.
- **PDF** (`SchoolController::minePdf`, route `schools.mine.export-pdf`, `export_excel`, throttle 6/min): the same scope, filters and order as the page, through `App\Support\MasterPdf` (official header, 45° background, Page X of Y), with Area / Officer / filter chips. At most `master_pdf_max_rows` (3000) schools, else a warning to filter or use Excel.
- Both downloads are audited `my_schools_exported` with `format`. Download Excel / Download PDF buttons on the page (also on phones).
- Files: `MySchoolsExport.php`, `SchoolController.php`, `routes/web.php`, `schools/mine.blade.php`, `config/inspection.php`, `.env.example`, `MySchoolsTest.php`, CLAUDE.md, UI doc.
- Verified: a MySQL render for GOSSAINICHINA gives header rows 1–5, 11 schools, a protected sheet and 2 logos. Tests: 2 new in `MySchoolsTest`; full suite 243 passed. Docx rebuilt.

## 2026-11-23: Excel logos and column sizes, School Medium removed from reports, PDF for Coverage / Inspections / My Inspections
- **My Schools Excel** (`App\Exports\MySchoolsExport`): the logos are sized into their column box (Assam 56×56 in A1, SSA up to 140×48 in the last column; before, the SSA logo was about 155 px wide in a 131 px column and spilled out of the header), rows 1–3 are taller than the logos; column widths follow the content (min 10, max 55 for School and 34 for the rest, data cells wrap), A4 landscape one page wide, Page X of N footer. Medium column removed (10 columns, A–J).
- **Medium removed** from the My Schools Excel / PDF, School Master PDF and list (column and filter), school page, map popup and inspection PDF. It stays in the School Master edit form, the import and the upload-format `SchoolListExport`.
- **PDF downloads** through `App\Support\MasterPdf` (new `sections()`, `maxRows()`, `prepare()`; the view prints captioned tables):
  - Inspections / My Inspections: `inspections.export-pdf` (`ReportController::exportPdf`, shared `exportQuery()` with the Excel export), one row per inspection with filter chips; audited `inspections_exported` with `format`.
  - Coverage: `reports.coverage.export-pdf` (`CoverageReportController::exportPdf`, `CoverageReportService::pdfSections()`): Summary + the level in view with a Total row, or the cluster's schools; audited `coverage_report_exported` with `format`.
  - All capped by `master_pdf_max_rows` with a warning; Excel + PDF buttons on desktop and phones.
- Verified: a MySQL render for GOSSAINICHINA (read-only) gives widths A13 B25 … J22, logos 56×56 and 140×48, A4. Tests: new `ReportPdfTest` (3), `MySchoolsTest` checks widths, wrap and that the logos fit, `SchoolMediumTest` updated. Full suite 246 passed. Docs: CLAUDE.md, UI doc, docx rebuilt.

## 2026-11-23: Fixed-size filter buttons, loading inside the report section, Rating Report PDF, District / Block offices on the map
- **Filter buttons**: new `<x-filter-buttons>` (Apply + Reset, `.btn-filter` 40 px / 44 px on phones, min width 112 px) on every filter bar: Inspections (mine / area), Coverage (also Apply range), Ratings, Network issues, My Schools, School Master, Users, Audit Log, Login and Daily report, Location / TLM / AC-PC masters, Schedules. Small filter fields are 40 px tall so they line up.
- **Section loading** (`resources/js/filter-partial.js`): a filter submit, an auto-submit select, pagination, sort or drill-down link fetches the page and swaps only the marked regions (`[data-filter-region]`: filter form, download buttons, results); a spinner covers only the results box (`[data-filter-results]`), the URL is pushed and Back works; errors / expired sessions fall back to a normal load. The full-page splash (`data-loader="heavy"`) is gone from these forms. `[data-autosubmit]` (with `data-clears`) and `form[data-confirm]` are now delegated in `app.js`, so swapped rows keep their confirmations.
- **Rating Report PDF** (`reports.ratings.export-pdf`, `RatingReportController::exportPdf`, view `reports/ratings/pdf`): A4 portrait, official header, watermark, filter chips, KPI cards (average in its band colour, improved / declined), band and section bars, best / lowest 5, area-wise table with band chips, school-wise table with score pills and ▲ / ▼ change; State Admin only, `export_excel`, at most `master_pdf_max_rows` schools, audited `rating_report_exported` (`format: pdf`). New `MasterPdf::view()` shares the render and page numbers. PDF buttons on the report (desktop and phone).
- **Offices on the map**: migration `2026_11_24_000002` adds `office_address` to districts and blocks (run on MySQL); the Location Master window labels latitude / longitude as the office location and adds Office address. `MapDataService::offices()` sends the offices in view (`offices` in the map JSON; jurisdiction-scoped); `map.js` draws D (blue) / B (green) office pins with a Show switch, office popups with directions and nearest / farthest school, the distance from each school to its Block and District office (approx., with a Google Maps route from the office) and a Block office column in the schools table. Area circles now use the centre of their schools. School rows carry `block_id` / `district_id`. No office location is set on MySQL yet: enter them in the Location Master (or the location upload's latitude / longitude).
- Verified: `npm run build`; a MySQL render of the Rating PDF (read-only, view rendered directly) gives 2 pages in 1.3 s; text checked with `pdftotext`. Tests: new `FilterUiTest` (3), `MapOfficesTest` (3), `RatingReportTest` PDF tests (2, replacing the "no PDF" test), `SmallFiltersTest` and `MapViewTest` updated. Full suite 254 passed. Docs: CLAUDE.md, UI doc, docx rebuilt.

## 2026-11-23: My Inspections without Excel, Coverage downloads of the selected view, planner popup fixed, school suggestions, offices without a location hidden
- **My Inspections**: the Excel button is hidden (header and phone row) and `inspections.export` `mine` returns 404; the PDF stays. Inspections in My Area keeps Excel and PDF.
- **Coverage Report**: `CoverageReportService::export()` now holds the view on screen like the PDF: Summary + the selected tab (District- / Block- / Cluster-wise) or the selected cluster's schools (shared `clusterSchoolRows()`), then Schools not visited / Due in cycle. Buttons read "Excel · Block-wise" / "PDF · Block-wise" (`viewLabel()`), files `coverage-block-wise-…`, audit meta `level`.
- **Plan Inspections popup**: found the cause: the Map View popup restyle (no padding, white close button) also hit the planner's plain `map-pop`, so text touched the edges and the close button was invisible. `schedule-planner.js` and `schedule-route.js` now build the Map View card (coloured head by status, body with status / stop and arrival, road distance, last inspected, Add / Remove and Google Maps Directions; popup built on open so it reflects the current plan). New `.map-pop-plan` styles.
- **School suggestions**: `schools.suggest` (`SchoolController::suggest`, jurisdiction-scoped, name or UDISE prefix, 10 rows, `all=1` for master managers) and `resources/js/school-suggest.js` (`data-school-suggest`, keyboard, delegated). Added to Inspections / My Inspections, My Schools and School Master. Kept out of `safeIntended()`.
- **Offices without a location**: the map's school popup lists only offices with coordinates (nothing when none), the Offices legend panel hides when the view has none. New `ScheduleService::startInfo()` (cluster → block → district office location, else the centre of the officer's schools, else none); the planner shows "Route starts at: …" under the totals and on the start marker.
- Files: `ReportController`, `CoverageReportService`, `CoverageReportController`, `SchoolController`, `ScheduleService`, `ScheduleController`, `LoginController`, `routes/web.php`, views `inspections/index`, `reports/coverage/index`, `schools/mine`, `admin/schools/index`, `schedules/plan`, `map/index`, JS `schedule-planner.js`, `schedule-route.js`, `map.js`, `school-suggest.js`, `app.js`, `app.scss`.
- Verified: `npm run build`; MySQL (read-only) `startInfo()` for Sanjay Das (DOTMA) gives the centre of the schools (no office location set). Tests: new `ModificationV14FollowUpTest` (4); full suite 258 passed. Docs: CLAUDE.md, UI doc, docx rebuilt.

## 2026-11-25: District HQ office locations from the Excel file
- Read `Documents/Lat Long of district headquarter.xlsx` (35 districts, by name). Matched it to the District Master by name (KARBIANGLONG, DIMAHASAO, SOUTH-SALMARA-MANKACHAR, WEST-KARBIANGLONG have spaces in the master). The file gave TAMULPUR the Baksa coordinates and left BAJALI blank, so as agreed both get approximate town locations (Tamulpur 26.6519, 91.5670; Pathsala 26.5039, 91.1819). They can be corrected in the Location Master.
- New `database/data/assam_district_offices.php` (code → lat / lng) and migration `2026_11_25_000001_set_district_office_coordinates` (fills only districts with no location, audited `district_offices_set`, cache flushed; `down()` no-op).
- Run on MySQL: all 35 districts set, one audit entry.
- Check against the KML boundaries (`DistrictBoundaryService::locate()`): no point is in a wrong area. Seven differences are explained: names differ (Sivasagar, Karimganj, Udalgiri, South Salmara), Tamulpur lies inside the older Baksa outline, and Kamrup-Rural (Amingaon) and Tinsukia sit at the edge of the outline next door.
- Tests: `MapOffices|Schedule` 16 passed. Docs: CLAUDE.md.

## 2026-11-25: Linked date filters, no Excel on Inspections in My Area, period named on Coverage downloads, Network Issues PDF, District HQ / Block office icons
- **Date filters** (`resources/js/date-picker.js`): From / To pairs (`from`/`to`, `custom_from`/`custom_to`, map `fFrom`/`fTo`) are linked. Once From is picked (e.g. Today), the To calendar greys out the earlier days, and To limits From the same way, within each box's own `min` / `max`. Clear removes the limit, and the map reset (`fp-sync`) drops the old limit before setting the new value. This prevents the "to must be on or after from" error.
- **Inspections in My Area**: the Excel buttons (header and phone) are removed. `ReportController::export()` and the `inspections.export` route are deleted, so both lists download as PDF only.
- **Coverage Report**: the downloads already held the period picked on screen; now they name it.
  - New `CoveragePeriod::typeLabel()`.
  - PDF title "Inspection Coverage Report · Monthly" / "· Daily", with a period chip "Monthly · November 2026 (01-11-2026 to 30-11-2026)".
  - The Summary sheet / section starts with "Report period type".
  - File names are `coverage-{daily|weekly|monthly|quarterly|custom-range}-{view}-{from}-to-{to}`, the audit meta has `type`, and the button tooltips name the period.
- **Network Issues PDF**: new route `reports.network.export-pdf` (`NetworkIssueController::exportPdf()`, `view_reports` + `export_excel`, throttle 6/min).
  - It uses the same filters and jurisdiction scope as the page (shared `filters()` / `query()`).
  - Columns: Sl, Date, UDISE, School, Block, District, Officer, Status, Problem reported.
  - Chips show the district, dates, counts and who downloaded it. At most `master_pdf_max_rows` rows. Audited `network_issues_exported`.
  - PDF button in the header (filter region `#nw-actions`) and on phones.
- **Offices on the maps**:
  - The District HQ office is purple `#6a1b9a` with a government-building icon (`bi-bank2`) and an "HQ" badge.
  - The Block office is teal `#00838f` with a house icon (`bi-house-door-fill`) and a "B" badge. Both colours are apart from the school status / rating colours.
  - A shared `OFFICE` table in `map-common.js` feeds the pins, tooltips, popups, the school-popup distance rows and the legend.
  - The **Plan Inspections map** now draws the planned officer's offices (`ScheduleController::plannerOffices()` → cfg `offices`, from `MapDataService::offices()` scoped to that officer), with a popup and Google Maps directions. A Cluster officer sees their District HQ and Block office.
- Files: `resources/js/date-picker.js`, `map-common.js`, `map.js`, `schedule-planner.js`, `resources/scss/app.scss`, `app/Http/Controllers/ReportController.php`, `CoverageReportController.php`, `NetworkIssueController.php`, `ScheduleController.php`, `app/Services/CoverageReportService.php`, `app/Support/CoveragePeriod.php`, `routes/web.php`, views `inspections/index`, `reports/coverage/index`, `reports/network/index`, `map/index`, tests `CoverageReportTest`, `ModificationV11Test`, `ModificationV14FollowUpTest`, `InspectionFlowTest`, `MapOfficesTest`, `CLAUDE.md`.
- Verified: `npm run build`. On MySQL (read-only), demo Cluster officer Arjun Saikia gets the KOKRAJHAR (1801) HQ office; no block office has a location yet. The route `reports.network.export-pdf` is registered.
  - New tests: Coverage daily / monthly downloads (dates, Summary, file names, audit), Network PDF (scoped, audited), Cluster officer offices on the map and planner, and the area list without Excel.
  - Full suite: 261 passed (3155 assertions).

## 2026-11-25: Network remark optional, photos the same size in the inspection PDF
- **Network question** (`NET_01`, step 1): Yes still opens the remarks box, but the remark is no longer mandatory.
  - New flag `'remarks_optional' => true` in `QuestionnaireDefinition`.
  - `InspectionValidator::remarksShown()` decides when the box shows; `remarksRequired()` is false for optional remarks.
  - The wizard label reads "Remarks (optional)" with no red star, and the paper form prints "Remarks (optional, if Yes)".
  - The Network Issues list and PDF already show "No remark" when it is empty.
- **Inspection PDF photos**: new `App\Support\PdfPhoto` fits every photo, uncropped (the stamp strip stays whole), into one 1200 × 900 (4:3) light canvas as JPEG. `ReportController::pdf()` embeds that.
  - `pdf.blade.php`: `.frame img` is full width with automatic height, so portrait, landscape and gallery photos all print at the same size. The caption has a minimum height so the cards in a row line up.
  - If GD is missing or the image cannot be read, the original is used.
- Files: `app/Questionnaire/QuestionnaireDefinition.php`, `InspectionValidator.php`, `app/Support/PdfPhoto.php` (new), `app/Http/Controllers/ReportController.php`, `NetworkIssueController.php` (comment), views `inspections/partials/question`, `blank-question`, `inspections/pdf`, tests `ModificationV11Test`, `tests/Unit/PdfPhotoTest.php` (new), `CLAUDE.md`, `Product Details/INSPECTION_FORM_DOCUMENT.md`.
- Verified: full suite 263 passed. New tests:
  - A network Yes without a remark is not missing, and the step shows the optional box.
  - Portrait, landscape and large photos all come out as 1200 × 900 JPEG.
  - Unreadable bytes are kept as they are.

## 2026-11-25: V14 updated document, Module A: Holiday calendar
- Reviewed the updated `Documents/Modification of the ApplicationV14.docx`. It has five items:
  1. Orders / notices
  2. Self-planned visits
  3. Holiday calendar
  4. Admin-assigned visits with an order
  5. Today's schools with 10 admin settings

  Agreed plan: build them module by module (A holidays, B notices, C assigned visits, D Today's schools), each with an ACTION entry, a commit and a push.
- **Holiday calendar** (`/holidays`, sidebar "Holiday Calendar" for everyone):
  - Month grid plus the year's list. State holidays are red; local (district) holidays are orange.
  - The State Admin adds state holidays and any district's local holidays. A District officer with Manage users adds, edits and deletes their own district's (gate `manage_holidays`).
  - The Excel import (`date`, `name`, `district_code`) checks every row before saving and skips holidays already in the calendar. A template can be downloaded.
  - Audited: `holiday_created` / `_updated` / `_deleted`, `holidays_imported`.
- **Planner:** a plan cannot be saved on a holiday of the officer's district ("… is a holiday: name"). The schedule calendar shows the holiday and hides "Plan this day".
- Files:
  - new: migration `2026_11_25_000002_create_holidays`, `app/Models/Holiday.php`, `app/Services/HolidayCalendar.php`, `app/Http/Controllers/HolidayController.php`, `resources/views/holidays/index.blade.php`, `tests/Feature/HolidayCalendarTest.php`
  - changed: `AppServiceProvider` (gate), `config/inspection.php` (`holidays`), `ScheduleService::checkDate()`, `ScheduleController::index()` + `schedules/index` view, `routes/web.php`, sidebar layout, `app.scss`
  - docs: `CLAUDE.md`, `Product Details/PROJECT_DOCUMENT.md` §14.1; the V14 docx copied to `Product Details/Requirements/`
- Verified:
  - `npm run build`.
  - Migrated on MySQL.
  - New tests (4): state and district rights, officers see only their district, the import checks all rows, no plan on a holiday while another district is not blocked.
  - Full suite: 267 passed.

## 2026-11-25: V14 updated document, Module B: Notices & Orders (Instruction / Notification / Order letters)
- **Sidebar "Notices & Orders"** (everyone):
  - **Inbox:** notices addressed to the officer's level and area, with unread ones highlighted. Filters: type, search, unread only.
  - **Sent tab:** for senders, showing read and acknowledged counts.
  - **New notice** (gate `issue_notices`: the State Admin, or a District officer with Manage users in their own district): type, letter no., date, subject, message, an optional attachment (PDF / Word / image ≤ 5 MB, private disk), show until, and the audience (levels below the sender, plus district / block / cluster).
- **Notice page:**
  - Opening it marks it read, and the Acknowledge button records the confirmation. The attachment opens and downloads through a checked, audited route.
  - Senders see "Who has read it" (officers / read / acknowledged and a per-officer list) and can Withdraw the notice.
- **Where officers see notices:** the dashboard card "Notices & orders" (latest 3, unread first), and an unread badge in the sidebar and on the topbar megaphone (cached 60 s).
- **Audit:** `notice_published`, `notice_acknowledged`, `notice_attachment_viewed`, `notice_withdrawn`.
- **Files:**
  - new: migration `2026_11_25_000003_create_notices`, `app/Models/Notice.php`, `NoticeRead.php`, `app/Services/NoticeService.php`, `app/Http/Controllers/NoticeController.php`, views `notices/index`, `create`, `show`, `tests/Feature/NoticeTest.php`
  - changed: `AppServiceProvider` (gate), `config/inspection.php` (`notice`), `routes/web.php`, the sidebar / topbar layout, `DashboardController` + `dashboard` view, `app.scss`
  - docs: `CLAUDE.md`, `Product Details/PROJECT_DOCUMENT.md` §14.2
- **Verified:**
  - `npm run build`, and migrated on MySQL (the JSON level filter works there).
  - New tests (3): audience by level and area, read and acknowledge counts, attachment access, withdraw, a district sender kept to their own district, a cluster-only notice, and an expired notice hidden.
  - Full suite: 270 passed.

## 2026-11-25: V14 updated document, Module C: assigned visits with an order, pending visits
- **Order with the plan (item 4):** the planner has an "Order / letter no." box and an "Attach the order" file (PDF, Word or image ≤ 5 MB, private disk `schedule-orders/`).
  - The planner now sends the plan as multipart `FormData`. JSON posts still work.
  - `ScheduleService::attachOrder()` stores or replaces the file and audits `schedule_order_attached`.
  - `schedules.attachment` serves it to the officer and their higher officers only, audited `schedule_order_viewed`.
  - The plan page and the dashboard Today's plan show "Assigned by … · Order … · file". The calendar chip shows a paperclip.
- **Self-planned visits (item 2):** a school still planned after its day shows **Pending** (`InspectionScheduleItem::isPending()`). The calendar status filter has "Pending visits (day passed)". A test confirms that State, District, Block and Cluster officers can each plan their own visits.
- **Files:**
  - new: migration `2026_11_25_000004_add_order_to_inspection_schedules`, partial `schedules/partials/order`, `tests/Feature/ScheduleOrderTest.php`
  - changed: `InspectionSchedule` / `InspectionScheduleItem` models, `ScheduleService`, `ScheduleController` (store, attachment, index filter), `routes/web.php`, `resources/js/schedule-planner.js`, views `schedules/plan`, `show`, `index`, `dashboard`, `DashboardController`
  - docs: `CLAUDE.md`, `Product Details/PROJECT_DOCUMENT.md` §14.3
- **Verified:**
  - `npm run build`, and migrated on MySQL.
  - New tests (3): an order assigned by the Block officer opens for the CRCC but not for an outsider, a bad file type is refused, JSON without a file still works, Pending shows on the plan page and in the filter, and every level plans for itself.
  - Full suite: 273 passed.

## 2026-11-25: V14 updated document, Module D: Today's Schools (daily school display) with admin settings
- **Today's Schools** (sidebar, dashboard card, `/today`):
  - The CRCC's schools for the day, each with a reason, the distance from the office, Route, Start (geo-captured) and Skip with a reason.
  - The list is made once on the first view of a working day and stays the same all day.
  - Submitting an inspection marks the school Visited.
  - No list on holidays or weekly off days. The last 30 days can be looked back on.
- **All 10 settings from the document's table are done:**
  - holidays
  - daily limit (default 2)
  - random selection
  - not yet inspected first
  - low-rated schools (threshold %)
  - issue-based priority (configured bad answers on the latest inspection)
  - pending school list (carry forward to the next working day)
  - less frequently visited
  - recently visited restriction (days)
  - manual school selection (Assign schools for higher officers, with remove)
- **More options proposed and built:**
  - which officer levels get the list
  - weekly off days
  - maximum distance from the office
  - skip schools being inspected / already done this month by another officer
  - an on / off switch per district
- **Settings screen** (`/today/settings`): the State Admin sets the state default plus district settings (a list with reset); a District officer with Manage users sets their own district or uses the state default. Audited.
- **The "Start inspection" geo capture** (`form[data-geo-start]`) moved from `schedule-route.js` into `app.js` (delegated), so the plan page and Today's Schools share it.
- **Files:**
  - new: migration `2026_11_25_000005_create_daily_school_lists`, `app/Models/DailyDisplaySetting.php`, `DailySchoolList.php`, `app/Services/DailySchoolListService.php`, `app/Http/Controllers/TodaySchoolsController.php`, views `today/index`, `assign`, `settings`, `tests/Feature/TodaySchoolsTest.php`
  - changed: `InspectionController::submit` (`markVisited`), `ScheduleService::blockedSchools()` (now public), `config/inspection.php` (`daily_list.issue_answers`), `DashboardController` + `dashboard` view, `routes/web.php`, the sidebar, `resources/js/app.js`, `schedule-route.js`, `app.scss`
  - docs: `CLAUDE.md`, `Product Details/PROJECT_DOCUMENT.md` §14.4
- **Verified:**
  - `npm run build`, and migrated on MySQL.
  - On MySQL (read-only `pick()`), Arjun Saikia (GOSSAINICHINA) gets 2 never-inspected schools in 0.07 s.
  - New tests (7): the limit of 2, made once, the dashboard, level switch off; priority order (never inspected, then low rating, then less visited) with recent schools left out, and random mode; issue priority; carry forward, visited at submit and skip; holiday / weekly off; manual assignment (scope, remove, fills the limit); settings (state, district override, reset, rights).
  - Full suite: 280 passed.

## 2026-11-25: Assam holiday list 2026 (with Restricted Holidays), holiday template, dashboard Calendar & Notices
- **Request:** show the Calendar & Notices on the dashboard; add the Government of Assam holidays including the Restricted Holidays from `Documents/HolidayList2026.pdf` (GAD e-file No.221561/440, 17-11-2025) to the database; make an Excel template.
- **Decisions (user):** Restricted holidays are **shown only** (they do not block Today's schools or Plan Inspections); 2nd / 4th Saturdays are **not** treated as off days.
- **Holiday types:** new column `holidays.type`: `gazetted` (office closed, the only type that blocks), `restricted`, `half` (Lakshmi Puja, from 1 PM). `Holiday::TYPES`, `OFF_TYPES`, `isOff()`, `typeLabel()`, `chipClass()`. `HolidayCalendar::year()` (so `holiday()`, `isWorkingDay()`, `offReason()`, plan checks and the daily list) reads gazetted only; new `between()` and `upcoming()` return every type for display.
- **Holiday Calendar page:** Type select in Add / Edit, chips per type (restricted dashed purple, half teal), Restricted Show / Hide filter, legend; only gazetted days get the red day background.
- **Data loaded:** `database/data/assam_holidays_2026.php` + `AssamHolidaySeeder` (in `DatabaseSeeder`; safe to re-run): 35 gazetted, 1 half, 39 restricted state rows, and Ali-Aye-Ligang (18-02) as a full local holiday for the 11 districts of para 9 (matched by code, else name; Sivasagar = SIBSAGAR 1816). Run on MySQL: 86 added (46 gazetted incl. 11 district rows, 1 half, 39 restricted).
- **Excel template:** `App\Exports\HolidayTemplateExport` + `HolidaySheet`: sheet Holidays (`date`, `name`, `type`, `district_code`) and sheet Value lists (types, district codes, notes). The page's Template menu has **Blank template** and **Assam holiday list 2026 (filled)** (`holidays.template?fill=assam-2026`). The import reads `type` (blank = gazetted; aliases holiday / RH / R.H. / half holiday; unknown = row error). Filled file saved as `Documents/HolidayList2026.xlsx` (86 rows) and copied with the PDF to `Product Details/Requirements/`.
- **Dashboard Calendar & Notices** (`dashboard/partials/calendar.blade.php`, every user): mini month calendar (today ringed, weekly off grey, holiday red, dots per type, names on hover), Upcoming holidays (next 6, with type chip), and the Notices & orders list beside it; the State Admin now sees the latest notices **sent** with read counts (was hidden).
- **Files:** migration `2026_11_25_000006_add_type_to_holidays`, `app/Models/Holiday.php`, `app/Services/HolidayCalendar.php`, `app/Http/Controllers/HolidayController.php`, `DashboardController.php`, `app/Exports/HolidayTemplateExport.php`, `HolidaySheet.php`, `database/data/assam_holidays_2026.php`, `database/seeders/AssamHolidaySeeder.php`, `DatabaseSeeder.php`, views `holidays/index`, `dashboard`, `dashboard/partials/calendar`, `app.scss`, `tests/Feature/HolidayCalendarTest.php`, docs `CLAUDE.md`, `Product Details/PROJECT_DOCUMENT.md` §14.1.
- **Verified:** `npm run build`; new tests (3): restricted / half not blocking but shown (page, filter, dashboard, admin type, sent notices), import type column + both template downloads, seeder counts / re-run / district rows; full suite 283 passed. MySQL: 18-02-2026 is off for LAKHIMPUR and a working day for KOKRAJHAR; 15-04-2026 is off (Bohag Bihu).

## 2026-11-25: Menu order, Restricted holidays in green, cleaner dashboard
- **Request:** move Notices & Orders after Map View; show Restricted holidays in green; make the dashboard more attractive and clean.
- **Sidebar:** Notices & Orders now comes right after Map View (before Inspection Schedules). The Rating Report row is written inline after Inspections in Area (gate `state_admin`) instead of the old `array_splice` at a fixed index.
- **Colours:** `.holiday-chip.is-restricted` and `.mini-dot.is-restricted` are green (`$success`); the half holiday moved from teal to sky blue (`$half-holiday` #0284c7) so the two don't look alike. Applies to the Holiday Calendar page and the dashboard.
- **Dashboard:**
  - Quick-link pills in the welcome banner (Today's schools, Plan inspections, Map, Notices with the unread count, Holidays; by permission).
  - KPI cards have a colour top bar.
  - The body has two columns: work on the left (Today's schools, Today's plan, Recent submissions + drafts) and a right rail (`col-xl-4`) with the Calendar card and the Notices card stacked.
  - The cards are split into the partials `dashboard/partials/calendar` and `dashboard/partials/notices`.
  - Panels have a soft shadow, a divided header with a tinted icon square and a pill link, and hairline row dividers.
  - In the mini calendar, today is a filled blue circle.
  - Upcoming holidays shows the next 4 with "today / tomorrow / in N days".
- **Files:** `resources/views/components/layouts/app.blade.php`, `resources/views/dashboard.blade.php`, `dashboard/partials/calendar.blade.php`, new `dashboard/partials/notices.blade.php`, `DashboardController.php` (`$noticeUnread`, 4 upcoming), `resources/scss/app.scss`, `tests/Feature/HolidayCalendarTest.php`, `CLAUDE.md`.
- **Verified:** `npm run build`; new test (menu order, Rating Report position, dashboard rail); full suite 284 passed.

## 2026-11-25: Today's Schools Settings, officer levels locked to the State for District users
- **Request:** "Officers who get the list": for a District user, the State default choice is ticked automatically and cannot be unticked.
- **Decision (user):** lock it for District users only; the State Admin can still give one district its own levels.
- **Change:**
  - Migration `2026_11_25_000007_daily_settings_levels_nullable`: `daily_display_settings.levels` is nullable, and a null on a district row means "follow the State". District rows last saved by a non-State-Admin were set to null.
  - `DailyDisplaySetting::forDistrict()` fills the State's levels in memory; new `followsStateLevels()`.
  - `TodaySchoolsController::updateSettings()` ignores posted levels from a District user and stores null; the audit meta has `levels: state`.
  - The settings view shows the State's levels ticked and disabled for District users, with "Set by the State Administrator (State default)".
- **Files:** the migration, `app/Models/DailyDisplaySetting.php`, `app/Http/Controllers/TodaySchoolsController.php`, `resources/views/today/settings.blade.php`, `tests/Feature/TodaySchoolsTest.php`, `CLAUDE.md`.
- **Verified:**
  - Migrated on MySQL: the column is nullable, and the one district row (KOKRAJHAR) was set by the State Admin, so it keeps its own levels.
  - New test: locked boxes, a crafted post ignored, the district follows a later State change, the State Admin's district override kept.
  - Full suite: 285 passed.

## 2026-11-25: Installed Packages & Libraries report as a Word document
- **Request:** a report of the installed packages and libraries and what each is used for, as a Word document.
- **Change:**
  - `Product Details/Installed_Packages_and_Libraries.md` was rewritten from the installed state (`composer.lock`, `package-lock.json`, `composer audit` / `npm audit` / `outdated`, a code search for each use). It covers:
    - Summary
    - Runtime and PHP extensions
    - Composer production, development and key dependencies
    - npm bundled packages (now with `flatpickr`) and build tools
    - The docs builder
    - Browser APIs
    - External services
    - A use case → package map
    - Recommendations
  - The current PDF and Excel uses are listed, and the removed inspection Excel export is gone.
  - New Word file `Product Details/Installed_Packages_and_Libraries.docx`.
  - `tools/docs/build-product-docx.cjs` has a single-document mode: `--only=<file.md> [--out=] [--title=]`. With no flags it builds the combined document as before.
  - Rebuilt `School_Inspection_Product_Details.docx`.
- **Findings:**
  - 129 PHP packages (94 production / 35 development), 158 npm packages; no known vulnerabilities.
  - Updates available: Laravel 12.72 → 12.74, autoprefixer and node-forge patch releases, Tinker 3.x (major).
  - `@fontsource-variable/inter`, `laravel/sail` and `mockery/mockery` are unused.
- **Verified:** both builds wrote their files, and the new .docx contains the package sections. Documentation only; no application code changed.

## 2026-11-25: Today's Schools shows open drafts; smaller sidebar
- **Request:** a school whose inspection was started but not completed should still show on Today's Schools. The sidebar items should be reduced.
- **Decisions (user):**
  - Show the draft on the row, with Continue.
  - Merge sibling pages into tabs, and make Users and Masters & Admin collapsible groups.
- **Today's Schools change:**
  - New `DailySchoolListService::openDrafts()`: the officer's own Draft for each listed school, in one query.
  - On `/today`, a pending row with a draft shows "Draft in progress · N %" and the last-saved time, and Continue in place of Start (on any date). Skip is hidden. The row is styled `.is-draft` (amber accent).
  - The dashboard card shows a "Draft N %" chip.
  - The school stays pending, and carries forward, until it is submitted.
- **Sidebar change:**
  - Dashboard, Today's Schools, **Schools**, **Inspections**, **Reports**, Map View, Notices & Orders, Inspection Schedules, Holiday Calendar. That is 9 items, down from 13.
  - New component `<x-page-tabs>` (existing `.report-tabs` pill style) on Find School / My Schools, My / Area Inspections, and Coverage / Rating / Network Issues. Each tab keeps its own permission.
  - The **Users** (3) and **Masters & Admin** (8) groups fold away and are open while one of their pages is open. In the icon rail, their links always show.
  - The nav gate is now a closure, so an item can need either of two permissions.
- **Files:**
  - `app/Services/DailySchoolListService.php`
  - `app/Http/Controllers/{TodaySchoolsController,DashboardController}.php`
  - `resources/views/today/index.blade.php`, `dashboard.blade.php`
  - `components/layouts/app.blade.php`, new `components/page-tabs.blade.php`
  - six list / report views (the tabs)
  - `resources/scss/app.scss`
  - tests: `TodaySchoolsTest` (new draft test), `HolidayCalendarTest`, `MySchoolsTest`, `RatingReportTest`, `CoverageReportTest`, `ModificationV9Test`
  - `CLAUDE.md`
- **Verified:** `npm run build`; full suite 286 passed.

## 2026-11-25: Product Details Word document updated
- **Request:** update the Product Details Word document.
- **Change:**
  - `PROJECT_DOCUMENT.md` (header now covers V9–V14):
    - §14.1 has the dashboard Calendar & Notices rail and its holiday colours.
    - §14.4 has the draft rows (Draft in progress · N %, Continue) and the officer levels as the State's choice, locked for District users.
    - New §14.5 "Navigation: a smaller sidebar" (9 items, the tabs table, the folding groups).
  - `UI_Pages_and_Application_Modules.md`: sidebar section.
  - `User_Roles_and_Permissions.md`: §5 navigation table, rebuilt for the merged items, tabs and groups, adding Today's Schools, Notices, Holidays and the State Admin-only Rating tab.
  - Rebuilt `Product Details/School_Inspection_Product_Details.docx`.
- **Verified:** the build wrote the file (3.7 MB), and the new sections are in `word/document.xml`.

## 2026-11-25: Today's Schools Settings, District users choose Block / Cluster officers only
- **Request:** on Today's Schools Settings, a District user's "Officers who get the list" should offer only Block and Cluster.
- **Decision (user):** editable for their district, starting from the State's ticks. District and State officers follow the State default.
- **Change:**
  - New constants `DailyDisplaySetting::DISTRICT_LEVELS` (cluster, block) and `STATE_ONLY_LEVELS` (district, state).
  - `forDistrict()` now merges the district's own Cluster / Block choice with the State's District / State choice. A null row still follows the State for every level.
  - `TodaySchoolsController::updateSettings()` keeps only Cluster / Block for any district save, so a crafted level is dropped. The state default keeps all four.
  - `today/settings.blade.php`: every district form (the District user's, and the State Admin's "Set a district of its own") shows only editable Cluster (CRCC) and Block boxes, with "District and State officers follow the State default". The lock from 636149d is removed.
- **Files:**
  - `app/Models/DailyDisplaySetting.php`, `app/Http/Controllers/TodaySchoolsController.php`
  - `resources/views/today/settings.blade.php`
  - `tests/Feature/TodaySchoolsTest.php`: `test_district_user_chooses_block_and_cluster_only` replaces the locked test
  - `CLAUDE.md`, `Product Details/{PROJECT_DOCUMENT,User_Roles_and_Permissions}.md`, rebuilt `School_Inspection_Product_Details.docx`
- **Verified:**
  - Full suite 286 passed.
  - On MySQL the State default is Cluster only. KOKRAJHAR's own row (all four, saved by the State Admin) now gives Cluster + Block: its District / State officers follow the State, which does not tick them.

## 2026-11-25: Notices & Orders demo data; State Administrator shows the male photo
- **Request:** insert demo data for the Notices & Orders module, and give the System (State) Administrator the male photo.
- **Change:**
  - New `database/seeders/DemoNoticeSeeder.php` (non-production; re-runnable by `ref_no`; called from `DatabaseSeeder` after `DemoUserSeeder`). It adds 8 notices:
    - State Admin: an order with a DomPDF order letter attached (`notices/` on the private disk), an instruction, a notification, one expired notice and one withdrawn notice.
    - KOKRAJHAR district: an instruction to Block and Cluster officers.
    - DHUBRI district: an order to Cluster officers.
    - DOTMA block: a notification to Cluster officers.
    - Plus 3 read / acknowledged rows. Each new notice is audited `notice_published` with `demo: true`.
  - `DemoUserReset`: deletes the removed officers' notices and attachment files first (`notices.created_by` restricts the delete), then runs `DemoNoticeSeeder` again.
  - `StateAdminSeeder`:
    - sets `gender = ADMIN_GENDER` (default male; also fills a null gender on an existing admin), so `<x-user-avatar>` shows `img/avatar-male.svg`
    - now reuses the existing State Admin when their mobile was changed. Before, it failed on the unique `employee_id`.
  - `.env.example`: `ADMIN_GENDER=male`.
- **Files:**
  - `database/seeders/{DemoNoticeSeeder,StateAdminSeeder,DatabaseSeeder}.php`, `app/Services/DemoUserReset.php`, `.env.example`
  - new `tests/Feature/DemoNoticeSeederTest.php`
  - `CLAUDE.md`, `Product Details/PROJECT_DOCUMENT.md`, rebuilt Word file
- **Run on MySQL:** `db:seed --class=StateAdminSeeder` (admin gender male, still one admin) and `db:seed --class=DemoNoticeSeeder`: 8 notices, 3 reads, 5 unread for demo CRCC 9000000031.
- **Verified:**
  - Full suite 289 passed before the seeder fix.
  - After the fix: the DemoNoticeSeeder and V13 tests, 14 passed.

## 25-11-2026: Dashboard "Latest notices" news ticker
- **What:** a breaking-news style strip at the top of the Dashboard scrolls the latest live Notices & Orders (officer: `Notice::for()`, up to 8; State Admin: every live published notice). Type pill, title, ref no, sender, date, "NEW" while unread; click opens the notice; View all. Pure CSS marquee (list twice, copy `aria-hidden`), pauses on hover / focus, no motion with `prefers-reduced-motion`, hidden when there are no live notices.
- **Files:** `app/Http/Controllers/DashboardController.php`, `resources/views/dashboard.blade.php`, new `resources/views/dashboard/partials/ticker.blade.php`, `resources/scss/app.scss` (`.news-ticker`), new `tests/Feature/DashboardTickerTest.php`, `CLAUDE.md`, `Product Details/PROJECT_DOCUMENT.md`, rebuilt Word file.
- **Verified:** `npm run build`; full suite 291 passed.

## 25-11-2026: Ticker slower, newest notice only
- **What:** the Dashboard ticker now shows only the newest live notice (`take(1)`) and crawls in a fixed 40 s loop; the notice repeats 3× per half of the track so the strip stays full (repeats `aria-hidden`, not focusable, hidden with reduced motion).
- **Files:** `DashboardController.php`, `dashboard/partials/ticker.blade.php`, `app.scss`, `DashboardTickerTest.php`, `CLAUDE.md`, `PROJECT_DOCUMENT.md`, rebuilt Word file.
- **Verified:** `npm run build`; full test suite (see commit).

## 25-11-2026: Latest Notice ticker redesigned
- **What:** the ticker is now a rounded gradient pill (white → light blue → light orange, soft blue shadow) with a red → orange "Latest Notice" label and a pulsing white megaphone badge, bordered type chip, bold title, meta with # ref / person / calendar icons, a gold "NEW" pill, blue diamond separators and a white "View all" pill whose arrow moves on hover. Phones: icon-only label, no meta. Behaviour unchanged (newest notice only, 40 s, pause on hover / focus, reduced motion).
- **Files:** `resources/scss/app.scss` (`.news-ticker*`), `resources/views/dashboard/partials/ticker.blade.php`, `CLAUDE.md`.
- **Verified:** `npm run build`; full suite 291 passed.

## 25-11-2026: Notices & Orders, homepage (Latest Notice) setting per notice
- **What:** `notices.show_on_home` (default true). The New notice form has the switch "Show in Latest Notice on the homepage" (ticked by default); the notice page has Show on / Remove from homepage for whoever can manage the notice (`POST notices/{notice}/home`, `NoticeController::home()`, audited `notice_home_changed`). The Dashboard ticker shows only the newest live **ticked** notice. Fix on the way: `NoticeService::checkAudience()` rebuilds the data from a fixed key list, so it now passes `show_on_home` through.
- **Demo:** `SSA/INSP/2026/098` (camera instruction) is off the homepage.
- **Files:** migration `2026_11_25_000008_add_show_on_home_to_notices`, `app/Models/Notice.php`, `app/Services/NoticeService.php`, `app/Http/Controllers/{NoticeController,DashboardController}.php`, `routes/web.php`, `resources/views/notices/{create,show}.blade.php`, `database/seeders/DemoNoticeSeeder.php`, `tests/Feature/DashboardTickerTest.php` (+2 tests), `CLAUDE.md`, `Product Details/PROJECT_DOCUMENT.md`, rebuilt Word file.
- **Run on MySQL:** `migrate` (done), `db:seed --class=DemoNoticeSeeder` (098 now off).
- **Verified:** full suite 293 passed.

## 25-11-2026: Sidebar, Masters and Admin Settings separated
- **What:** the folding group "Masters & Admin" is split into **Masters** (Location, AC / PC, School, TLM Masters, Excel Upload) and **Admin Settings** (Rating Settings, plus the Coverage Settings and Today's Schools Settings pages that were not in the sidebar before, Login Monitoring, Audit Log). Each link keeps its own gate: a District officer with Manage users sees Admin Settings with only Coverage / Today's Schools Settings. The Reports and Today's Schools items no longer light up on those settings pages.
- **Files:** `resources/views/components/layouts/app.blade.php`, `tests/Feature/HolidayCalendarTest.php`, `CLAUDE.md`, `Product Details/{PROJECT_DOCUMENT,UI_Pages_and_Application_Modules,User_Roles_and_Permissions}.md`, rebuilt Word file.
- **Verified:** full suite 293 passed.

## 25-11-2026: State Administrator, Clean Test Data
- **What:** Admin Settings → **Clean Test Data** (State Admin; no link and refused in production) and `php artisan inspections:clean-test-data [--force]`. The page lists the 13 tables with their row counts and the SQL (`DELETE FROM <table>;`, after `UPDATE inspections SET prefilled_from_id = NULL;`), the files on disk and what is kept; typed `DELETE` empties them in one transaction, deletes photo / order files, flushes the cache, audits `test_data_cleaned` (counts per table) and shows a Cleaned table. Tables: `inspection_classroom_tlm`, `inspection_classroom_observations`, `inspection_absent_teachers`, `inspection_fund_positions`, `inspection_answers`, `inspection_remarks`, `inspection_ratings`, `inspection_evidence` (+ photo files), `daily_school_lists`, `daily_school_list_days`, `inspection_schedule_items`, `inspection_schedules` (+ order files), `inspections` (after `UPDATE inspections SET prefilled_from_id = NULL`).
- **Files:** new `app/Services/TestDataCleaner.php`, `app/Http/Controllers/Admin/TestDataController.php`, `resources/views/admin/test-data/{index,table}.blade.php`, `tests/Feature/TestDataCleanerTest.php` (3 tests); `routes/web.php`, `routes/console.php`, `resources/views/components/layouts/app.blade.php`, `CLAUDE.md`, `Product Details/{PROJECT_DOCUMENT,User_Roles_and_Permissions}.md`, rebuilt Word file.
- **MySQL:** not cleaned (left to the administrator). Preview now: 5 inspections, 218 answers, 12 photos, 5 classroom rows, 28 TLM ticks, 3 absentee, 3 fund, 3 ratings, 5 Today's Schools rows, 2 day markers.
- **Verified:** full suite 296 passed.

## 25-11-2026: Notice types master; Clean Test Data in four parts, local server only
- **Notice types:** new master `notice_types` (migration `2026_11_25_000009`, 6 defaults: Instruction, Notification, Order, **Publication**, **Meeting Minutes**, **Information**; `notices.type` widened to 30). `App\Models\NoticeType` (cached map), `Notice::typeLabel/typeIcon/typeColor`, **Masters → Notice Types** (`Admin\NoticeTypeController`: add, edit window, make inactive; code fixed; never deleted; audited). Compose offers active types only and validates an active code. Chip colours come from the master (`--nt` CSS variable; the fixed `.is-instruction/.is-notification/.is-order` rules removed). Demo: + a Publication and a Meeting Minutes notice (10 in all).
- **Clean Test Data:** four parts with their own table + SQL and own DELETE: Inspections & Today's Schools, Inspection Schedules (`inspection_schedules`, `inspection_schedule_items`), Login log (`user_login_logs`), Audit log (`audit_logs`, Excel archive first via `HousekeepingService::purgeAudit(0, true)`, new `$everything` flag). Command `data:clean-test {group|all}` (old `inspections:clean-test-data` kept).
- **Security:** only when `TestDataCleaner::enabled()` = not production and `TEST_DATA_CLEANUP` (config `inspection.test_data_cleanup`, default on only for APP_ENV local / testing). Otherwise no link, **404** on the page and POST, commands refuse. `.env.example`: flag + production checklist line.
- **Files:** migration, `app/Models/{NoticeType,Notice}.php`, `app/Http/Controllers/Admin/{NoticeTypeController,TestDataController}.php`, `app/Http/Controllers/NoticeController.php`, `app/Services/{TestDataCleaner,HousekeepingService}.php`, `config/inspection.php`, `.env.example`, `routes/{web,console}.php`, views `admin/notice-types/*`, `admin/test-data/index`, `notices/{create,index,show}`, `dashboard/partials/{notices,ticker}`, `components/layouts/app`, `resources/scss/app.scss`, `database/seeders/DemoNoticeSeeder.php`; tests `NoticeTypeTest` (new), `TestDataCleanerTest` (rewritten), `DemoNoticeSeederTest`; docs.
- **MySQL:** migrated; DemoNoticeSeeder added the 2 new notices; Clean Data is on (APP_ENV=local).
- **Verified:** `npm run build`; full suite 300 passed.

## 25-11-2026: Notices & Orders, opening a notice clears its New tag and the counters update live
- **What:** clicking a notice marks it read on the page at once (its New tags go from the Inbox, dashboard card and ticker; every counter drops by one), the server marks it read when it opens, and Back shows it read. Counters (sidebar badge, topbar megaphone dot, Inbox tab, dashboard card, banner pill) are fetched again from `GET notices/unread-count` on Back, tab focus and every minute. Server fix: `NoticeService::markRead()` now flushes the cached count also when an existing read row gets its `read_at`.
- **Files:** new `resources/js/notice-badge.js` (+ `app.js` import), `app/Http/Controllers/NoticeController.php` (`unreadCount()`), `app/Services/NoticeService.php`, `app/Http/Controllers/Auth/LoginController.php` (deny list), `routes/web.php`, views `components/layouts/app`, `notices/index`, `dashboard`, `dashboard/partials/{notices,ticker}`, `tests/Feature/NoticeTest.php` (+1 test), `CLAUDE.md`, `Product Details/PROJECT_DOCUMENT.md`, rebuilt Word file.
- **Verified:** `npm run build`; full suite 301 passed.

## 25-11-2026: Calendars, click a date to see / add / remove holidays
- **What:** on the Holiday Calendar and the dashboard calendar, clicking a date that has a holiday opens a popup with its holidays; the State Administrator (and a District user manager for their own district) can click any date to add a holiday on it or remove one from the popup. Adding from the dashboard returns to the dashboard; form errors reopen the popup.
- **Files:** new `resources/views/holidays/partials/day-modal.blade.php`, `resources/js/holiday-day.js` (+ `app.js`), `app/Services/HolidayCalendar.php` (`editableDistricts()`, `dayData()`), `app/Http/Controllers/{HolidayController,DashboardController}.php`, views `holidays/index`, `dashboard/partials/calendar`, `resources/scss/app.scss`, `tests/Feature/HolidayCalendarTest.php` (+2 tests), `CLAUDE.md`, `Product Details/PROJECT_DOCUMENT.md`, rebuilt Word file.
- **Verified:** `npm run build`; full suite 303 passed.

## 25-11-2026: Product Details documents brought up to V14; Word file rebuilt
- **What:** the technical chapters were still at 19-11-2026 for the V14 modules. Updated from the code and the live MySQL schema (read only):
  - `Database_Architecture_and_Schema.md`: totals (50 tables = 42 + 8, 78 FKs, 15 CHECKs, 43 migrations), module rows, inventory 28a–28h, relationships, DB rules, business logic, and generated column blocks for `coverage_settings`, `holidays`, `notices`, `notice_reads`, `notice_types`, `daily_display_settings`, `daily_school_list_days`, `daily_school_lists` (+ `inspection_schedules` order columns).
  - `UI_Pages_and_Application_Modules.md`: Today's Schools (+ Assign, Settings), Holiday Calendar (date popup), Notices & Orders (Inbox / Sent, New notice, notice page), Network Issues, Notice Types, Clean Test Data; endpoints; dashboard strip, live counters, Masters / Admin Settings; module summary. The removed inspections Excel export row is marked removed.
  - `PROJECT_DOCUMENT.md`: §5 table count, ER lines and a V14 tables table; §6 module → tables rows; file map (commands, seeders, JS / views).
  - `Project_File_Structure_and_Technical_Architecture.md`: file inventory 45–53, routes, console commands, frontend modules.
  - `System_Architecture_and_Workflow.md`: module map + §8.1 V14 workflows. `README.md`: dates and table count.
- **Files:** `Product Details/*.md` (above), `School_Inspection_Product_Details.docx` (rebuilt, 3.76 MB).
- **Verified:** `node tools/docs/build-product-docx.cjs` wrote the file; every new table / page name found in the documents.

## 25-11-2026: Same school by Cluster / Block / District; officers see own + lower-level inspections only
- **What:** officers of different levels may inspect the same school (even the same day); only the same level blocks (open draft / submitted this month), in `InspectionStartGuard`, the Find School badges and `ScheduleService::blockedSchools()`. Visibility: own + lower levels (District → Block, Cluster; Block → Cluster; Cluster → own; State / State Admin → all), applied in `JurisdictionService::inspections()` (lists, PDFs, report / photos policy, rating and network reports, dashboard), `SchoolController` (history, counts, filters), `School::latestRatedInspection`, `Inspection::previousRating()`, `InspectionPrefill::source()`, teacher names, `MapDataService`, `CoverageReportService`, `DailySchoolListService` priorities.
- **Data:** `inspections.officer_level` (migration `2026_11_25_000010`, backfilled from `users.level`; set at start and by a creating hook). New `App\Support\InspectionVisibility`.
- **Tests:** new `InspectionVisibilityTest` (4); `UserTypeAccessTest` (a second CRCC of the cluster no longer sees the first one's inspection), `TodaySchoolsTest` and `SchoolRatingDisplayTest` adjusted to the rule.
- **MySQL:** migrated; officer_level filled on every row (4 cluster, 1 state_admin).
- **Verified:** full suite 307 passed.

## 25-11-2026: Map View, "Map view" button at the top of the page
- **What:** a primary **Map view** button in the page's top bar (all screen sizes) scrolls to the map card, closes the phone filters panel, redraws the map and focuses it; `/map#mapCard` opens straight at the map. Works as a plain anchor without JS.
- **Files:** `resources/views/map/index.blade.php`, `resources/js/map.js`, `resources/scss/app.scss` (`.map-card` scroll margin), `tests/Feature/MapViewTest.php`, `CLAUDE.md`, `Product Details/UI_Pages_and_Application_Modules.md`, rebuilt Word file.
- **Verified:** `npm run build`; full suite 307 passed.

## 25-11-2026: Dashboard, Upcoming holidays hidden; Map view button
- **What:** the dashboard calendar card no longer lists the upcoming holidays (the month grid with dots and the date popup stay; `DashboardController` no longer queries `upcoming()`). A primary **Map view** button in the dashboard top bar (`view_reports`) opens `/map#mapCard`, landing on the map; the banner's Map pill is removed (no duplicate link).
- **Files:** `resources/views/dashboard.blade.php`, `resources/views/dashboard/partials/calendar.blade.php`, `app/Http/Controllers/DashboardController.php`, `tests/Feature/HolidayCalendarTest.php` (+1 test), `CLAUDE.md`, `Product Details/{PROJECT_DOCUMENT,UI_Pages_and_Application_Modules}.md`, rebuilt Word file.
- **Verified:** full suite 308 passed.

## 25-11-2026: Latest Notice shows title and date only; Map View page button removed
- **What:** the dashboard Latest Notice strip now shows only the notice title and its date (no type chip, NEW pill, ref no or sender; the date shows on phones too). The **Map view** button at the top of `/map` is removed; `#mapCard` stays so the dashboard's Map view button (`/map#mapCard`) still lands on the map.
- **Files:** `resources/views/dashboard/partials/ticker.blade.php`, `resources/views/map/index.blade.php`, `resources/js/map.js`, `resources/scss/app.scss`, `tests/Feature/{DashboardTickerTest,MapViewTest}.php`, `CLAUDE.md`, `Product Details/{PROJECT_DOCUMENT,UI_Pages_and_Application_Modules}.md`, rebuilt Word file.
- **Verified:** `npm run build`; full suite 308 passed.

## 25-11-2026: Own name hidden on own inspections; Latest Notification (pinned notice)
- **What:** (1) an officer's own inspections show **You** instead of their own name (report page Inspected by / Inspecting officer, wizard step 1, school page history); higher officers still see the name; PDF unchanged. (2) Notices & Orders: a sender can pin a notice as their **Latest Notification** (compose switch or notice page button; one per sender, replaces the previous pin; always on the homepage; withdrawn / expired refused; audited `notice_pinned` / `notice_unpinned`). The Dashboard top strip shows the newest pinned notice, whatever its date, else the newest ticked one. Pinned rows get a **Latest** badge.
- **Files:** migration `2026_11_25_000011_add_pinned_at_to_notices`, `app/Models/Notice.php`, `app/Services/NoticeService.php`, `app/Http/Controllers/{NoticeController,DashboardController}.php`, `routes/web.php`, `resources/views/notices/{create,show,index}.blade.php`, `resources/views/inspections/{show,step}.blade.php`, `resources/views/schools/show.blade.php`, `tests/Feature/{DashboardTickerTest,OwnInspectionNameTest}.php`, `CLAUDE.md`, `Product Details/{PROJECT_DOCUMENT,Database_Architecture_and_Schema,UI_Pages_and_Application_Modules}.md`, rebuilt Word file.
- **Verified:** `npm run build`; full suite 311 passed; MySQL migrated.

## 25-11-2026: Latest Notice strip static and aligned; larger PDF button icons
- **What:** the dashboard Latest Notice strip no longer scrolls: one panel with the title (one line, "…" when long, full title as tooltip) and a date pill on the same centre line (marquee track, copies and separators removed). Every PDF button's icon is slightly larger (1.2em) and the red soft PDF buttons have a thin clean outline (CSS only).
- **Files:** `resources/views/dashboard/partials/ticker.blade.php`, `resources/scss/app.scss`, `tests/Feature/DashboardTickerTest.php`, `CLAUDE.md`, `Product Details/{PROJECT_DOCUMENT,UI_Pages_and_Application_Modules,Project_File_Structure_and_Technical_Architecture}.md`, rebuilt Word file.
- **Verified:** `npm run build`; full suite passed.

## 25-11-2026: One user per browser; database optimisation; faster HTML / CSS loading
- **What:**
  - **One user per browser:**
    - `/login` while signed in shows an "Already signed in" card: Continue as … / Sign out and sign in as another user (`login.switch`, audited `browser_user_switched`).
    - A second sign-in over the first is refused.
    - Every signed-in page carries the user's token (`App\Support\PageUser`, `partials/page-user`). `session-guard.js` sends it with every save and shows the other tabs "Another user has signed in on this browser" when another user signs in or out.
    - `EnsurePageUser` refuses saves from a page of another user (409 JSON / redirect). The wizard and the offline queue keep the answers.
  - **Database:**
    - EXPLAIN review of the main pages on MySQL with the 44,197-school master.
    - Indexes `schools_status_deleted_name_idx` / `schools_status_deleted_area_idx`.
    - One grouped visit join in `SchoolController::listQuery()` (state-wide My Schools 940 → 550 ms, suggestions 88 → 19 ms).
    - `RequestMemo` for the current posting and the coverage setting (dashboard 32–51 → 25–40 queries).
    - `db:maintain` (monthly ANALYZE / OPTIMIZE) and the slow query log (`SLOW_QUERY_MS`).
  - **Loading:**
    - `CompactHtml` middleware (pages 14–22 % smaller).
    - Bootstrap SCSS part by part, no dark mode.
    - `bootstrap-lite.js` (JS 82 → 70 KB).
    - Generated icon subset (`tools/build-icons.cjs`, 398 of 2,078 icons; CSS 534 → 447 KB, gzip 88 → 73 KB).
    - woff2 preload, `decoding="async"` images.
    - `.htaccess` gzip + a year's immutable cache for `/build/assets` (Cache-Control checked on local Apache; mod_deflate is not loaded in XAMPP, see the `.env.example` checklist).
- **Files:**
  - `app/Support/{PageUser,RequestMemo}.php`
  - `app/Http/Middleware/{EnsurePageUser,CompactHtml}.php`
  - `bootstrap/app.php`, `routes/{web,console}.php`
  - `app/Http/Controllers/{Auth/LoginController,SchoolController}.php`
  - `app/Services/{JurisdictionService,HousekeepingService}.php`
  - `app/Models/{CoverageSetting,UserJurisdiction}.php`
  - `app/Providers/AppServiceProvider.php`, `config/inspection.php`
  - migration `2026_11_25_000012_add_v14_performance_indexes`
  - `resources/views/{auth/login,partials/page-user,profile,components/layouts/{app,portal},components/user-avatar}.blade.php`
  - `resources/js/{session-guard,bootstrap-lite,app,holiday-day,review,wizard,offline-queue}.js`
  - `resources/scss/{app,_icons}.scss`, `tools/build-icons.cjs`, `package.json`
  - `public/.htaccess`, `.env.example`
  - tests `OneUserPerBrowserTest`, `DatabaseOptimisationTest`, `IconSubsetTest`
  - `CLAUDE.md`
  - `Product Details/{DB_INDEXING,PROJECT_DOCUMENT,User_Roles_and_Permissions,Project_File_Structure_and_Technical_Architecture}.md`
  - rebuilt Word file
- **Verified:**
  - `npm run build`
  - full suite 320 passed
  - MySQL migrated; `db:maintain` ran on MySQL (22 tables analysed, 0.4 s)

## 25-11-2026: Fix, icons blank after the loading optimisation
- **What:**
  - **Cause:** every Bootstrap icon showed blank after 2709e6e. The icon subset generator wrote the literal `content: "${hex(n)}"` instead of the glyph code, because of an escaped `$` in a template literal. The HTML compaction was not involved.
  - **Fix:** `tools/build-icons.cjs` now writes `content: "\f425"`, and the subset was regenerated (396 icons).
  - **Tests:** `IconSubsetTest` now checks each used icon's real glyph code, checks that no `${` placeholder is left, and checks that the compacted dashboard keeps its `bi` icon classes.
- **Files:** `tools/build-icons.cjs`, `resources/scss/_icons.scss`, `tests/Feature/IconSubsetTest.php`.
- **Verified:**
  - `npm run build`: the built CSS has the same icon bytes as before the optimisation.
  - Full suite: 321 passed.

## 26-11-2026: Performance gaps, module A (quick fixes)
- **What:**
  - **Photo thumbnails:**
    - `EvidenceStorage::makeThumb()` writes a 360 px JPEG next to each photo (`abc.jpg` -> `abc-t.jpg`, `thumbPathFor()`; no new column).
    - `evidence.show?size=thumb` serves it, falling back to the original when it is missing. Evidence is cached privately for a day.
    - `thumb_url` in `toClientArray()`; the wizard grid (`camera.js`) and the review / report tiles use it. The viewer and downloads keep the full photo.
    - Deleting a photo, Clean Test Data and `users:reset-demo` remove the thumbnail too.
    - `php artisan evidence:thumbs [--force]` backfills; it ran locally and made 12.
  - **N+1 guard:** outside production `Model::preventLazyLoading()`, logged by default (`LAZY_LOADING_CHECK=log|throw|off`), throw in phpunit.xml. Fixed what it found:
    - Map View officer list loaded no `code` / state, so labels lacked the code.
    - Approvals list and schedule officer lists: jurisdiction state.
    - Notice inbox: creator designation.
    - `DemoUserSeeder`.
    - Three tests.
  - **Dashboard figures:** `App\Services\DashboardStats`, cached 60 s per officer, posting and day. The version key is bumped when an inspection is created / deleted or changes status / date, when a school is added / closed / moved, and after a School Master import. Draft autosaves do not bump it.
  - **Fewer queries:**
    - `MapDataService::chain()` is memoised per request: map schools JSON 27 -> 21 queries.
    - `DailyDisplaySetting::forDistrict()` is memoised (a clone per caller): Cluster dashboard 23 -> 19 queries.
  - **`cache:status`:** adds routes / events / views cached, GD, queue connection, waiting and failed jobs, and a warning when jobs wait over 5 minutes.
  - **Queue:** `DB_QUEUE_RETRY_AFTER` default 90 -> 3700 (long jobs are not run twice).
  - **`QueryBudgetTest`:** query budgets for the dashboard (3 levels), Find School, My Schools, both inspection lists, the report page, map schools JSON and coverage.
- **Files:**
  - `app/Services/{EvidenceStorage,DashboardStats,MapDataService,MasterImportService,ApproverResolver,ScheduleService,TestDataCleaner,DemoUserReset}.php`
  - `app/Http/Controllers/{EvidenceController,DashboardController,NoticeController}.php`
  - `app/Models/{InspectionEvidence,Inspection,School,DailyDisplaySetting}.php`
  - `app/Providers/AppServiceProvider.php`, `app/Console/Commands/CacheStatus.php`
  - `config/{inspection,queue}.php`, `routes/console.php`, `phpunit.xml`
  - `resources/js/camera.js`, `resources/views/inspections/partials/step-answers.blade.php`
  - `database/seeders/DemoUserSeeder.php`
  - tests `PerformanceQuickFixesTest`, `QueryBudgetTest`, `TlmItemsTest`, `TodaySchoolsTest`, `InspectionScheduleTest`
- **Verified:**
  - full suite 326 passed, with lazy loading set to throw
  - `npm run build`
  - `php artisan cache:status`

## 26-11-2026: Performance gaps, module B (background downloads)
- **What:**
  - **Background downloads:** the heavy Excel / PDF downloads (`App\Support\QueuedExports::ROUTES`: School Master Excel / PDF, Location Master, My Schools, Coverage, Rating, Inspections PDF, Network Issues PDF) are made by the queue worker instead of inside the request.
  - **Queuing (`QueueLargeExports`, web middleware):**
    - It runs the route's own middleware first (auth, active, gates, the Rating Report's State Admin closure, throttle), so a refused request is answered as before and never queued.
    - It saves an `ExportJob` (new table `export_jobs`) and redirects back.
    - At most 3 at once per officer.
  - **Making the file (`App\Jobs\GenerateExport`):** replays the same GET as the same user through the same middleware and controller, and stores the returned file (`private:exports/{user}/`).
    - No export logic was copied or changed: filters, jurisdiction scoping, row caps and audit entries (with the click's IP and browser) are the controllers' own.
    - A controller warning (PDF row cap) becomes a failed job with its message.
  - **Downloads page** (`/downloads`, sidebar):
    - Owner only (others 404), with status JSON, file and delete.
    - `resources/js/downloads.js` shows a "Preparing…" box, starts the download when ready, and warns after 30 s when no worker seems to run.
  - `exports:purge` runs hourly (keep 24 h; unfinished after 3 h becomes failed).
  - `EXPORT_QUEUE` (unset = on unless the queue is `sync`), `EXPORT_KEEP_HOURS`, `EXPORT_MAX_ACTIVE`.
  - Worker command and restart notes are in `.env.example` and CLAUDE.md Commands.
- **Files:**
  - `app/Support/QueuedExports.php`, `app/Jobs/GenerateExport.php`, `app/Http/Middleware/QueueLargeExports.php`
  - `app/Http/Controllers/DownloadController.php`, `app/Models/ExportJob.php`
  - migration `2026_11_26_000001_create_export_jobs_table`
  - `resources/views/downloads/index.blade.php`, `resources/js/{downloads,app}.js`, `resources/scss/app.scss`, `resources/views/components/layouts/app.blade.php`
  - `bootstrap/app.php`, `routes/{web,console}.php`, `config/inspection.php`
  - `app/Http/Controllers/Auth/LoginController.php` (download URLs are never an intended URL)
  - `.env.example`, `CLAUDE.md`
  - tests `QueuedExportTest`
- **Verified:**
  - full suite 333 passed
  - MySQL migrated
  - with a real `queue:work` on MySQL:
    - whole-state School Master Excel done in 2 min 5 s (4.5 MB, audit with the admin, IP and browser)
    - District officer coverage PDF 1 s, My Schools PDF 2 min 15 s
    - School Master PDF refused for that officer ("You are not allowed to download this.")
  - check jobs and files removed afterwards

## 26-11-2026: Performance gaps, module C (background imports)
- **What:**
  - **Background check and save:** the Excel Upload check and save run on the queue (`App\Jobs\ImportMasterFile`, modes `check` / `import`). A state-wide School Master no longer holds a web request open for minutes, twice.
  - **Batch statuses:** `import_batches` gets `status` (checking, checked, check_failed, queued, importing, done, failed; old rows = done), `pending_path`, `preview`, `error`, `district_all`, `started_at`, `finished_at`.
    - The session keeps only the batch id, and only its uploader can confirm it.
    - History lists finished batches only.
    - The page shows a progress card that polls `imports.status` and reloads when the status changes. The result or error is shown once.
  - **Sync queue:** the answers are unchanged (preview flash, a template error back on the form as `file`).
  - **`MasterImportService::import()`:** accepts the existing batch and passes the user to `master_upload` / `location_renamed` (a worker has no signed-in user).
  - **`longRunning()`:** moved into the job (MariaDB too).
  - **`exports:purge`:** removes uploads checked but never confirmed after 24 h, and fails checks / saves not finished in 3 h.
  - **Two tests updated:** a checked batch now exists before Confirm, and the page no longer says "keep this page open".
- **Files:**
  - `app/Jobs/ImportMasterFile.php`, `app/Models/ImportBatch.php`
  - `app/Http/Controllers/Admin/ImportController.php`, `app/Services/MasterImportService.php`
  - migration `2026_11_26_000002_add_status_to_import_batches`
  - `resources/views/admin/imports/index.blade.php`, `resources/js/imports.js`, `routes/{web,console}.php`, `CLAUDE.md`
  - tests `QueuedImportTest`, `DistrictSchoolImportTest`, `ModificationV13Test`
- **Verified:**
  - full suite 337 passed
  - MySQL migrated
  - real `queue:work` check of `Documents/SchoolMaster_UDISE 202526.xlsx`: 44,197 rows (0 new, 44,197 update, 0 errors) in 1 min 50 s
  - the check batch was removed afterwards; nothing was saved

## 26-11-2026: Performance gaps, module D (map and search)
- **What:**
  - **Map:**
    - `map.js` loads the state outline first and the district polygons only when the state-wide view is drawn, so officers below the State never fetch the file.
    - `map:boundaries` writes `assam-districts.geojson` as one dissolved MultiPolygon per district (4 decimals, tolerance 0.0015) instead of one feature per constituency. The state outline is also 4 decimals.
    - Rebuilt: districts 186 → 114 KB (gzip 55 → 33 KB), state 55 → 49 KB (gzip 19 → 15 KB). The per-district outline files are unchanged.
  - **Search:** `App\Support\SchoolSearch` is the one school search.
    - **Escaping (bug fix):** search text is escaped, so `%` and `_` used to match anything.
    - **Modes:** `split` / `either`, as before.
    - **FULLTEXT** index `schools_name_ft` (MySQL / MariaDB) for the live searches (suggestions, Map View search, planner): word starts first, the exact LIKE when that finds nothing.
    - Full lists keep the exact LIKE.
  - **Test change:** the `map:boundaries` test now expects one feature per district.
- **Files:**
  - `app/Support/SchoolSearch.php`
  - migration `2026_11_26_000003_add_school_name_fulltext_index`
  - `app/Http/Controllers/{SchoolController,ReportController,Admin/SchoolMasterController}.php`
  - `app/Services/{MapDataService,ScheduleService}.php`
  - `app/Console/Commands/ImportMapBoundaries.php`, `resources/js/map.js`, `public/geo/assam-{state,districts}.geojson`
  - `Product Details/DB_INDEXING.md` (section 10), `CLAUDE.md`
  - tests `SchoolSearchTest`, `MapViewTest`
- **Verified:**
  - full suite 341 passed
  - MySQL migrated (index built in 2 s)
  - "kokra" LIKE 797 ms cold → MATCH 6.6 ms
  - "govt lps" same 81 rows both ways
  - "okrajh" (part of a word) still found through the fallback
  - planner "batabari" / "ataba" both find WEST BATABARI LPS

## 26-11-2026: Performance gaps, module E (documents)
- **What:**
  - **`System_Architecture_and_Workflow.md`:**
    - new 8.2 Background work, with a sequence diagram
    - the deployment table now says a queue worker is required
  - **`Database_Architecture_and_Schema.md`:** `export_jobs`, and the new `import_batches` columns.
  - **`PROJECT_DOCUMENT.md`:** a "Performance work" summary.
  - **`UI_Pages_and_Application_Modules.md`:** the Downloads page and the Upload progress card.
  - **`DB_INDEXING.md`:** section 10 (written in module D).
  - Rebuilt the Word file.
- **Files:**
  - `Product Details/{System_Architecture_and_Workflow,Database_Architecture_and_Schema,PROJECT_DOCUMENT,UI_Pages_and_Application_Modules}.md`
  - `Product Details/School_Inspection_Product_Details.docx` (3.77 MB)
- **Verified:** `node tools/docs/build-product-docx.cjs` wrote the Word file.

## 2026-11-26: Today's Schools: carried-over pending schools no longer fill the daily limit
- **Problem:** a CRCC with 2 pending schools from earlier days saw only those 2 and no new school. `DailySchoolListService::generate()` took the room for new picks as `daily_limit - (assigned + carried)`, and the default limit is 2.
- **Fix:**
  - Only the assigned (`source = manual`) rows use the daily limit. Carried schools are extra (2 pending + limit 2 = 4), and they are still left out of the automatic pick, so no school appears twice.
  - Repair: `DailySchoolListService::reopenCarriedOnlyDays($day)` drops the "generated" marker of a day that holds carried rows but no `auto` row. Migration `2026_11_26_000004_regenerate_todays_school_lists` runs it for today, and the next view adds the new schools (the rows already there stay).
  - Text: the list header reads "Daily limit N + pending from earlier", and the setting help says "on top of the daily limit".
- **Files:** `app/Services/DailySchoolListService.php`, `database/migrations/2026_11_26_000004_regenerate_todays_school_lists.php`, `resources/views/today/{index,settings}.blade.php`, `tests/Feature/TodaySchoolsTest.php`
- **Verified:**
  - `TodaySchoolsTest`: carry-forward now expects 1 carried + 2 new; new tests for 2 pending + 2 new and for repairing a day made the old way.
  - Full suite: 343 passed.
  - Migrated on MySQL: today's lists now hold 6 auto and 5 carried rows.

## 2026-11-26: Today's Schools: a settings change updates today's list the same day
- **Problem:** the list was made once a day. Changing Today's Schools Settings (daily limit, priorities, random, recent days, distance, pending list) only showed the next day.
- **Fix:**
  - `daily_school_list_days.setting_stamp` (migration `2026_11_26_000005`) keeps `DailyDisplaySetting::listStamp()`, a sha1 of the values that decide the schools. Enabled, levels and weekly off are left out because they are checked on every view.
  - `DailySchoolListService::generate()`: when the stamp differs on the next view, `refresh()` brings today's list in line. A null stamp (lists made before) is only stamped.
  - **`refresh()` keeps** assigned, visited, skipped and open-draft rows, plus carried rows while the pending list is on.
  - **Pending list switched off:** today's untouched carried rows are removed and their earlier row goes back to `pending`. Switching it on carries them again.
  - **Automatic schools** are picked again for the new limit. `pick(..., $prefer)` gives today's ones a +1 tie bonus (first in random mode), so a higher limit only adds and a lower one keeps the best. Audited `daily_list_refreshed` (added, removed).
  - The carry step moved to `carry()`, shared by generate and refresh.
  - `pick()`'s "busy" check no longer counts the officer's own rows (they are passed in `$exclude`).
  - Text: Today's Schools and the dashboard card note "List updated after a settings change"; the settings save message says today's lists change too.
- **Files:** `app/Services/DailySchoolListService.php`, `app/Models/DailyDisplaySetting.php`, `app/Http/Controllers/TodaySchoolsController.php`, `database/migrations/2026_11_26_000005_add_setting_stamp_to_daily_school_list_days.php`, `resources/views/today/index.blade.php`, `resources/views/dashboard.blade.php`, `tests/Feature/TodaySchoolsTest.php`
- **Verified:**
  - 3 new tests:
    - limit 2 to 4 keeps the morning schools and adds 2
    - limit 1 never removes a visited or draft school
    - pending list off / on the same day
    - unchanged settings make no refresh
    - a null stamp is kept
    - only the officer's own district override counts
  - Full suite: 346 passed.
  - Migrated on MySQL.

## 26-11-2026 · Inspection Coverage Report and Coverage Settings explained in plain words
- **Request:** "Inspection Coverage Report and setting explain. More easy to user understanding report."
- **Report page:**
  - New **What this report shows** card with the view's own figures in sentences (`CoverageReportService::explain()`), for example "1 of 3 schools (33.3 %) in … were visited at least once in November 2026 … 2 schools were not visited." It covers both Visit and Inspection coverage, plus the colour bands read from config (`bandsText()`; they were hardcoded before). The card folds away and the choice is remembered (`si-cov-help`).
  - Plainer tile and heading names ("Schools in this area", "Schools visited", "Total visits", "Inspected this cycle", "not counted", "Not inspected yet"), each with a tooltip giving its meaning.
  - The cluster school list has a note on the order and on visits not counted.
  - "How coverage is counted" became **Questions about this report** (Q&A with examples). It moved inside the results, so it follows the filters.
- **Settings page:**
  - Help under each field.
  - A live **With these settings** sentence with today's actual dates (`CoverageReportController::preview()`).
  - A "Period and cycle: what is the difference?" card with an example, and a note on district settings.
  - The success message is a plain sentence.
- **Downloads:** the Summary (Excel sheet and PDF) has a third column, **What it means** (`SUMMARY_HEADS`), with the same sentences. "Unverified" is now "Not counted" in the headings.
- **Tooltips:** `app.js` also creates tooltips on hover / focus, so sections swapped in by the filters keep them.
- **No calculation changed.**
- **Files:** `app/Services/CoverageReportService.php`, `app/Http/Controllers/CoverageReportController.php`, `resources/views/reports/coverage/{index,settings}.blade.php`, `resources/scss/app.scss`, `resources/js/app.js`, `tests/Feature/CoverageReportTest.php`, `CLAUDE.md`, `Product Details/PROJECT_DOCUMENT.md`, rebuilt Word file.
- **Verified:**
  - New test `test_report_and_settings_explain_the_figures_in_plain_words`: sentences, bands line, Summary column, PDF headings, preview dates, success message.
  - Full suite: 347 passed. `npm run build` OK.

## 26-11-2026 · Fix: Coverage Report Quarterly view showed 0 % Inspection coverage
- **Request:** "Inspection Coverage Report Quaterly not working. Find the error and fixed it."
- **Cause:** `CoverageReportService::cycle()` used the inspection cycle that contains the **period end**. The running quarter ends on 31-12-2026. With a monthly cycle (the KOKRAJHAR district setting) that is December, a month that has not started, so the report counted no submitted inspection.
  - Reproduced on MySQL as District officer 31: Monthly gave 2 inspected (Nov cycle), Quarterly gave 0 (Dec cycle).
- **Fix:** the cycle is anchored on the period end, or on today while the period is still running. Finished periods are unchanged. The page, the plain-words sentences, the Summary, the Excel "Due in cycle" and the PDF all follow.
- **Not a bug:** Visit coverage is 0 in every period on the local database because all 5 test inspections were made far from their schools (listed as "not counted").
- **Files:** `app/Services/CoverageReportService.php`, `tests/Feature/CoverageReportTest.php`, `CLAUDE.md`, `Product Details/PROJECT_DOCUMENT.md`, rebuilt Word file.
- **Verified:**
  - New test `test_a_running_period_uses_the_current_cycle_not_a_future_one`: running quarter → Nov cycle with 1 inspected; past quarter → Sep; state quarterly cycle → Oct–Dec.
  - MySQL, officer 31, Quarterly: cycle 01–30 Nov, 2 inspected.

## 26-11-2026 · Inspection Rules setting (State Admin)
- **Request:** a setting for the inspection start rules.
  - Block a school after a same-level submission for this calendar month, this week, this day, or none.
  - An option allowing officers of different levels to inspect the same school, even on the same day.
- **User decisions:**
  - Week = Monday to Sunday.
  - None still blocks while a same-level draft is open.
  - Different levels switched off = blocked on the same day only.
- **Done:**
  - **Table and model:** migration `2026_11_26_000006_create_inspection_start_settings`, one row (`repeat_window` month | week | day | none, `cross_level`). Model `InspectionStartSetting` (`current()` memoized, `window()`, `reopensOn()`, `windowLabel()`).
  - **`InspectionStartGuard`:** the window block (`OTHER_MONTH`) follows the setting (`otherInWindow()`, `reopensOn()`). New rule 5 `OTHER_LEVEL_TODAY` / `otherLevelToday()` applies when different levels are not allowed on the same day.
  - **`ScheduleService::blockedSchools()`** (planner and Today's Schools) uses the same window for the plan date, plus the different-level check on that day.
  - **Badges and start box:** Find School shows "Inspected this month / this week / today" and "Inspected today by another level". The school page start box shows the matching text and the reopen date.
  - **Page:** Admin Settings → **Inspection Rules** (`admin.inspection-rules`, `Admin\InspectionRuleController`, State Admin only). It has radio cards, the switch, help text, examples with today's dates and a live "With these settings" summary. Saves are audited `inspection_rules_updated` (old → new).
  - **Defaults** keep the earlier behaviour (calendar month, different levels allowed).
- **Files:** the migration, `app/Models/InspectionStartSetting.php`, `app/Services/{InspectionStartGuard,ScheduleService}.php`, `app/Http/Controllers/{SchoolController,Admin/InspectionRuleController}.php`, `routes/web.php`, `resources/views/admin/inspection-rules.blade.php`, `resources/views/schools/{show,_results}.blade.php`, `resources/views/components/layouts/app.blade.php`, `tests/Feature/InspectionRuleSettingsTest.php`, `CLAUDE.md`, `Product Details/PROJECT_DOCUMENT.md`, rebuilt Word file.
- **Verified:**
  - 5 new tests: default month; week, day and none (draft still blocks); different level on the same day only; planner window; State Admin only, with audit.
  - Full suite: 353 passed.
  - Migrated on MySQL.

## 26-11-2026: PDF buttons download the file (no browser view)
- **Asked:** "When click on PDF download button automatically download. Not view."
- **Cause:** the inspection report PDF (`inspections.pdf`) was sent `inline` and its buttons opened a new tab (`target="_blank"`), so the browser showed it. All list / report PDFs (`MasterPdf`) and the blank form already downloaded.
- **Done:** `ReportController::pdf()` sends `attachment; filename="<inspection no>.pdf"` + `Cache-Control: private, no-store`. The five report PDF links (inspection list icon, report page ×3, submitted page) lose `target="_blank"` and get `download data-no-loader`.
- **Files:** `app/Http/Controllers/ReportController.php`, `resources/views/inspections/{index,show,submitted}.blade.php`, `tests/Feature/InspectionFlowTest.php`, `CLAUDE.md`.
- **Verified:** the test asserts the attachment header; full suite 353 passed.

## 26-11-2026: Inspection form entry validation (screen, server, database) with the fix in every message
- **Asked:** "Inspection Form Entry All use case entry database validation and UI validation if not added and display the resolution". The user chose two new rules: an absent teacher's type must be within its step 2 count, and an absent teacher cannot be the observed teacher. Result shown as messages plus a document.
- **Found by an audit of all entry points:**
  - Letters in a number box were read as blank, so the answer was cleared without a message.
  - The server turned "1.9" into 1 and cut long text.
  - Hidden TSI_14 / 15 could be saved and get around the teacher-totals rule.
  - An array value gave a 500 error.
  - `show_if` answers were kept after their parent changed.
  - The TLM and Participation errors read "Please choose yes." / "Please choose satisfactory."
  - Remarks and suggestions had only a toast. Suggestions used Laravel's default text.
  - A negative amount got the format message.
  - Rejected offline changes were dropped, and `review.js` dropped them with no message at all.
  - A Retry button was shown after a 422.
  - The gallery used `window.prompt`.
  - The document limit was checked on the server only.
  - The database had no CHECK constraints.
- **Done:** server rules (`InspectionController` `saveAnswer` / `cleanValue` / `teacherData` / `classroomData` / suggestions / `teacherNames?present=1`, `EvidenceController`, `InspectionValidator::absentTypeProblems()` + the observed-absent check at submit, `InspectionAbsentTeacher::TYPE_COUNT_CODES` / `typeCounts()` / `sameName()`); screen rules and inline messages (`wizard.js`, `camera.js`, `review.js`, `offline-queue.js`, step views); 12 MySQL CHECK constraints (migration `2026_11_26_000007`, run on MySQL: all added, no existing row broke them); document `Product Details/INSPECTION_VALIDATION.md` (added to the Word file).
- **Tests:**
  - New: `tests/Feature/InspectionValidationTest.php` (6 tests).
  - Changed: `ModificationV10Test`. The hidden TSI_14 / 15 are now written as stored old data, and posting them is checked to fail with 422. Step 2 gets a contractual teacher before a contractual absent row.
- **Verified:** full suite 359 passed; `npm run build` OK.

## 26-11-2026: Inspection PDF report: all data checked, two time bugs fixed
- **Asked:** "Inspection PDF report check all data display correctly".
- **Checked:** every MySQL inspection rendered and compared with the stored rows (answers, remarks, step 2 boxes, absent teachers, classrooms with TLM, funds with the year-wise summary, suggestions, photos). All were printed correctly except the times.
- **Bug 1, wrong start time:**
  - "Inspection started" came after "Submitted" (inspections 1–3: started 25 Nov 18:23, submitted 23 Nov), or was equal to it (inspection 5).
  - Cause: MariaDB had given `inspections.started_at` `ON UPDATE CURRENT_TIMESTAMP`, because `explicit_defaults_for_timestamp` is 0. Every save set it to "now".
  - Fix: migration `2026_11_26_000008_fix_inspection_timestamps` removes it from `started_at`, `inspection_ratings.calculated_at` and `otp_verifications.expires_at`. It repairs `started_at` from `created_at` (the earlier of the two is kept).
- **Bug 2, photo times 5 h 30 min early:**
  - The phone sends `taken_at` in UTC, and it was stored as India time (e.g. 08:05 for a photo received at 13:36).
  - Fix: `EvidenceController` converts it to the app time zone. The same migration converts the stored rows.
- **Also:** the PDF prints only uploaded photos (no empty frame for a failed upload), and a photo linked to a question shows the question's label, not its code.
- **Files:** the migration, `app/Http/Controllers/EvidenceController.php`, `resources/views/inspections/pdf.blade.php`, `tests/Feature/InspectionPdfContentTest.php` (new), `CLAUDE.md`.
- **Verified:**
  - Migrated on MySQL: started times are now 14:45 / 15:42 / 11:20 / 15:10 / 15:28 (before submit), photos 13:35, and no `on update` column is left.
  - New test checks every entered value and both times (220 assertions).
  - Full suite: 360 passed.

## 26-11-2026: Map View: map and filters only, a full-height map with Full screen, selected filters as chips
- **Asked:** "Map View page showing only Map View on Large option with Filter No report. And what are selected that are display". The user chose: map + filters only (remove the count tiles and the table, keep the colour key bar), and the selected filters as a chips bar above the map.
- **Done:**
  - `resources/views/map/index.blade.php`: tiles and table removed; the map card got Full screen and Filters (phones) buttons and the chips bar.
  - `resources/js/map.js`: `renderTable()` and the row handler removed, the tile counts dropped from `renderStats()`. New `renderChips()` (area path from the breadcrumb, school, officer, period, rating colour; × per chip and Clear all) and Full screen (Fullscreen API on `#mapLayout`, fixed-overlay fallback, Esc, `invalidateSize()`).
  - `resources/scss/app.scss`: `.map-card-full .si-map` is as tall as the screen; full-screen styles; `.map-chips` / `.map-chip` (area blue, school / officer green, period grey, rating orange; larger × on phones); `.map-stats` removed.
- **Tests:** `MapViewTest` checks the chips bar, Full screen, `#mapLayout`, and no table or tiles.
- **Verified:** full suite 360 passed; `npm run build` OK (new icons are in the subset).

## 26-11-2026: Map View: the report list is back below the map, hidden in Full screen
- **Asked:** "Map View page Below List of the report not Full View Display". The user chose: show the list below the map, and hide it in Full screen.
- **Done:**
  - The table card came back below the map card (`.map-list`), with `renderTable()` and its call in `load()` (taken from the version before `b2ae5b6`).
  - A row click now scrolls to `#mapCard` and opens the marker's popup.
  - `.map-layout.is-full .map-list { display: none; }`. The count tiles stay removed; the chips, the screen-tall map and Full screen are unchanged.
- **Files:** `resources/views/map/index.blade.php`, `resources/js/map.js`, `resources/scss/app.scss`, `tests/Feature/MapViewTest.php`, `CLAUDE.md`.
- **Verified:** full suite 360 passed; `npm run build` OK.

## 26-11-2026: Sidebar Collapse / Expand button fixed (app.js was bundled twice)
- **Asked:** "Sidebar Collapse not working. JavaScript issue: find and fix it." The user confirmed that the desktop << / >> button is the broken part.
- **Cause:** headless Chrome showed that a click saved `si-sidebar` but left the rail in place. `downloads.js` imported `escapeHtml` from `./app`, and `app.js` imports `./downloads`. Vite therefore put a **second copy of `app.js`** in the bundle, so every `app.js` handler was registered twice. One click collapsed the sidebar and the second handler expanded it again. Bootstrap's collapse (the Users / Masters groups) lives in the shared chunk, so it was not affected.
- **Done:**
  - New `resources/js/util.js` holds `escapeHtml`. `downloads.js` imports it from there, and `app.js` re-exports it and keeps `window.escapeHtml`.
  - New `resources/js/sidebar.js` holds the phone menu, the rail button and the name popups, moved out of `app.js`. Its click and hover handlers are delegated and set up once (`window.__siSidebar`). It is imported first in `app.js`.
  - `app.scss`: the brand title is cut with an ellipsis, and the toggle sits above it (`z-index: 1`).
  - `MapViewTest` checks that the layout renders both sidebar buttons.
- **Verified:**
  - The bundle now has one copy of the handlers (was 2).
  - Headless Chrome at 1366 px, signed in as the State Admin, on the dashboard, Map View and User List: click 1 gives the full menu (232 px, `full`), click 2 gives the rail (72 px, `mini`), with no console errors.
  - At 390 px the ☰ menu opens.
  - `npm run build` OK; full suite 360 passed.

## 26-11-2026: Modification V15, Today's Schools: skip switch, carry of skipped schools, alerts, list lock
- **Asked:** "review the Modification of the ApplicationV15.docx and update accordingly". The document lists 11 items, all about Today's Schools.
- **Decisions (user):**
  - Alerts in-app only (the bell).
  - After the lock, the State Admin allows changes with a reason.
  - Carried schools stay first and extra to the daily limit.
- **Done:**
  - Migration `2026_11_26_000009_v15_today_schools`:
    - Settings: `allow_skip`, `notify_enabled` / `notify_time`, `reminder_enabled` / `reminder_time` (State only).
    - `daily_school_lists.carried_to`.
    - Day marker: `notified_at`, `reminded_at`, `locked_at`, `unlocked_*`.
    - `notifications` table.
  - `DailySchoolListService`:
    - Skip refused when it is off, or for a later day.
    - Skipped rows are carried once, as `carried_skipped`.
    - `prepare()` makes a later working day's list ahead; `rebuildAhead()` remakes it.
    - `markVisited()` clears later rows of the visited school.
    - `lockOnStart()`, `isLocked()`, `unlock()`.
    - `remove()` for any pending row without a draft; new `replace()`.
    - Officers are told of every change.
  - `today:notify` (every 5 minutes) sends the next-day alert and the pending reminder.
  - `RefreshDailyLists` job runs on each settings save.
  - Bell in the top bar:
    - `AlertService`, `AlertController`, Alerts page, `alert-badge.js`.
    - The approvals icon is now `bi-person-check`.
  - Views:
    - Today page: Priority tag, "moved to", made-ahead notice, lock banner, link to tomorrow's list, Skip hidden when off.
    - Assign: Remove / Replace modal; lock banner with **Allow changes** for the State Admin.
    - Settings: a "Skip and alerts" block.
  - `TodaySchoolsTest`: the limit test now checks that a started inspection locks the list, and that the waiting change applies after the unlock.
- **Query budget:** coverage report 11 → 12, because the bell's unread count adds one cached query per page (the test cache lives one request). New budget: alerts page 6 (measured 4).
- **Docs:** CLAUDE.md, `PROJECT_DOCUMENT.md` section 15 and its tables, `Database_Architecture_and_Schema.md`, V15 copied to `Product Details/Requirements/`, Word file rebuilt.
- **Verified:**
  - `npm run build` OK.
  - Full suite green; the new `TodaySchoolsV15Test` has 8 tests.
  - `php artisan migrate` on MySQL OK, with no `on update` timestamp columns.
  - `schedule:list` shows `today:notify`.
  - On the local Apache site as the State Admin, `/today/settings`, `/today/assign`, `/alerts`, `/alerts/count` and the dashboard all return 200.
  - `today:notify` was not run against the local MySQL data, so the demo officers got no alerts.

## 2026-11-26 · Registration form alignment
- Employee photo and Identity proof now share one line: Full name, Gender, Designation and Employee ID are `col-md-6 col-lg-3` (four on a line on desktop), so the two upload boxes (`col-md-6`) get their own row.
- User level pills show only the level name (State / District / Block / Cluster), 1rem text and a larger icon; the small Directorate / DEEO / BEEO / CRCC line was removed.
- Security check: `partials/captcha` takes `inline` (`captcha-box-inline`), so on registration the CAPTCHA image, refresh and the entry box (64 px, same height) sit on one line, wrapping on narrow phones. The login page is unchanged.
- Files: `resources/views/auth/register.blade.php`, `resources/views/partials/captcha.blade.php`, `resources/scss/app.scss`.
- Verified: `npm run build`; registration / captcha / icon tests 34 passed.

## 2026-11-26 · Today's Schools: period for "Skip schools taken by another officer"
- New setting `daily_display_settings.skip_window` (migration `2026_11_26_000010`, default `month`): Same day / This week (Mon–Sun) / This month, radio group under the tick box on Today's Schools Settings (state and district forms). A school being inspected now (another officer's draft) is always skipped.
- `ScheduleService::blockedSchools()` takes an optional period; `DailySchoolListService::pick()` passes `skip_window`. The planner keeps the Inspection Rules window. `InspectionStartSetting::windowFor()` / `labelFor()` share the window maths.
- `skip_window` is in `listStamp()`, so a change re-picks lists the same day. Saving without it keeps the stored value.
- Files: migration, `DailyDisplaySetting`, `InspectionStartSetting`, `ScheduleService`, `DailySchoolListService`, `TodaySchoolsController`, `today/settings.blade.php`, `InspectionRuleSettingsTest`, CLAUDE.md, Product Details docs.
- Verified: 369 tests passed (SQLite); migrated on MySQL.

## 2026-11-26 · Registration page: two-line details, one-line CAPTCHA, level text, password help
- Full name, Gender, Designation and Employee ID: two per line (`col-md-6`).
- Security check: with `partials/captcha` `inline`, the label, CAPTCHA picture, refresh and entry box share one row from tablets up (`.captcha-field-inline`; stacked on phones). Login unchanged.
- User level tiles: the level name uses the base field size (`$font-size-base`, 600), the same in every tile.
- Password: live rule checklist under the box (`#pw-rules`: length, A–Z, a–z, 0–9, symbol, ticks green, feeds the meter); under Confirm, "Passwords match" / "Passwords do not match" (`#pw-match`); a mismatched submit is stopped in `register.js` before the encryption listener.
- Files: `auth/register.blade.php`, `partials/captcha.blade.php`, `resources/js/register.js`, `resources/scss/app.scss`.
- Verified: `npm run build`; registration / login / captcha / icon tests 45 passed.

## 2026-11-26 · Registration CAPTCHA row alignment
- The CAPTCHA picture, refresh button (now a square) and entry box are all 52 px tall and centred on one line (`.captcha-box-inline`); the picture is no longer squeezed by `max-width: calc(100% - 64px)`.
- From tablets up the field is a two-column grid (label | picture + box, no wrap); the label is centred on the row, and the hint and error start under the picture, not under the label. Phones stack as before. Login CAPTCHA unchanged.
- Files: `resources/scss/app.scss`.
- Verified: `npm run build`; registration / captcha / icon tests 34 passed.

## 2026-11-26 · Registration CAPTCHA: the entry box could not be typed in
- Cause: the `flat` CAPTCHA picture is 345 × 65; with `max-width: none`, `nowrap` and `min-width: 0` on the input, the label + picture + refresh filled the `col-lg-8` row and the entry box shrank to a few pixels.
- Fix: picture capped at 220 px (`object-fit: contain`), entry box at least 10rem wide, the row wraps again when narrow, and the CAPTCHA column is full width (`col-12`).
- Files: `resources/scss/app.scss`, `resources/views/auth/register.blade.php`.
- Verified: `npm run build`; registration / captcha / icon tests passed.

## 2026-11-26 · Download links: processing look, no repeat clicks until the file arrives
- A click on a PDF / Excel download link (inspection report PDF, blank form, Coverage / Rating / Network / My Schools / Inspections / School and Location Master downloads) shows a spinner and "Preparing PDF…" / "Preparing Excel…" on every copy of that link (desktop and phone), like submit buttons, and further clicks are ignored.
- How it ends: the link is sent with `_dl=<token>`; new middleware `MarkDownloadDone` (prepended to the web group) strips it from the request and, when the answer is a file, sets the readable cookie `si_dl=<token>` (excluded from cookie encryption). The page sees the cookie and restores the button; queued exports redirect back as before; 90 s safety release and Back (`pageshow`) release.
- The blank form links lost a `data-no-busy` that had no effect on links, so they get the busy look too.
- Files: `resources/js/download-busy.js` (new), `resources/js/app.js`, `resources/scss/_button-busy.scss`, `app/Http/Middleware/MarkDownloadDone.php` (new), `bootstrap/app.php`, `schools/show.blade.php`, `inspections/step.blade.php`, `tests/Feature/DownloadBusyTest.php` (new), CLAUDE.md.
- Verified: `npm run build`; full suite 371 passed (SQLite).

## 27-11-2026 · Registration page: loading splash no longer appears over the visible page
- Cause: `#si-loader` fades in after 1.5 s and was hidden only on `window.load`, which waits for every image. On `/register` the server-made CAPTCHA image (plus logos and the district request) often took longer, so the splash covered the form already on screen. A CAPTCHA slower than 8 s also marked the network "slow" for the next pages.
- Fix: `components/page-loader.blade.php` hides the loader (and measures a slow load) at `DOMContentLoaded`; `load` stays as a fallback. `resources/js/register.js` binds the password, upload and consent handlers first and restores old district / block / cluster without awaiting it. `partials/captcha.blade.php` image `decoding="async"`.
- Files: resources/views/components/page-loader.blade.php, resources/js/register.js, resources/views/partials/captcha.blade.php, tests/Feature/PageLoaderTest.php, CLAUDE.md.
- Verified: `php artisan test --filter="PageLoader|RegistrationAndLogin"` 19 passed; `npm run build` OK.

## 27-11-2026 · Registration: employee photo by selfie
- The photo upload zone has a **Take a selfie** button. It opens a camera window (front camera, mirrored live view, round face guide) with Capture, Retake, Use photo and Cancel. The picture is a 600 × 600 JPEG (quality lowered if it would pass the 1 MB limit), named `selfie-YYYYMMDD-HHmm.jpg`, put into the same `photo` input, so the existing preview, size check, Remove and the server rules (`RegisterRequest`, `RegisterUploadStash`) apply unchanged.
- The camera stops when the window closes or the page is left. Clear messages for denied permission, no camera, camera busy and http. Without the camera API the button opens the phone camera through `capture="user"`.
- Files: resources/js/selfie.js (new), resources/js/register.js, resources/views/auth/register.blade.php, resources/scss/app.scss, tests/Feature/RegistrationAndLoginTest.php, CLAUDE.md.
- Verified: `npm run build`; `php artisan test --filter="RegistrationAndLogin|PageLoader|IconSubset"` 22 passed.

## 27-11-2026 · Photo size standard: every photo under 1 MB at a standard size
- New `resources/js/image-shrink.js`: `fitSize()` (fit inside long × short, aspect kept, never enlarged), `encodeUnder()` (JPEG 0.82 down to 0.5, then 85 % smaller and again until under the limit; white background for PNG), `shrinkPhotoFile()` (a file already within size and limit is kept as it is), `replaceInputFile()`.
- Inspection photos (camera and gallery, `camera.js processImage()`): standard **1600 × 1200** (portrait 1200 × 1600), under `photo_max_kb` (1 MB) with 5 % room, so a photo is no longer refused for size; the stamp strip is redrawn at the smaller size. Config `evidence.photo_short_side_px` (1200); `SI.shortSide` / `SI.photoMaxKb` from `step.blade.php`.
- Employee photo (registration upload / selfie and profile): standard **800 × 600** (`employee_photo.max_side_px` / `short_side_px`), compressed under 1 MB instead of "choose one of 1 MB or less". Identity proof JPG / PNG: fit 2000 × 1500 under 2 MB. The upload zone shows "resized to W × H". Server limits unchanged (still the safety net).
- Files: resources/js/image-shrink.js, resources/js/camera.js, resources/js/register.js, resources/js/app.js, config/inspection.php, resources/views/inspections/step.blade.php, resources/views/auth/register.blade.php, resources/views/profile.blade.php.
- Verified: `npm run build`; `php artisan test --filter="Registration|Evidence|Profile|Photo|IconSubset"` 44 passed.
## 27-11-2026 · Profile: View identity proof in a popup
- **My Profile** has a **View identity proof** button (when one was uploaded) and an "Identity proof" tile (Uploaded / Not uploaded (optional)). The admin user page's button now opens the same popup instead of a new tab.
- Popup (`partials/id-proof-modal.blade.php`): image or PDF, file name, spinner, **Open in new tab** and Close. The file loads only when the popup opens (`[data-lazy-src]`, delegated `show.bs.modal` in `app.js`), so `id_proof_viewed` is logged only then.
- `Admin\ApprovalController::idProof()` now also lets the officer view their own proof (like the photo); others outside the approver / user manager rule still get 404.
- Files: app/Http/Controllers/Admin/ApprovalController.php, resources/views/partials/id-proof-modal.blade.php (new), resources/views/profile.blade.php, resources/views/admin/users/show.blade.php, resources/js/app.js, tests/Feature/RegistrationV7Test.php, CLAUDE.md.
- Verified: `npm run build`; `php artisan test --filter="RegistrationV7|RegistrationAndLogin|IconSubset|QueryBudget|Profile"` 29 passed.

## 27-11-2026 Photos: JPG / PNG only, 1 MB cap (profile and school photos)
- Employee photo (register, My Profile): server adds `mimetypes:image/jpeg,image/png` (content check) next to `mimes:jpg,jpeg,png` and `max:1024`, with the message "The photograph must be a JPG or PNG image." The selfie fallback input accepts only `image/jpeg,image/png`; the profile hint says "JPG or PNG, max 1 MB".
- School (inspection) gallery photos: `#gallery-input` accepts only JPG / PNG, and `camera.js` refuses any other type or extension ("Only JPG or PNG photos are allowed.") before processing. Gallery buttons have a "JPG or PNG photo" title. Larger photos are still fitted and compressed under 1 MB in the browser (user decision: keep shrinking).
- `EvidenceStorage`: the photo type error no longer prints the raw mime ("This photo was not prepared by the app…"); the server still takes only the app's JPEG, up to 1 MB. ID proof (2 MB) and step 7 documents (5 MB) are unchanged (user decision).
- Files: app/Http/Controllers/ProfileController.php, app/Http/Requests/RegisterRequest.php, app/Services/EvidenceStorage.php, resources/js/camera.js, resources/views/inspections/step.blade.php, resources/views/auth/register.blade.php, resources/views/profile.blade.php, tests/Feature/RegistrationAndLoginTest.php, tests/Feature/InspectionFlowTest.php, CLAUDE.md.
- Verified: `npm run build`; `php artisan test --filter="RegistrationAndLogin|RegistrationV7|InspectionFlow|IconSubset"` 51 passed (756 assertions).

## 27-11-2026 Photos: JPG / JPEG / PNG only, max 2 MB, with a warning message
- `employee_photo.max_kb` and `evidence.photo_max_kb` 1024 → 2048.
- `checkPhotoFile()` (resources/js/image-shrink.js) is the one browser rule for a chosen photo. It refuses another type or extension ("Only JPG, JPEG or PNG photos are allowed. "x.webp" is a WEBP file.") and a file over the limit ("This photo is 3.4 MB. The maximum is 2 MB. Please choose a smaller photo."). Large files are no longer shrunk silently.
  - Register photo zone: red message under the zone. The ID proof zone also refuses over 2 MB before any resizing.
  - My Profile: message plus a warning toast.
  - Inspection Gallery: warning toast.
  - In-app camera and selfie photos are made by the app and encoded under 2 MB.
- Server:
  - `photo.max` = "The photograph is larger than 2 MB. Please choose a photo of 2 MB or less."; `photo.mimes|mimetypes|image` = "Only JPG, JPEG or PNG photos are allowed." (Register, Profile).
  - EvidenceStorage: "The photo is larger than 2 MB."
- Hints show "JPG, JPEG or PNG, max 2 MB" from config: profile, Gallery button titles and the step 7 photo note.
- Files: config/inspection.php, resources/js/{image-shrink,register,app,camera,selfie}.js, app/Http/Controllers/ProfileController.php, app/Http/Requests/RegisterRequest.php, app/Services/EvidenceStorage.php, resources/views/{auth/register,profile,inspections/step}.blade.php, tests (RegistrationAndLogin, RegistrationV7, InspectionFlow), CLAUDE.md.
- Verified: `npm run build`; `php artisan test --filter="RegistrationAndLogin|RegistrationV7|InspectionFlow|IconSubset|QueryBudget"` 52 passed (793 assertions).

## 27-11-2026 — Organisation name: "Samagra Shiksha Axom"
- Replaced "Samagra Shiksha Abhiyan, Assam" and "Samagra Shiksha, Assam" with "Samagra Shiksha Axom" everywhere it is shown: top bar brand, sign-in / register header and footer, page loader, My Profile greeting, inspection report PDF, rating report PDF, master list PDFs, My Schools Excel (row 2), blank paper form, demo notice letter, product doc and the docx builder.
- Left unchanged: "Government of Assam", the "SSA, ASSAM" watermark, plain "Samagra Shiksha" (logo alt text, fund source, questionnaire option) and past ACTION.md entries.
- Files: resources/views/{inspections/pdf,reports/ratings/pdf,admin/exports/master-pdf,components/layouts/app,components/layouts/portal,components/page-loader,profile,inspections/blank-form}.blade.php, app/Exports/MySchoolsExport.php, database/seeders/DemoNoticeSeeder.php, tests/Feature/PageLoaderTest.php, Product Details/UI_Pages_and_Application_Modules.md, tools/docs/build-product-docx.cjs, CLAUDE.md.
- Verified: grep finds no old name; tests below.

## 27-11-2026 — Login math CAPTCHA: no negative answers
- Subtraction sums could come out negative ("5 − 9"). `MathCaptcha::refresh()` now uses 10–20 minus 1–9, so every answer is 1 or more; `check()` and the answer box accept digits only.
- Files: app/Support/MathCaptcha.php, resources/views/partials/math-captcha.blade.php, tests/Feature/LoginMathCaptchaTest.php (new `test_the_answer_is_never_negative`, 300 sums), CLAUDE.md.
- Verified: LoginMathCaptchaTest passes.
