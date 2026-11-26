# System Architecture and Workflow

19-11-2026. Every diagram reflects the implemented code. Mermaid renders on GitHub and in VS Code (with a Mermaid extension). In the Word version each diagram is also written out as steps.

---

## 1. Overall system architecture

```mermaid
flowchart LR
    subgraph Client["Officer device (phone / PC browser)"]
        UI[Blade pages + Bootstrap 5]
        JS[ES modules: wizard, camera, offline-queue,<br/>password-encrypt, map, planner]
        IDB[(IndexedDB<br/>offline queue)]
        JS --- IDB
    end
    subgraph Server["Web server: Apache + PHP 8.2 + Laravel 12"]
        MW[Middleware<br/>CSRF, session, decrypt passwords,<br/>auth, active, password.fresh, gates, throttle]
        CT[Controllers]
        SV[Services + domain<br/>Questionnaire, Rating, Jurisdiction...]
        PDF[DomPDF]
        XL[Laravel Excel]
        SCH[Scheduler: expire drafts 00:30,<br/>purge register tmp 01:00]
    end
    DB[(MySQL / MariaDB<br/>schoolinspection)]
    PRIV[[storage/app/private<br/>evidence, photos, ID proofs, RSA keys]]
    GEO[[public/geo GeoJSON<br/>from KML via map:boundaries]]
    OSM[(OpenStreetMap tiles)]
    OSRM[(OSRM routing)]
    MAIL[[Mail: log driver locally]]

    UI --> MW --> CT --> SV
    JS -- AJAX JSON / multipart --> MW
    SV --> DB
    SV --> PRIV
    CT --> PDF
    CT --> XL
    SCH --> SV
    UI -. tiles .-> OSM
    JS -. "Show route" only .-> OSRM
    GEO --> UI
    SV --> MAIL
```

## 2. Database ER diagram

The ER diagram (main tables, all relations) is in `Database_Architecture_and_Schema.md` §3.5. A compact view:

```mermaid
erDiagram
    users ||--o{ user_jurisdictions : postings
    users ||--o{ inspections : conducts
    schools ||--o{ inspections : "is inspected"
    clusters ||--o{ schools : contains
    inspections ||--o{ inspection_answers : ""
    inspections ||--o{ inspection_evidence : ""
    inspections ||--o| inspection_ratings : ""
    users ||--o{ inspection_schedules : plans
    inspection_schedules ||--o{ inspection_schedule_items : ""
```

## 3. User role and access architecture

```mermaid
flowchart TB
    U[Request by user] --> A{Authenticated<br/>and active?}
    A -- no --> L[Login]
    A -- yes --> F{must_change_password?}
    F -- yes --> CP[Change password]
    F -- no --> G{Route gate<br/>can:permission}
    G -- no --> X403[403]
    G -- yes --> P{Policy / service rule<br/>InspectionPolicy, ApproverResolver,<br/>ScheduleService, authorizeManage}
    P -- no --> X403
    P -- yes --> J[JurisdictionService scope<br/>queries limited to the user's area]
    J --> R[Response]
    subgraph Permissions
        RP[role_permissions<br/>by users.level = roles.slug] --> PS[PermissionService::has]
        UP[user_permissions<br/>individual grants] --> PS
        SA[state_admin = all] --> PS
    end
    PS --> G
```

## 4. School hierarchy

```mermaid
flowchart TB
    S[State: Assam<br/>states] --> D1[District<br/>districts.code e.g. 1801]
    S --> D2[District 1802]
    D1 --> B1[Block<br/>blocks.code e.g. 180101]
    D1 --> B2[Block 180102]
    B1 --> C1[Cluster<br/>clusters.code e.g. 18010101]
    B1 --> C2[Cluster 18010102]
    C1 --> SC1[School<br/>schools.udise_code 11 digits]
    C1 --> SC2[School]
    classDef lvl fill:#e3f2fd,stroke:#1565c0
    class S,D1,D2,B1,B2,C1,C2 lvl
```
Officer jurisdictions use the same levels: a State officer covers S, a District officer one D, a Block officer one B, and a Cluster officer one C.

