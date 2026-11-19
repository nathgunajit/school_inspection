# Technical Audit and Recommendations

19-11-2026. This is a read-only review of the existing implementation; no code was changed. Each observation gives the evidence (file) and a recommendation. Priority: **H** high, **M** medium, **L** low.

---

## 1. Existing technical implementation: summary

The application is a well-structured Laravel 12 monolith:
- Controllers are thin; the logic lives in 17 services and in pure domain classes (`QuestionnaireDefinition`, `InspectionValidator`, `RatingCalculator`).
- The policy and gate layers sit on top of a custom permission model.
- Jurisdiction scoping is centralised in `JurisdictionService`.
- An append-only audit log covers the main actions.
- The development rules are documented (`CLAUDE.md`), and 20 feature test files cover the main flows.

The mobile-first UI is server-rendered with focused ES modules. It supports offline work, an in-app camera and maps.

### 1.1 Feature status

| Area | Feature | Status |
|---|---|---|
| Access | Registration with approval chain, login, lockout, CAPTCHA, forced password change | Implemented |
| | Email password reset | Partial (needs SMTP; most officers have no email) |
| | OTP / SMS | Not implemented (Phase 2; stub exists) |
| Masters | Location and School Master, Excel import with error file | Implemented |
| Inspection | 7-step fixed questionnaire v2, autosave, conditional rules, teacher totals rule | Implemented |
| | Start rules, one per day, monthly block, draft expiry, cancel, unlock | Implemented |
| | Camera / gallery photos with GPS stamp and hash; documents | Implemented |
| | Offline queue (IndexedDB) with ordered replay | Implemented |
| | Configurable questionnaire (questions in DB) | Not implemented (Phase 2) |
| | Review / approval of submitted reports by a higher officer | Not implemented (Phase 2) |
| Rating | Automatic rating, versioned schemes, settings screen, recalculation | Implemented |
| Planning | Day plans, approval, reassignment, route estimate, map | Implemented |
| | Notifications (SMS/email/push) for plans and approvals | Not implemented |
| Reporting | Report page, PDF, Excel lists, rating report, map statistics, login report | Implemented |
| | Analytical dashboard with charts and trends | Partial (personal dashboard; rating report has simple bars) |
| | CSV export | Not implemented (Excel only) |
| Operations | Audit log, login monitoring | Implemented |
| | Data retention (`retention_years = 5`) | Not implemented (setting unused) |
| | Backups, monitoring, CI | Not in the repository |

---

## 2. Security observations

| # | Topic | Observation (evidence) | Assessment | Recommendation | P |
|---|---|---|---|---|---|
| S1 | Authentication | Session guard; bcrypt (12 rounds); session regenerated on login and invalidated on logout (`Auth\LoginController`) | Good | — | |
| S2 | Password policy | Min 8 chars, mixed case, number and symbol, max 64; must differ from the current one (`RegisterRequest`, `PasswordController`) | Good | Add `->uncompromised()` when the server has internet access; consider password expiry for admins | L |
| S3 | Password transport | RSA-OAEP in the browser with a per-session nonce (`PasswordCrypto`, `password-encrypt.js`) | Defence in depth; TLS is still essential | Keep HTTPS mandatory; rotate the keys with `security:keys` and back up `storage/app/private/keys` | M |
| S4 | Brute force | Throttle 10/min/IP, CAPTCHA after 2 failures (session-based), account lock 3 × 20 min | Good; the per-account lock lets anyone who knows a mobile number lock that officer out for 20 minutes | Consider a progressive delay, or locking per mobile + IP, instead of a hard lock; alert on `top_refused` in Login Monitoring | M |
| S5 | Authorization | Gates per permission, `InspectionPolicy`, service-level checks, `JurisdictionService` on every list | Good | Add a policy class for users and schedules, to move the remaining `abort_unless` checks out of the controllers | L |
| S6 | IDOR | Encrypted route keys (`UrlId`, `HasEncryptedRouteKey`); a numeric ID gives 404 | Good | — | |
| S7 | CSRF | Laravel `web` group (VerifyCsrfToken), `@csrf` in forms, axios sends the XSRF token | Good | — | |
| S8 | Input validation | Validated in Form Requests and controllers; questionnaire answers are checked against the definition; enums are checked with `Rule::in` | Good | Move the inline `validate()` calls of `InspectionController` into Form Requests for reuse and testing | L |
| S9 | SQL injection | Eloquent / query builder bindings. The `selectRaw` calls interpolate only whitelisted column names (`MapDataService::LEVELS`, `RatingReportService::areaLevel()`) | Good | Escape `%` and `_` in the user-typed LIKE searches (`ReportController::filtered`, `AuditLogController`) | L |
| S10 | XSS | Blade `{{ }}` escaping. Each `{!! !!}` was reviewed: the content is built with `e()` (`stat-card`, `_results` highlight, schedule chips, error lists) or is `json_encode` with the `JSON_HEX_*` flags | Good | Add a Content-Security-Policy header (all assets are local; only the tiles and OSRM are external) | M |
| S11 | Security headers | Only `X-Content-Type-Options: nosniff` on file responses. No CSP, `X-Frame-Options`, HSTS or `Referrer-Policy` | Gap | Add a small middleware or server config: CSP, `frame-ancestors 'none'`, HSTS, `Referrer-Policy: same-origin`, `Permissions-Policy` (camera and geolocation self) | H |
| S12 | File uploads | Real MIME from finfo, size limits, SHA-256 match, random names, private disk, served through a policy check with `nosniff` (`EvidenceStorage`, `EvidenceController`) | Good | Enable ClamAV in production (`CLAMAV_BINARY`). It is optional and off by default | M |
| S13 | File storage | `storage/app/private/*` is git-ignored; the RSA keys are there too | Good | Restrict the OS permissions; include the folder in backups (encrypted) | M |
| S14 | Session | DB driver, 20 min (`.env.example`); `config/session.php` falls back to 120 min if the env value is missing; `SESSION_ENCRYPT=false`; secure cookie only through the checklist | Acceptable | In production set `SESSION_SECURE_COOKIE=true` and `SESSION_LIFETIME=20` explicitly, and consider `SESSION_ENCRYPT=true` | M |
| S15 | Sensitive data exposure | The `.env.example` has `APP_DEBUG=true` and `LOG_LEVEL=debug` for local use. The demo login panel shows the demo mobile numbers when not in production | Risk on a public staging server | On every server reachable from the internet: `APP_DEBUG=false`, `DEMO_LOGINS=false`, `LOG_LEVEL=warning` | H |
| S16 | Personal data | Mobile, email, photos, ID proofs, GPS of officers (login logs, evidence) | Needs governance | Write a privacy notice and retention rules (see R-Data); mask mobiles in exports where not needed | M |
| S17 | Public endpoints | `/locations/{type}` is public (names and codes only, throttled 120/min); `/up` health check | Acceptable | — | |
| S18 | Raw KML in web root | `public/KML/Assam` (17 MB) is publicly downloadable, but only `public/geo` is needed | Low risk (public data) | Move the KML to `storage/app/kml` and point `map:boundaries` there | L |
| S19 | Password reset | The message does not reveal whether a mobile exists (`PasswordController::sendLink`); throttled 5/10 min | Good | — | |
| S20 | Third-party calls | OSM tiles and the OSRM public demo server (for light use) | Availability and privacy | Self-host or contract a tile and routing provider for production (`MAP_TILE_URL`, `MAP_ROUTING_URL`) | M |

