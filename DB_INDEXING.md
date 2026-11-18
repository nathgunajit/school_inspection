# Database indexing report

Modification of the Application V4, item 5. Database `schoolinspection` (MySQL 8 / MariaDB, InnoDB). Date: 18-11-2026.

## 1. Method
1. Listed every index (`information_schema.statistics`) and every query the screens and jobs run: controllers, `JurisdictionService`, `InspectionStartGuard`, the `inspections:expire-drafts` job, and the Excel export.
2. Matched each frequent filter and sort to an index, with equality columns first, then the range or sort column.
3. Fixed queries that could not use any index.
4. Added the missing indexes in one migration and checked them with `EXPLAIN`.

## 2. Indexes that were already in place (kept)
| Table | Index | Used for |
|---|---|---|
| inspections | `UNIQUE (officer_id, school_id, inspection_date)` | One inspection per officer, school and day (rule 5) |
| inspections | `UNIQUE (inspection_no)` | Look-up by inspection ID |
| inspections | `(status, inspection_date)`, `(district_id, block_id, cluster_id)` | State-wide lists, jurisdiction |
| inspection_answers | `UNIQUE (inspection_id, question_code)` | Auto-save (one answer per question) |
| inspection_evidence | `UNIQUE (evidence_uid)`, `(inspection_id, kind, status)`, `(sha256)` | Idempotent upload, photos of an inspection, duplicates |
| inspection_fund_positions | `UNIQUE (inspection_id, financial_year)` | One row per year |
| schools | `UNIQUE (udise_code)`, `(name)` | UDISE search (prefix), name |
| users | `UNIQUE (mobile)`, `UNIQUE (employee_id)`, `(level, status)` | Login, registration, user lists |
| user_jurisdictions | `(user_id, end_date)` | Current posting of a user |
| audit_logs | `(action, created_at)`, `(auditable_type, auditable_id)` | Audit filters |
| registration_requests | `(status, created_at)` | Pending registrations |
| Foreign keys | One index on every FK column | Joins and FK checks |

## 3. Problem fixed: date functions blocked index use
Nine queries used `whereDate('inspection_date', …)`. MySQL compiles this to `DATE(inspection_date) = ?`, and wrapping the column in a function stops any index on it from being used. The affected places were:
- dashboard
- start rules
- school page
- search badges
- reports
- Excel export

**Fix:**
- These queries now compare the column directly: `where('inspection_date', '>=', '2026-11-01')`.
- `Inspection::inspectionDate()` always stores the value as `Y-m-d`, so the comparison is correct on MySQL and also on SQLite, which the tests use.
- Results are unchanged; 48 tests pass.

## 4. Indexes added (`2026_11_18_000006_add_performance_indexes`)
| Table | New index (columns) | Query it serves |
|---|---|---|
| inspections | `insp_school_status_date_idx` (school_id, status, inspection_date) | Start rules: another officer's draft or completion this month; school page; search badges |
| inspections | `insp_officer_status_date_idx` (officer_id, status, inspection_date) | My Inspections, dashboard counts, profile counts, own export |
| inspections | `insp_district_status_date_idx` (district_id, status, inspection_date) | District reports and Excel export by period |
| inspections | `insp_block_status_date_idx` (block_id, status, inspection_date) | Block reports and export |
| inspections | `insp_cluster_status_date_idx` (cluster_id, status, inspection_date) | Cluster reports and export |
| inspections | `insp_status_started_idx` (status, started_at) | Nightly job that expires drafts |
| schools | `schools_{district,block,cluster}_status_name_idx` (…_id, status, name) | Find School within a jurisdiction, ordered by status then name |
| inspection_answers | `answers_question_code_idx` (question_code) | Question-wise reports and export columns |
| audit_logs | `audit_created_idx` (created_at), `audit_user_created_idx` (user_id, created_at), `audit_ip_idx` (ip_address) | Audit screen date range, by user, by IP; 5-year retention |
| user_jurisdictions | `uj_{district,block,cluster}_end_idx` (…_id, end_date) | Users and approvers of a jurisdiction (current posting) |
| registration_requests | `reg_approver_status_idx` (approver_id, status) | Approval queue of one approver |
| mobile_change_requests | `mcr_status_created_idx` (status, created_at), `mcr_user_status_idx` (user_id, status) | Pending mobile changes; pending request on the profile |

The migration only adds indexes and skips any that already exist, so it is safe on a live database. `down()` removes exactly these indexes.

## 5. EXPLAIN check (local data, 18-11-2026)
| Query | Index chosen |
|---|---|
| Start rules: another officer's open draft | `insp_school_status_date_idx` (ref) |
| My inspections this month | `inspections_officer_school_day_unique` (ref); `insp_officer_status_date_idx` is a candidate |
| Expire drafts job | `insp_status_started_idx` (range) |
| Find School in a cluster | `schools_cluster_status_name_idx` (ref) |
| Users of a district | `uj_district_end_idx` (ref) |
| Approval queue | `reg_approver_status_idx` (ref) |
| Answers of one question | `answers_question_code_idx` (ref) |
| Completed this month, area reports (district / cluster) | The new indexes are listed as candidates. With only a few rows in the local database, MySQL reads the whole table instead, which is cheaper at that size. The indexes are used once the tables grow. |

Re-run the check on the server after the pilot with: `EXPLAIN select … from inspections where district_id = ? and status in ('submitted','unlocked') and inspection_date between ? and ?`.

## 6. Not indexed, on purpose
- **School name search** `like '%text%'`: a leading wildcard can't use a normal index. With about 50,000 schools a full scan stays fast. If needed later, a FULLTEXT index on `schools.name` is the next step.
- **Answer values**: they are not searched directly, and reports group by question.
- **Low-volume tables** (masters, roles, permissions): the primary or unique keys are enough.

## 7. Action plan
| # | Action | Status |
|---|---|---|
| 1 | Replace `whereDate()` on `inspection_date` with plain comparisons; store the date as `Y-m-d` | Done (ACTION.md #55) |
| 2 | Add the indexes in section 4 (migration `2026_11_18_000006`) | Done; applied locally |
| 3 | Add a test that the key indexes exist and that the date is stored as plain `Y-m-d` | Done |
| 4 | Server: run `php artisan migrate` during a quiet hour (InnoDB builds the indexes online) | To do at deployment |
| 5 | After the pilot (more than 10,000 inspections): re-run the EXPLAIN checks; turn on the slow query log (`long_query_time = 1`) for a week | To do |
| 6 | If name search becomes slow: FULLTEXT index on `schools.name` | Only if needed |
| 7 | Review the indexes again before Phase 2 (dashboards, scoring), which add new grouped queries | Phase 2 |
