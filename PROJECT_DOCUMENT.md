# School Inspection Management System: project document

Modification of the Application V5, Step 1. This document covers the database schema and the functional flow, for developers and for users. Phase 1. Date: 18-11-2026.

| For | Read |
|---|---|
| Officers and administrators (users) | Sections 1, 3, 4 |
| Developers | All sections, then 5–8 |

Related documents:
- `Documents/School_Inspection_PRD.docx`: the requirements
- `CLAUDE.md`: developer rules
- `DB_INDEXING.md`: database indexes
- `LOAD_TEST.md`: capacity
- `BROWSER_SUPPORT.md`: browsers
- `ACTION.md`: change log

The diagrams use Mermaid. GitHub and VS Code (with a Mermaid extension) show them as pictures.

---

## 1. What the application does
Inspecting officers of the Samagra Shiksha (Assam) at **State, District, Block and Cluster** level use the application.
1. They **register** themselves and are **approved** by a higher officer.
2. They **find a school** by UDISE code, within their own area.
3. At the school they fill a **fixed 7-step inspection form** and take **geo-stamped photographs** in the app.
4. They **submit** the report, which is then **locked**.
5. Higher officers **see the reports of their area**, print them as **PDF** and export them to **Excel**.
6. The **State Administrator** manages masters (locations, schools), users and unlocks, and reads the audit log.

```mermaid
flowchart LR
    O[Officer<br/>phone or PC browser] -->|HTTPS| W[Web server<br/>Apache / PHP 8.2<br/>Laravel 12]
    W --> DB[(MySQL / MariaDB<br/>schoolinspection)]
    W --> P[[Private disk<br/>photos, ID proofs,<br/>employee photos]]
    W --> L[[Logs<br/>SMS log driver]]
    S[Scheduler<br/>00:30 daily] -->|expire drafts| W
```

---

## 2. Main terms
| Term | Meaning |
|---|---|
| **Level** | State Admin, State, District, Block or Cluster. Set at registration; users cannot change it themselves |
| **Jurisdiction** | The area of a user: the whole state, one district, one block or one cluster. Stored as a *posting* (`user_jurisdictions`) with start and end dates |
| **UDISE code** | The 11-digit national school code, used to find a school |
| **Draft** | An inspection that has been started but not submitted. Only its officer can see it. Cancelled automatically after 7 days |
| **Submitted** | Final and locked. It gets an Inspection ID such as `SI/2026/1801/000123` |
| **Unlocked** | Opened again by the State Admin, with a reason, so the officer can correct it and submit again |
| **Question code** | A fixed ID for every question (e.g. `GOVR_02`), stored with each answer. Never renamed or reused |

---

## 3. User types and what they can do
### 3.1 Levels and area
| Level | Area (jurisdiction) | Typical designation |
|---|---|---|
| State Administrator | Whole state; also manages masters and users. Created at installation | System administrator |
| State | Whole state | Director, Joint Director, Deputy Director |
| District | One district and its blocks, clusters and schools | DEO, Inspector of Schools, DPC |
| Block | One block and its clusters and schools | BEEO, BMC, BRP |
| Cluster | One cluster and its schools | CRCC |

### 3.2 Rights (PRD 3.2)
Rights are **permissions**. Each level has a default role, and a higher administrator can grant extra rights to one person.

| Action | State Admin | State | District | Block | Cluster |
|---|---|---|---|---|---|
| Conduct and submit inspections | Yes | Yes | Yes | Yes | Yes |
| View inspection reports | All | All | Own district | Own block | Own cluster + own |
| Export to Excel | Yes | Yes | Yes | Yes | Own only |
| Approve registrations of the level below | Yes | If granted | If granted | If granted | No |
| Activate, deactivate, edit, transfer users | Yes | No | If granted | No | No |
| Masters (locations, schools, import) | Yes | No | No | No | No |
| Unlock a submitted inspection | Yes | No | No | No | No |
| Mobile number changes, audit log | Yes | No | No | No | No |