## 5. Inspection workflow

```mermaid
stateDiagram-v2
    [*] --> Draft: Start (InspectionStartGuard ok)
    Draft --> Draft: autosave answers, rows, photos
    Draft --> Submitted: Submit (no missing items, queue empty)
    Draft --> Cancelled: Officer cancels with reason
    Draft --> Cancelled: Not submitted in 7 days (job)
    Submitted --> Unlocked: State Admin unlocks with reason
    Unlocked --> Submitted: Officer resubmits
    Submitted --> [*]
    Cancelled --> Draft: Same officer restarts the same day
```

The step-level flowchart is in `School_Inspection_Mechanism.md` §5.

## 6. Inspection data flow

```mermaid
sequenceDiagram
    participant O as Officer browser
    participant Q as offline-queue (IndexedDB)
    participant S as Laravel
    participant D as MySQL
    participant F as Private disk
    O->>S: POST /schools/{key}/inspections (GPS)
    S->>D: inspections (draft, server date, location snapshot)
    loop each answer / row
        O->>Q: enqueue op (row_uid for new rows)
        Q->>S: POST answers / teachers / classrooms / funds
        S->>D: upsert inspection_answers / rows, completion_pct
        S-->>Q: progress JSON (dequeue)
    end
    O->>O: camera: resize, stamp, JPEG, SHA-256
    Q->>S: POST evidence (file + evidence_uid + hash + GPS)
    S->>F: evidence/{id}/{random}.jpg
    S->>D: inspection_evidence (uploaded)
    O->>S: POST submit (confirm, GPS)
    S->>S: InspectionValidator::progress()
    S->>D: lock row, inspection_no, submitted
    S->>D: inspection_schedule_items → inspected
    S->>D: inspection_ratings (RatingService)
    S->>D: audit_logs
    O->>S: GET report / PDF
```

## 7. Approval workflows

There is **no approval workflow for inspection reports** (Phase 2). Three approval workflows are implemented.

### 7.1 Registration
```mermaid
stateDiagram-v2
    [*] --> Pending: Officer registers
    Pending --> Active: Approver approves (ApproverResolver chain)
    Pending --> Rejected: Approver rejects with reason
    Rejected --> Pending: Officer re-applies
    Active --> Inactive: User manager deactivates
    Inactive --> Active: User manager activates
    Active --> Locked: 3 wrong passwords
    Locked --> Active: after 20 minutes
```

### 7.2 Inspection schedule
```mermaid
stateDiagram-v2
    [*] --> Draft: Officer saves own plan
    Draft --> Submitted: Submit (approval required)
    Draft --> Published: Approval not required
    [*] --> Published: Higher officer plans for officer
    Submitted --> Published: Higher officer approves
    Submitted --> Rejected: Higher officer rejects with note
    Rejected --> Submitted: Officer edits and resubmits
    Published --> Completed: every visit inspected or skipped
    Draft --> Cancelled
    Published --> Cancelled: no visit inspected yet
```

### 7.3 Mobile number change
Officer request (Profile) → State Admin approves (the mobile is updated) or rejects. Both are audited.

## 8. Application module relationships

```mermaid
flowchart LR
    AUTH[Auth & Registration] --> USERS[Users / Jurisdictions / Permissions]
    USERS --> APPROVALS[Registration approvals]
    MASTERS[Location & School Masters<br/>+ Excel import] --> SCHOOLS[Find School / School page]
    USERS --> SCHOOLS
    SCHOOLS --> INSP[Inspection wizard]
    SCHED[Schedules] --> SCHOOLS
    INSP --> EVID[Evidence]
    INSP --> RATE[Rating]
    INSP --> SCHED
    INSP --> REP[Reports / PDF / Excel]
    RATE --> RREP[Rating report]
    RATE --> MAP[Map View]
    INSP --> MAP
    MASTERS --> MAP
    AUTH --> MON[Login monitoring]
    AUDIT[(Audit log)]
    INSP --> AUDIT
    USERS --> AUDIT
    SCHED --> AUDIT
    MASTERS --> AUDIT
    RATE --> AUDIT
    HOL[Holiday Calendar] --> SCHED
    HOL --> TODAY[Today's Schools]
    TODAY --> SCHOOLS
    INSP --> TODAY
    NOTICE[Notices & Orders] --> DASH[Dashboard: Latest Notice, calendar, counters]
    HOL --> DASH
    NOTICE --> AUDIT
    HOL --> AUDIT
```

