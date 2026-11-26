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
| inspection_answers | ~~`answers_question_code_idx` (question_code)~~ | **Removed again** after the volume review (section 8): no query reads answers by question alone |
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
| Answers of one question | `answers_question_code_idx` (ref). Index removed later; see section 8 |
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
| 8 | Check every index on the load-test volume (10,000 inspections, 650,000 answers); see section 8 | Done (ACTION.md #61) |
| 9 | Remove the unused `answers_question_code_idx` (migration `2026_11_18_000007`) | Done; applied locally |
| 10 | Server: run `php artisan migrate` (adds `000006`, removes the unused index in `000007`), then `ANALYZE TABLE inspections, inspection_answers, schools` | To do at deployment |
| 11 | Set `innodb_buffer_pool_size` to 1–2 GB. At 650,000 answers the answer table plus its indexes are already about 67 MB, four times the XAMPP default of 16 MB (LOAD_TEST.md finding 4) | To do at deployment |
| 13 | V14 / real School Master review: school indexes, grouped visit join, request memo, `db:maintain`, slow query log (section 9) | Done (25-11-2026) |
| 14 | Server: `php artisan migrate` (adds `000012`) and schedule `db:maintain` (in `schedule:run`) | To do at deployment |
| 12 | Phase 2 question-wise totals: join through `inspections` (district/block/cluster + status + date indexes), then group by `question_code`. Do not filter answers by question alone | Phase 2 |

## 8. Volume check (18-11-2026, after the load test)
**Setup:**
- `LoadTestSeeder` data:
  - 10,006 inspections
  - 650,138 answers
  - 5,024 schools
  - 521 users
- Ran `ANALYZE TABLE` on the main tables, then ran `EXPLAIN` and timed each query (average of 5 runs) on the local MariaDB.
- The database was backed up first and restored afterwards.

| Query (screen or job) | Index chosen | Access | Rows read | Time |
|---|---|---|---|---|
| Start rules: another officer completed this month | `insp_school_status_date_idx` | ref | 2 | 0.6 ms |
| Start rules: another officer's open draft | `insp_school_status_date_idx` | range | 2 | 0.6 ms |
| School page: history of one school | `insp_school_status_date_idx` | range | 3 | 0.9 ms |
| My inspections: submitted this month | `inspections_status_inspection_date_index` | range | 1 | 0.5 ms |
| My inspections list, newest first | `insp_officer_status_date_idx` (+ sort of ~40 rows) | ref | 40 | 2.2 ms |
| Area report: district, 3 months | `insp_district_status_date_idx` | range | 1,254 | 6.6 ms |
| Area report: block, 3 months | `insp_block_status_date_idx` | range | 640 | 2.0 ms |
| Area report: cluster, 3 months | `insp_cluster_status_date_idx` | range | 334 | 1.1 ms |
| Area report: whole state, this month | `inspections_status_inspection_date_index` | range | 2 | 0.5 ms |
| Expire drafts job | `insp_status_started_idx` | range | 1 | 0.4 ms |
| Find School in a cluster (first page) | `schools_cluster_status_name_idx` | ref | 628 | 4.4 ms |
| Find School by UDISE prefix (`1801010…`) | `schools_udise_code_unique` | range | 6 | < 1 ms |
| Find School by name (contains) | none (full scan, see section 6) | ALL | 4,984 | 5.2 ms |
| Answers of one inspection (report, PDF, export) | `inspection_answers_inspection_id_question_code_unique` | ref | 65 | 0.9 ms |
| Login: user by mobile | `users_mobile_unique` | const | 1 | 0.6 ms |
| Current posting of a user | `user_jurisdictions_user_id_end_date_index` | ref | 1 | 0.7 ms |
| Users of a district | `uj_district_end_idx` | ref | 262 | 0.6 ms |

**Result:**
- Every screen and job query uses an index. All of them take under 7 ms at this volume.
- At 1,000 rows the area reports read the whole table (section 5). At this volume they use the new district, block and cluster indexes.
- The load test (LOAD_TEST.md) found 0 slow queries and 0 lock waits with 100 officers at once.

**Index removed:** `answers_question_code_idx`.
- **No query uses it.** The wizard, report, PDF and Excel export all read answers through `inspection_id`, which the unique key serves.
- **It cost space and write time:** 17.5 MB of the 44 MB of answer indexes, plus an extra write on every auto-save.
- **Removed by** migration `2026_11_18_000007_drop_unused_answers_question_code_index`. Its `down()` adds the index back.
- **Side effect:** reading one question across all inspections now scans the table (about 1 s at 650,000 answers). No screen does this. Phase 2 reports should go through `inspections` (action plan 12).

**Checked and left as is:**
- My inspections list: about 40 rows per officer to sort, so an index on the sort order is not needed. One officer makes about 300 inspections a year.
- Name search (contains): 5 ms at 5,000 schools, an estimated 50 ms at 50,000. FULLTEXT stays the next step only if needed.
- Sessions: read by primary key.

## 9. V14 tables and the real School Master (25-11-2026)
**Setup:** MySQL `schoolinspection` with the real UDISE+ School Master (44,197 schools), 19 users, 11 notices and the V14 tables (notices, holidays, Today's Schools). The main pages were loaded in-process for a State Admin, a District, a Block and a Cluster officer (inside a rolled-back transaction). Every query was captured and `EXPLAIN`ed. The tables were analysed first.

**Found:**
| Query (screen) | Before | Problem |
|---|---|---|
| Active schools count (dashboard, Inspections in My Area) | 116 ms | full scan of `schools` (no index starts with `status`) |
| State-wide school suggestions (`status = active`, name contains, first 10 by name) | 88 ms | full scan + sort |
| Coverage: active schools per district / block / cluster | 94 ms | full scan + temporary table |
| My Schools / Find School, state-wide | 940 ms | two correlated sub-queries per school (count and last date of visits) for all 44,197 rows |
| Current posting of the user (`user_jurisdictions`) | 7–11 times per dashboard | the same look-up repeated per page |
| Coverage setting (`coverage_settings`) | 7–9 times per coverage page | the same look-up repeated |

**Done:**
- **Indexes** (migration `2026_11_25_000012_add_v14_performance_indexes`, skips existing ones):
  - `schools_status_deleted_name_idx (status, deleted_at, name)`: the counts read the index only; the suggestions walk the index in name order and stop after 10 matches.
  - `schools_status_deleted_area_idx (status, deleted_at, district_id, block_id, cluster_id)`: the coverage per-area counts read the index only.
- **One grouped join:** `SchoolController::listQuery()` joins one grouped table of visible visits (`school_id, count, max(date)`) instead of two sub-queries per school.
- **Request memo:** `App\Support\RequestMemo` keeps the current posting and the coverage setting for the rest of the request. The `UserJurisdiction` / `CoverageSetting` saved and deleted events forget them.
- **Maintenance:** `php artisan db:maintain` runs monthly (1st, 02:30, after `audit:purge` / `cache:purge`). It runs `ANALYZE TABLE` on 22 main tables, so the optimiser keeps choosing the right index as they grow, and `OPTIMIZE TABLE` on `audit_logs`, `user_login_logs` and `cache*`, which lose rows every month. About 0.4 s locally.
- **Slow query log:** a query slower than `SLOW_QUERY_MS` (default 1000 ms) is written to the Laravel log with the page path.

**After (same data):**
| Query (screen) | Before | After |
|---|---|---|
| Active schools count | 116 ms | 51 ms (index only) |
| School suggestions, state-wide | 88 ms | 19 ms |
| Coverage per area, state | 94 ms | 63 ms |
| My Schools, state-wide (first page) | 940 ms | 550 ms |
| Queries per dashboard (State Admin / officers) | 32 / 41–51 | 25 / 30–40 |
| Queries per coverage page | 19–23 | 13–16 |

**Checked and left as is:**
- Notices, notice reads, holidays, Today's Schools and schedule items are already served by their unique keys (`(school_id, list_date)`, `(officer_id, list_date, school_id)`, `(school_id, schedule_date)`, `(notice_id, user_id)`, `(user_id, notice_id)`) and are small. Re-check them after a year of use.
- `inspections.officer_level` (visibility) is filtered after the `(district|block|cluster)_id, status, inspection_date` index range; no extra index is needed.
- **The state-wide My Schools sort (44,000 rows):** this is the one query still above 0.5 s, and only for State-level users. A deferred join (sorting ids only) is the next step if it becomes a problem.
- **Name contains search:** still a scan (section 6); FULLTEXT only if needed.
## 10. School name search, queue tables and fewer queries (26-11-2026)
**FULLTEXT on `schools.name`** (migration `2026_11_26_000003`, `schools_name_ft`, MySQL / MariaDB only):
- MariaDB 10.4 has no ngram parser, so FULLTEXT matches **word starts** ("kokra" finds KOKRAJHAR; "okra" does not). The tokens are words of 3+ letters (`innodb_ft_min_token_size`), minus the InnoDB stopwords.
- `App\Support\SchoolSearch::live()` uses it for the live, limited searches: suggestions, Map View search and the planner list. Each word becomes `+word*`. When MATCH finds nothing (part of a word), or no word can be used (shorter than 3 letters, digits), the exact LIKE runs instead, so nothing is lost.
- Full lists and filters (Find School, My Schools, School Master, inspection lists) keep the exact LIKE, so their results never change.
- `SchoolSearch` also escapes `%` and `_` (they used to match anything).

| Query (44,197 schools, MariaDB 10.4) | LIKE | FULLTEXT |
|---|---|---|
| "kokra", 10 rows, ordered by name | 797 ms cold, ~25 ms warm | 6.6 ms |
| "govt lps" (count) | 50 ms | 19 ms (same 81 rows) |

**New tables:**
- `export_jobs` (index `(user_id, status, created_at)`, `created_at` for the purge).
- `import_batches.status` (index `(status, created_at)` for the purge and History). History now reads `whereIn('status', ['done', 'failed'])`.

**Fewer queries per request:**
- `MapDataService::chain()` is memoised: map schools JSON 27 → 21.
- `DailyDisplaySetting::forDistrict()` is memoised: Cluster dashboard 23 → 19.
- The six dashboard counts are cached for 60 s (`DashboardStats`).
- `QueryBudgetTest` keeps these numbers from growing again.