### 3.3 Who approves a registration (PRD 3.3)
```mermaid
flowchart TB
    SA[State Admin]
    ST[State officer<br/>with approval right]
    DI[District officer<br/>with approval right]
    BL[Block officer<br/>with approval right]
    A1([State applicant]) --> SA
    A2([District applicant]) --> ST
    A2 -.otherwise.-> SA
    A3([Block applicant]) --> DI
    A3 -.otherwise.-> SA
    A4([Cluster applicant]) --> BL
    A4 -.no block approver.-> DI
    A4 -.otherwise.-> SA
```
An approver must be **active**, hold the **approval right**, and have the applicant's area **inside their own**. Nobody approves themselves.

### 3.4 User type check (V5 Step 1)
The automated test `tests/Feature/UserTypeAccessTest.php` checks all five levels on every run of `php artisan test`. It verifies:

| Check | Result |
|---|---|
| State Admin opens all officer and admin screens: dashboard, profile, find school, my inspections, area inspections, approvals, users, mobile changes, audit, locations, schools, imports | Pass |
| State, District, Block and Cluster officers open the officer screens and get **403** on every admin screen | Pass |
| A District officer given "approve" and "manage users" opens only those two admin screens; audit and masters stay closed | Pass |
| A submitted inspection is seen by: State Admin, State, the District / Block / Cluster officers **of the same area**. It is **not** seen by another district, block or cluster (report page and area list) | Pass |
| A draft is seen only by its own officer | Pass |

Demo accounts for a manual check are in `CLAUDE.md`:
- active officers at every level, plus pending, rejected and inactive accounts
- password `Demo@1234`
- Admin: `9999999999`

---

## 4. Functional flows (for users and developers)

### 4.1 Registration and approval
```mermaid
flowchart TD
    A[Open /register] --> B[Fill name, designation, employee ID,<br/>level, District / Block / Cluster,<br/>mobile, password]
    B --> C[Upload ID proof PDF/JPG/PNG ≤ 2 MB<br/>and employee photo JPG/PNG ≤ 1 MB]
    C --> D{Valid?<br/>mobile and employee ID unique}
    D -- No --> B
    D -- Yes --> E[User saved as PENDING<br/>registration_request pending<br/>posting created]
    E --> F[Approver sees it in Approvals]
    F --> G{Decision}
    G -- Approve --> H[User ACTIVE<br/>SMS 'approved' to log]
    G -- Reject with reason --> I[User REJECTED<br/>SMS with reason]
    I -.re-apply with same mobile.-> B
    H --> J[Officer can sign in]
```

### 4.2 Sign in and session security
```mermaid
flowchart TD
    A[Login: mobile + password] --> B{Account locked?}
    B -- Yes, time left --> X[Show 'Try again in n minutes']
    B -- No --> C{Password correct?}
    C -- No --> D[failed_login_attempts + 1<br/>CAPTCHA after 2 failures]
    D --> E{3 failures?}
    E -- Yes --> F[Status LOCKED for 20 minutes<br/>audit: login_locked]
    E -- No --> A
    C -- Yes --> G{Status}
    G -- Pending / Rejected / Inactive --> Y[Explain why, no sign-in]
    G -- Active --> H{Must change password?}
    H -- Yes --> I[Change password screen]
    H -- No --> J[Dashboard]
    I --> J
    J --> K[Idle 18 min: warning toast<br/>'Stay signed in' → /session/ping]
    K --> L[Idle 20 min: session ends → login]
```
The login form allows 10 attempts per minute from one IP address. Forgot password sends a reset link by email.

### 4.3 Find a school and start an inspection (start rules, Modification V3)
```mermaid
flowchart TD
    A[Find School: UDISE or name<br/>only schools in my area] --> B[School page]
    B --> C[Start inspection<br/>browser sends GPS location]
    C --> D{Rules in InspectionStartGuard<br/>school row locked during the check}
    D -- My open draft exists any date --> E[Reopen my draft]
    D -- I submitted it today --> F[Open my report]
    D -- Another officer's open draft --> G[Blocked: 'Being inspected by Name']
    D -- Another officer submitted it this month --> H[Blocked until the 1st of next month]
    D -- None --> I[Create DRAFT<br/>date and time from the server<br/>area codes copied from the school]
    E --> W[Wizard step 1]
    I --> W
```
Cancelled inspections never block. Every blocked attempt is audited (`inspection_start_blocked`). The database allows **one inspection per officer, per school, per day** (unique key).

