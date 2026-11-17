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