---

## 3. Database observations

| # | Observation | Recommendation | P |
|---|---|---|---|
| D1 | The schedule `status` columns are `varchar(20)`, while the other statuses are `enum` | Add a CHECK constraint or an enum for consistency | L |
| D2 | `inspections.questionnaire_version` defaults to `1` | Change the default to 2 in a migration, or remove the default (the application always sets it) | L |
| D3 | `users.level` ↔ `roles.slug` is not a foreign key | Acceptable by design; document it (done) | — |
| D4 | No archive or retention process; `audit_logs` and `user_login_logs` grow without limit | Monthly archive or partition of the logs; implement `retention_years` | M |
| D5 | Statistics are computed live (COUNT/AVG); the map caches for 60 s | Fine at the current scale (see `LOAD_TEST.md`); for state-wide scale consider summary tables refreshed by the scheduler | M |
| D6 | Soft deletes on users, schools, inspections and evidence; evidence FK is `restrict` | Good; make sure purge jobs delete the files first | — |
| D7 | Legacy v1 columns in the fund and absentee tables | Keep them while v1 data exists; mark them deprecated in the model docblocks | L |
| D8 | No DB backups or point-in-time recovery in the repository | Daily `mysqldump` + binlog, an off-site copy, and a tested restore | H |
| D9 | MariaDB 10.4 is end-of-life | Upgrade the production DB to MariaDB 10.11 LTS or MySQL 8.4 LTS | M |

---

## 4. Code quality observations

| # | Observation | Recommendation | P |
|---|---|---|---|
| C1 | Clear layering; the domain rules are documented in `CLAUDE.md`; consistent naming | Keep it up | — |
| C2 | `InspectionController` (531 lines) and `ScheduleService` (434) are the largest classes | Split the row endpoints (teachers/classrooms/funds) into a `InspectionRowController`; move validation to Form Requests | L |
| C3 | Only feature tests (20 files); no unit tests for `RatingCalculator`, `RouteOptimizer` or `InspectionValidator` | Add unit tests: they are pure classes and cheap to test | M |
| C4 | No JavaScript tests for `wizard.js` (923 lines), `offline-queue.js`, `camera.js` | Add Vitest for the pure parts (queue ordering, rule evaluation) and Playwright for one end-to-end inspection | M |
| C5 | No CI pipeline, static analysis or formatting gate in the repository | Add GitHub Actions: `composer install`, `pint --test`, `phpstan` (Larastan level 5), `php artisan test`, `npm run build` | M |
| C6 | Unused npm dependency `@fontsource-variable/inter` | Remove it | L |
| C7 | The questionnaire rules are duplicated in PHP (`InspectionValidator`) and JS (`wizard.js`) | Keep them generated from one source (the definition is already sent to JS through `window.SI`). Add a test that compares both | L |
| C8 | The rating report export uses the `view_reports` gate, while the inspection export uses `export_excel` | Use `export_excel` for every Excel download, for consistency | L |