### 4.4 The inspection form (7 steps)
```mermaid
flowchart LR
    S1[1 Condition of the School<br/>CND_* + person met] --> S2[2 Teachers and Students<br/>TSI_* numbers]
    S2 --> S3[3 Teachers Absentee rows]
    S3 --> S4[4 Classroom Performance rows]
    S4 --> S5[5 Fund received and expenditure<br/>by financial year, in lakh]
    S5 --> S6[6 School Governance<br/>GOVR_* + 3 suggestions]
    S6 --> S7[7 Photographs<br/>4 mandatory categories]
    S7 --> R[Review: all answers,<br/>missing items, Expand all]
    R --> SUB[Submit]
```
- **Auto-save:** every answer is saved as soon as it changes (`POST /answers`), so nothing is lost if the network or the session drops. The progress bar comes from `/progress`.
- **Dependent questions:** a question that depends on another one (e.g. CND_18 only when CND_17 = Yes) is greyed out. Its answer is cleared if the parent answer changes.
- **Photos:**
  - taken with the in-app camera or picked from the gallery
  - resized to at most 1600 px and saved as JPEG
  - a strip is stamped with the date, time and GPS; SHA-256 is calculated
  - then uploaded
  - Gallery photos are marked "From gallery". Uploading the same photo again does nothing (`evidence_uid`).
  - Mandatory: school building, toilet block, ICT / smart class, classroom transaction.

### 4.5 Submit, lock, unlock and expiry (inspection life cycle)
```mermaid
stateDiagram-v2
    [*] --> Draft: Start
    Draft --> Draft: Auto-save answers, photos
    Draft --> Submitted: Submit (mandatory items done, confirmed, GPS) gives Inspection ID
    Draft --> Cancelled: Officer cancels
    Draft --> Cancelled: Not submitted in 7 days (nightly job)
    Submitted --> Unlocked: State Admin unlocks with reason (audited)
    Unlocked --> Submitted: Officer corrects and resubmits (same ID)
    Cancelled --> Draft: Same officer restarts the same day
```
- **Inspection ID:** `SI/<year>/<district code>/<6-digit running number>`, given at the first submission.
- **Submitted inspections are read-only.** Only the State Admin can unlock one.

### 4.6 Reports, PDF and Excel
```mermaid
flowchart TD
    A[My Inspections<br/>own drafts and submitted] --> V[Report page]
    B[Inspections in Area<br/>submitted in my area<br/>filters: dates, district, block,<br/>cluster, school, officer, status] --> V
    V --> P[PDF: header with logos, school card,<br/>basic information, all steps, photos,<br/>watermark, 'Page X of Y']
    A --> X[Excel export: one row per inspection,<br/>every answer as a column]
    B --> X
    V --> PH[Photos served only after a permission check]
```
Counts at the top of the area list: schools in the area, schools inspected in the period, and inspections submitted.

### 4.7 Administration
```mermaid
flowchart LR
    subgraph Users [Users: State Admin or granted]
      U1[User list in my area] --> U2[Edit details / Activate / Deactivate]
      U1 --> U3[Transfer: end old posting,<br/>start new posting from a date]
      U1 --> U4[Reset password → must change at next login]
      U1 --> U5[Grant extra rights]
    end
    subgraph Masters [Masters: State Admin]
      M1[Districts / Blocks / Clusters] --> M3[Excel import with error file]
      M2[Schools] --> M3
    end
    subgraph Other [State Admin]
      O1[Mobile change requests: approve / reject]
      O2[Audit log: filter by user, action, IP, date]
    end
```
Officers request a **mobile number change** from their profile; the State Admin decides it. **Transfers** keep past inspections in the old area.

---

## 5. Database schema
MySQL / MariaDB database `schoolinspection`, InnoDB, utf8mb4. There are 32 tables, all created by `database/migrations`. The location masters run first (`0001_01_00_*`).