### 8.1 V14 workflows (25-11-2026)
- **Holiday → planning:** a gazetted holiday (state, or the officer's district) closes the day. `ScheduleService::checkDate()` refuses a plan on it, and Today's Schools makes no list. Restricted and half holidays are only shown. Holidays are added on the Holiday Calendar (also by clicking a date), by Excel import or by the Assam 2026 seeder.
- **Today's Schools:** on the first view of a working day, `DailySchoolListService` makes the officer's list once (manual → carried over → picked by priority or at random, within the daily limit and the settings). Submitting an inspection marks the row visited; a started draft stays on the list with Continue.
- **Notice → read → counters:** the sender publishes a notice to officer levels inside an area (`NoticeService::publish()`). Officers see it in the Inbox, the dashboard card and, when ticked for the homepage, the Latest Notice strip. Opening it records `read_at` and lowers every unread counter at once; Acknowledge records `acknowledged_at`; the sender sees the read report and can withdraw it.
- **Clean Test Data (local server only):** the State Administrator empties one part (inspections and Today's Schools, schedules, the login log, or the audit log after an Excel archive). The page and command do not exist in production.

### 8.2 Background work (26-11-2026)

Long work runs on the queue worker, not inside a web request:

- **Large downloads:** a click on a heavy Excel / PDF download first passes the route's own checks (sign-in, gates, throttle).
  1. `QueueLargeExports` saves it as an `export_jobs` row and answers at once.
  2. The worker (`GenerateExport`) replays the same request as the same officer through the same checks and controller, then stores the file.
  3. The page shows "Preparing…" and the file downloads by itself when ready. It stays on the **Downloads** page for 24 hours.
- **Excel Upload:** the check and the save run as `ImportMasterFile` jobs. The `import_batches` row follows them (checking → checked → queued → importing → done), and the Upload page shows a progress card that refreshes itself.
- **Clean-up:** `exports:purge` (hourly) removes old downloads and unconfirmed uploads, and marks work not finished in 3 hours as failed.

```mermaid
sequenceDiagram
    participant B as Browser
    participant W as Web request
    participant Q as jobs table
    participant K as queue:work
    B->>W: GET export (filters)
    W->>W: route middleware (auth, gates, throttle)
    W->>Q: ExportJob + GenerateExport
    W-->>B: redirect back (export_job)
    K->>Q: take job
    K->>K: replay GET as the officer, same controller
    K->>K: store file (private:exports/)
    B->>W: poll downloads.status
    W-->>B: done + file URL
    B->>W: GET downloads.file (owner only)
```

## 9. Deployment view (current and recommended)

| Item | Current (local) | Production (from the `.env.example` checklist) |
|---|---|---|
| Web | `php artisan serve --port=8010` / XAMPP Apache | Apache or Nginx + PHP-FPM, HTTPS (URLs forced to HTTPS) |
| Assets | `npm run dev` | `npm run build` → `public/build` |
| DB | MariaDB 10.4 | MySQL 8 / MariaDB 10.6+, with backups |
| Scheduler | manual | cron / Task Scheduler: `php artisan schedule:run` every minute |
| Queue | database driver; run `php artisan queue:work` for large downloads and Excel uploads (26-11-2026) | **required**: a `queue:work` service (NSSM / supervisor), `--timeout=3600`, restarted after deploys (`queue:restart`); `cache:status` shows waiting / failed jobs |
| Mail | log | SMTP for password reset links |
| Keys | `php artisan security:keys` | once per server, back up `storage/app/private/keys` |