---

## 5. Performance observations

| # | Observation | Recommendation | P |
|---|---|---|---|
| P1 | The layout calls `ApproverResolver::pendingFor()` on **every page** for approvers. It loads all pending registrations with their relations and filters them in PHP | Cache the count per user for 1–5 minutes, or compute it with a jurisdiction-scoped SQL count | M |
| P2 | `MapDataService` caches per user for 60 s; aggregate queries are indexed (`DB_INDEXING.md`) | Good. With Redis, the cache store becomes cheaper than the DB cache | L |
| P3 | Cache, session and queue all use the database driver | Use Redis in production for sessions and cache under load | M |
| P4 | PDF generation (DomPDF) with photos is CPU-heavy and runs in the request | Pre-size the images (already ≤ 1600 px); for bulk PDFs use a queue job | L |
| P5 | Assets are built by Vite with targets Chrome 100+/Safari 15+; Leaflet is bundled | Good; enable gzip/brotli and long cache headers for `public/build` | L |
| P6 | Load test results are in `LOAD_TEST.md` | Repeat them on the production hardware before go-live | M |

---

## 6. Inconsistencies found

### 6.1 Brief (`Documents/Documentation.docx`) against the actual project
| Brief says | Actual |
|---|---|
| URL `http://127.0.0.1:8000/login` | Port **8010** (8000 is used by another local project) |
| Expected jQuery | No jQuery; vanilla ES modules + axios |
| `config/permission.php`, `routes/api.php`, `public/css`, `public/js`, `public/images` | Not present: custom permission tables, no API, Vite build in `public/build`, logos in `public/logo` |
| Roles "District Administrator", "Inspection Officer" | Levels `state_admin`, `state`, `district`, `block`, `cluster` + permissions |
| "Inspection submission and approval processes", "Administrator review" | Submission and unlock only; report approval is Phase 2 |
| "Document uploads" | Implemented as optional supporting documents in step 7 (≤ 5, PDF/JPG/PNG ≤ 5 MB) |

### 6.2 Between database, backend and frontend
| Layer | Inconsistency | Impact |
|---|---|---|
| DB ↔ backend | `questionnaire_version` default 1 against the current version 2 | Only for raw inserts |
| DB ↔ backend | Schedule statuses are free text in the DB, enum in PHP | Only for raw inserts |
| Config ↔ backend | `retention_years` declared but unused; `max_documents` enforced only in the controller and view | Retention not applied |
| Config ↔ frontend | Session lifetime: `.env.example` 20, framework default 120. The idle warning reads the configured value | If the env value is missing, sessions last 2 hours |
| Backend ↔ frontend | Validation rules in both PHP and JS (by design) | Risk of drift; covered by the feature tests on the server side only |
| Docs ↔ code | `CLAUDE.md` says "fixed six-step questionnaire" in the Project section; the code has 7 steps | Documentation wording only |
| Docs ↔ code | `CLAUDE.md` (Domain rule 12) names an `OtpService`; the code has `App\Services\Otp\OtpSender` + `LogOtpSender` | Documentation wording only |

---

## 7. Missing or incomplete features

1. Review/approval of submitted inspection reports by higher officers, with comments and return-for-correction (Phase 2).
2. Configurable questionnaire (questions, options and rules in the DB, with versions) (Phase 2).
3. Notifications: SMS/OTP, email and in-app (registration decisions, plan approvals, unlocks).
4. Analytical dashboards for district/state officers (trends, coverage %, top issues).
5. Data retention and archiving; personal-data deletion on request.
6. CSV export and an API for other government systems (UDISE+, PFMS, state dashboards).
7. Self-service password reset without email (OTP).
8. Help pages and a user manual inside the application.
9. Assamese / Bengali / Bodo language support.

## 8. Recommended improvements (short list)

| # | Recommendation | Why | P |
|---|---|---|---|
| R1 | Security headers middleware (CSP, HSTS, frame-ancestors, Permissions-Policy) | Closes S11 | H |
| R2 | Production hardening checklist enforced by a `php artisan app:doctor` command (debug off, demo off, secure cookie, keys present, ClamAV, SMTP, scheduler running) | Prevents S14/S15 mistakes | H |
| R3 | Automated backups of the DB and the private disk, with a restore drill | D8 | H |
| R4 | CI with tests, Pint and Larastan | C5 | M |
| R5 | Unit tests for the rating, route and validator classes; JS tests for the offline queue | C3, C4 | M |
| R6 | Cache the pending approvals count | P1 | M |
| R7 | Implement `retention_years` (archive old inspections and logs) | D4 | M |
| R8 | Redis for cache and session in production | P3 | M |
| R9 | Self-hosted tiles and OSRM, or a contracted provider | S20 | M |
| R10 | Use `export_excel` on every export; fix the `questionnaire_version` default | C8, D2 | L |

The longer-term roadmap is in `Future_Recommendations_Gaps_and_Roadmap.md`.