### 5.1 Entity relationship diagram (main tables)
```mermaid
erDiagram
    states ||--o{ districts : has
    districts ||--o{ blocks : has
    blocks ||--o{ clusters : has
    clusters ||--o{ schools : has
    master_values ||--o{ schools : "management, category"
    master_values ||--o{ users : designation

    users ||--o{ user_jurisdictions : "postings (current: end_date null)"
    users ||--o{ registration_requests : applies
    users ||--o{ mobile_change_requests : requests
    users ||--o{ user_permissions : "extra rights"
    permissions ||--o{ user_permissions : ""
    roles ||--o{ role_permissions : ""
    permissions ||--o{ role_permissions : ""

    users ||--o{ inspections : "officer"
    schools ||--o{ inspections : inspected
    inspections ||--o{ inspection_answers : "answers by question_code"
    inspections ||--o{ inspection_absent_teachers : "step 3"
    inspections ||--o{ inspection_classroom_observations : "step 4"
    inspections ||--o{ inspection_fund_positions : "step 5"
    inspections ||--o{ inspection_remarks : "suggestions"
    inspections ||--o{ inspection_evidence : "photos, documents"
    master_values ||--o{ inspection_evidence : "photo category"
    users ||--o{ audit_logs : "actor"
    users ||--o{ import_batches : uploads
```

### 5.2 Tables
**Location and school masters**
| Table | Purpose | Key columns |
|---|---|---|
| `states` | State (single-state deployment, code 18) | `code` UQ, `name`, `is_active` |
| `districts` | Districts | `state_id` FK, `code` UQ (e.g. 1801), `name`, lat/lng, `is_active` |
| `blocks` | Blocks | `district_id` FK, `code` UQ (180101), `name`, lat/lng, `is_active` |
| `clusters` | Clusters | `block_id` FK, `code` UQ (18010101), `name`, `is_active` |
| `schools` | School master | `udise_code` CHAR(11) UQ, `name`, `state_id`/`district_id`/`block_id`/`cluster_id` FK, `management_id`/`category_id` → master_values, address, lat/lng, `status` (active/closed), soft delete |
| `master_values` | Drop-down lists | `type` (designation, management, category, photo_category, fund_category, subject), `code`, `label`, `sort`, `is_active` |
| `import_batches` | Excel imports of masters | `type` (location/school), file, `uploaded_by`, total/added/updated/rejected, `error_file_path` |

**Users and access**
| Table | Purpose | Key columns |
|---|---|---|
| `users` | Officers and administrators | `name`, `designation_id`, `employee_id` UQ, `mobile` CHAR(10) UQ (login), `email`, `password` (bcrypt), `level`, `status` (pending, active, rejected, inactive, locked), `id_proof_path`, `photo_path` (private disk), `failed_login_attempts`, `locked_until`, `must_change_password`, `last_login_at`, soft delete |
| `user_jurisdictions` | Postings. The **current** one has `end_date` null; a transfer ends it and adds a new one | `user_id`, `state_id`, `district_id`, `block_id`, `cluster_id` (filled down to the user's level), `start_date`, `end_date`, `assigned_by` |
| `roles` / `role_permissions` | One role per level, with its default rights | `slug` = level |
| `permissions` | Rights | `slug`: conduct_inspections, view_reports, export_excel, approve_registrations, manage_users, manage_masters, unlock_inspections |
| `user_permissions` | Extra rights given to one user | `user_id`, `permission_id`, `granted_by`, `granted_at` |
| `registration_requests` | Approval queue | `user_id`, `approver_id`, `status` (pending/approved/rejected), `reason`, `decided_at` |
| `mobile_change_requests` | Mobile number changes | `user_id`, `old_mobile`, `new_mobile`, `status`, `reason`, `decided_by`, `decided_at` |
| `otp_verifications` | Ready for Phase 2 OTP (not used in Phase 1) | `mobile`, `purpose`, `otp_hash`, `attempts`, `expires_at` |
| `password_reset_tokens`, `sessions` | Laravel password reset and sessions (database driver) | |

**Inspections**
| Table | Purpose | Key columns |
|---|---|---|
| `inspections` | One inspection | `inspection_no` UQ (given at submit), `officer_id`, `school_id`, `inspection_date` (server date), `started_at`, `submitted_at`, `district_id`/`block_id`/`cluster_id` and their codes (copied from the school at start, so a later master change or transfer does not move old reports), start/submit GPS and accuracy, `person_met_name`/`_designation`, `status` (draft, submitted, unlocked, cancelled), `completion_pct`, `questionnaire_version` (2), `unlocked_by`/`_at`/`unlock_reason`, `cancelled_reason`, soft delete. **UQ (officer_id, school_id, inspection_date)** |
| `inspection_answers` | Answers of steps 1, 2 and 6 | `inspection_id`, `step`, `question_code`, `value` (JSON), `remarks`. **UQ (inspection_id, question_code)** |
| `inspection_absent_teachers` | Step 3 rows | `teacher_name`, `teacher_type`, `reason` (e.g. UL = unauthorised leave), `status`, `remarks` |
| `inspection_classroom_observations` | Step 4 rows | `teacher_name`, `class`, `subject`, `tlm_used`, `participation`, `remarks` |
| `inspection_fund_positions` | Step 5, one row per financial year | `financial_year` (UQ per inspection), received/expenditure for ASG, MDM, Govt, Other (lakh) |
| `inspection_remarks` | Suggestions and notes | `type` (suggestion, general, classroom_note, inspection_remark), `sort`, `body` |
| `inspection_evidence` | Photos and documents | `evidence_uid` UQ (idempotent upload), `kind`, `source` (camera/gallery), `category_id`, `question_code`, `caption`, `taken_at`, `received_at`, GPS, `no_location_reason`, `mime`, `size_bytes`, `storage_path` (private disk, random name), `sha256`, `status` (pending, uploaded, failed), `uploaded_by` |

**Audit and system**
| Table | Purpose |
|---|---|
| `audit_logs` | **Append-only** log: `user_id`, `action` (e.g. login, login_failed, registration_approved, inspection_started, inspection_submitted, inspection_unlocked, inspection_pdf, evidence_downloaded), `auditable_type`/`_id`, `meta` JSON, `ip_address`, `user_agent`, `created_at`. Kept for 5 years |
| `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `migrations` | Laravel cache, queue and migration tables |

Indexes and the reason for each one are in `DB_INDEXING.md`.

### 5.3 Rules the database enforces
- **Unique keys:**
  - one inspection per officer, school and day
  - one answer per question per inspection
  - one fund row per year
  - unique mobile, employee ID, UDISE code, Inspection ID and `evidence_uid`
- **Foreign keys with RESTRICT:** a location or school that has inspections cannot be deleted.
- **Soft deletes** on users, schools, inspections and evidence. Nothing is removed for good from the screens.
- **The answer `value` column holds JSON.** The model casts it.

---

## 6. Modules and the tables they use
| Module (controller / service) | Reads | Writes |
|---|---|---|
| Registration (`Auth\RegisterController`) | locations, master_values | users, user_jurisdictions, registration_requests, audit_logs |
| Login, password (`Auth\LoginController`, `PasswordController`) | users | users (failures, lock, last_login), sessions, password_reset_tokens, audit_logs |
| Approvals (`Admin\ApprovalController`, `ApproverResolver`) | registration_requests, users, user_jurisdictions, user_permissions | registration_requests, users.status, audit_logs |
| Users (`Admin\UserController`) | users, user_jurisdictions, permissions | users, user_jurisdictions (transfer), user_permissions, audit_logs |
| Profile, mobile change (`ProfileController`, `Admin\MobileRequestController`) | users, user_jurisdictions, inspections (counts) | users.photo_path, mobile_change_requests, users.mobile, audit_logs |
| Masters (`Admin\LocationController`, `SchoolMasterController`, `ImportController`, `MasterImportService`) | locations, schools | states, districts, blocks, clusters, schools, import_batches, audit_logs |
| Find School (`SchoolController`, `JurisdictionService`) | schools, inspections (badges) | — |
| Start (`InspectionController::start`, `InspectionStartGuard`) | schools (locked row), inspections | inspections, audit_logs |
| Wizard (`InspectionController`, `InspectionValidator`, `QuestionnaireDefinition`) | inspections and all child tables | inspection_answers, absent_teachers, classroom_observations, fund_positions, remarks, inspections (person met, completion) |
| Photos (`EvidenceController`, `EvidenceStorage`) | inspection_evidence | inspection_evidence, private disk, audit_logs (download) |
| Submit / unlock / cancel | inspections | inspections (number, status, GPS), audit_logs |
| Reports, PDF, Excel (`ReportController`) | inspections + children, schools, users, locations | audit_logs (pdf, export) |
| Dashboard (`DashboardController`) | inspections, registration_requests | — |
| Audit (`Admin\AuditLogController`, `AuditLogger`) | audit_logs | audit_logs (append only) |
| Nightly job `inspections:expire-drafts` | inspections | inspections (cancelled), audit_logs |

**Example: one auto-save**
1. `POST /inspections/{id}/answers`
2. `InspectionPolicy::update`: only the officer, and only while the inspection is Draft or Unlocked
3. `QuestionnaireDefinition::find(code)` and clean the value
4. upsert `inspection_answers` (inspection_id, question_code)
5. clear the answers of children it now disables
6. update `inspections.person_met_*` if the question was the person met
7. return the progress as JSON

---

## 7. Developer guide
### 7.1 Code layout
| Path | Content |
|---|---|
| `app/Enums` | UserLevel (rank, area rules), UserStatus, InspectionStatus, EvidenceStatus |
| `app/Services` | JurisdictionService (**every** area filter), ApproverResolver, PermissionService, InspectionStartGuard, InspectionNumberGenerator, EvidenceStorage, AuditLogger, MasterImportService, Otp/* |
| `app/Questionnaire` | `QuestionnaireDefinition` (version 2, the fixed questions; it drives both the screens and the validation), `InspectionValidator` |
| `app/Policies/InspectionPolicy.php` | view / update / cancel / unlock rules |
| `app/Http/Middleware` | `active` (signed-out if not active), `password.fresh` (force password change) |
| `config/inspection.php` | Configurable values: draft expiry, login lock, photo limits, mandatory photos, page size, retention |
| `resources/views`, `resources/js`, `resources/scss` | Blade screens, wizard / camera / review JS, Samagra Shiksha theme |
| `database/seeders` | Roles, masters, demo locations and schools, State Admin, demo users, load-test data |
| `tests/Feature` | Registration and login, approvals and users, the inspection flow, masters and audit, user types, load-test switch |
| `tools/loadtest` | Load driver (LOAD_TEST.md) |

### 7.2 Request path
```mermaid
flowchart LR
    R[Request] --> M[Middleware:<br/>auth, active, password.fresh,<br/>can:permission, throttle]
    M --> C[Controller<br/>Form Request validation]
    C --> PO[Policy / Gate]
    PO --> S[Service<br/>JurisdictionService scope]
    S --> E[Eloquent models]
    E --> DB[(Database)]
    C --> AU[AuditLogger::log]
    C --> V[Blade view / JSON / PDF / Excel]
```

### 7.3 Rules that must not be broken
They are listed in `CLAUDE.md` under domain rules. In short:
1. Every school, user and inspection query goes through `JurisdictionService`. Location IDs from the browser are never trusted.
2. Users cannot change their own level or area.
3. Question codes are never renamed or reused.
4. Submitted means locked; only the State Admin unlocks, with a reason.
5. One inspection per officer, school and day; the start rules apply.
6. Evidence stays on the private disk and is served only after a permission check.
7. The audit log is append-only.
8. Server time is the source of truth.

### 7.4 Commands
```bash
php artisan migrate --seed            # schema + roles, masters, State Admin, demo data
php artisan serve --port=8010         # development (Apache for load tests)
npm run dev | npm run build           # assets
php artisan config:clear && php artisan test   # tests (SQLite in memory only)
php artisan inspections:expire-drafts # nightly at 00:30 through the scheduler
php artisan optimize                  # production (required on Windows/Apache, LOAD_TEST.md)
```

### 7.5 Testing
- 56 feature tests, run on SQLite in memory. `tests/TestCase.php` refuses to run against any other database.
- **Never run the tests while the config is cached.**
- Demo data for manual tests: `DemoUserSeeder`.
- Volume data: `LoadTestSeeder`. Use it only on a database you have backed up.

---

## 8. Statuses at a glance
| Object | Statuses |
|---|---|
| User | pending → active / rejected; active ↔ inactive; active → locked (20 min) → active |
| Registration request | pending → approved / rejected |
| Mobile change request | pending → approved / rejected |
| Inspection | draft → submitted → unlocked → submitted; draft → cancelled (by the officer or after 7 days) |
| Evidence | pending → uploaded / failed |
| School | active / closed (closed schools cannot be inspected) |
