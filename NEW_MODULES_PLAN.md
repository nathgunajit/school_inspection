# New Module development plan

Source: `Documents/Modification of the Application New Module.docx` (Map View, Inspection Scheduling and Automatic Rating System). Date: 18-11-2026.

Delivery is one module at a time. Each module is committed and pushed on its own, with an ACTION.md entry:

| # | Module | ACTION | Status |
|---|---|---|---|
| 0 | Development plan (this document) | #65 | Done |
| 1 | Interactive Map View | #66 | Done |
| 2 | Map-based Inspection Scheduling (drag and drop) | #67 | Done |
| 3 | Automatic Inspection Rating | #68 | Done |

## V6 modules (Modification of the Application V6)

Source: `Documents/Modification of the ApplicationV6.docx`. Date: 19-11-2026. The same delivery rule applies: one module at a time, each committed and pushed, each with an ACTION.md entry.

| # | Module | V6 item | Decision | Status |
|---|---|---|---|---|
| 4 | URL encryption | Security for URL Encryption | Record IDs in URLs become deterministic, tamper-proof AES tokens (`App\Support\UrlId`, trait `HasEncryptedRouteKey`); a numeric or edited ID returns 404 | Done (#70) |
| 5 | Password encryption on submit | Password When Submitted Automatically Encrypted | The browser encrypts password fields with RSA-OAEP (WebCrypto, `node-forge` fallback on plain HTTP) plus a session nonce; `DecryptPasswordFields` middleware decrypts before validation; storage stays bcrypt | Done (#71) |
| 6 | Login monitoring report | Login User Monitoring Report (State Administrator) | `user_login_logs` table (login, logout, last seen, device, IP, failures); `/admin/login-report` with tiles, filters, per-user history, Excel | Done (#72) |
| 7 | District boundary from KML | Use District KML for District | Per-district GeoJSON from `public/KML/Assam/Assam Dist`; District, Block and Cluster officers see their own district outlined and zoomed; `map:boundaries --check` lists unmatched districts | Planned |
| 8 | My location, distance and road route | Showing My Current Location in Map and distance from the school if selected and road view | GPS "My location" control; straight-line and road distance and drive time with the route drawn (OSRM, configurable); "Open in Google Maps" link; on `/map` and the school page | Planned |
| 9 | Rating shown for the school | Rating Display the School | Latest rating badge and trend on the school list and page; map markers can be coloured by rating band; average rating per area | Planned |
| 10 | Rating report | Rating Report | `/reports/ratings`: summary, band distribution, area drill-down, section averages, school table, Excel and PDF, scoped by jurisdiction | Planned |


## Context
The new document asks for three modules on top of the existing Laravel/MySQL app:
1. **Interactive Map View.** State → District → Block → Cluster → School drill-down, officer counts, coverage, coloured status markers, filters and popups.
2. **Map-based Inspection Scheduling.** Daily, weekly and monthly plans, built by drag-and-drop. Includes visit order, distances and route optimisation, conflict checks, assign/reassign by District and Block officers, approval and publishing, and history.
3. **Automatic Inspection Rating.** Weighted scores per question, section and overall. Not Applicable answers are left out. Includes rating bands with colours, strengths and weaknesses, history per school, recalculation after authorised changes, and an admin screen to configure scoring.

General requirements:
- OpenStreetMap + Leaflet.js
- role-based access by hierarchy (`JurisdictionService`)
- mobile-friendly, and light on slow networks
- audit logs
- **no existing data or behaviour changed** (only additive migrations)

The user asked for a module development plan first. After that, each module is built **one by one**. Every module is **committed and pushed** with a descriptive message and gets an **ACTION.md entry** (the next number is #65).

Facts found:
- `schools`, `districts` and `blocks` already have `latitude`/`longitude`. `clusters` does not.
- In the local data, 16 of 24 schools have coordinates. Districts and blocks have none.
- Leaflet is not installed yet.
- `Inspection::haversine()` already exists.
- Unlocking goes through `InspectionController::unlock`, and resubmit goes through `submit`.
- CLAUDE.md lists "scoring" as Phase 2 / out of scope. This document now explicitly asks for it, so CLAUDE.md will be updated.

---

## Module 0: Development plan document (commit 1, ACTION #65)
- `NEW_MODULES_PLAN.md`: this plan in document form. It covers scope, data model, screens, access rules, workflow, test plan and the delivery order.
- Commit it together with `Documents/Modification of the Application New Module.docx` and the modified `…V5.docx`.
- Commit message: "New module development plan: map view, inspection scheduling and automatic rating".

## Module 1: Interactive Map View (commit 2, ACTION #66)
**Packages:**
- `leaflet` and `leaflet.markercluster` through npm, bundled by Vite into `resources/js/map.js`, so no CDN is needed.
- Tile URL and attribution in `config/inspection.php` → `'map' => ['tiles' => env('MAP_TILE_URL', OSM default), 'center' => Assam, 'zoom' => 7]`.

**Migration:**
- Add `latitude`/`longitude` to `clusters`.
- Any area with no coordinates falls back to the average of its schools' coordinates (computed in the service).

**Demo data:**
- Coordinates for the demo districts, blocks and clusters.
- Fill in the missing demo school coordinates (in `DemoUserSeeder`, non-production only).

**District boundaries:**
- `public/geo/assam-districts.geojson` is used when the file is present, matched on district code or name. Otherwise districts are drawn as centroid markers.
- No boundary file is shipped, because I can't source authoritative data here.

**`app/Services/MapDataService.php`:** every query goes through `JurisdictionService`.
- `areas(user, level, parentId, filters)`: one row per district, block or cluster. Columns: id, name, lat/lng, schools, inspected, in progress, not inspected, coverage %, officers.
- `schools(user, clusterId, filters)`: compact rows. Columns: id, UDISE, name, lat/lng, status, last inspection date, inspection no., officer.
- Status in the period (default: this month; from/to filter):
  - green: submitted
  - orange: draft or unlocked
  - red: not inspected
  - grey: no GPS (counted, listed separately)
- Officer counts come from active users with a current `user_jurisdictions` row, grouped by area. Filter by officer: only schools that officer inspected.
- Short cache (60 s) keyed by user scope and filters.

**`MapController`:**
- `GET /map` (page)
- `GET /map/areas/{level}` (JSON)
- `GET /map/schools` (JSON)
- `GET /map/officers` (officer list, for the filter and the tables)
- Middleware `can:view_reports`.
- A parent ID outside the user's jurisdiction returns **403**.
- The starting level is the user's own jurisdiction. For example, a Block officer starts at their block.

**UI (`resources/views/map/index.blade.php`):**
- Stat cards: officers, schools, inspected, pending, coverage %.
- Filter panel (an offcanvas on phones): District / Block / Cluster / school search / officer / date range.
- Breadcrumb for drill-down (click a district → its blocks → its clusters → its schools), with a legend.
- Marker popup:
  - for a school: details, last inspection, a link to the report or school page
  - for an area: counts
- Tables below the map: officers by district, block and cluster.
- A "Map View" sidebar menu item.

**Tests (`MapViewTest`):**
- counts and status colours are correct
- a block officer gets 403 or no rows for another block
- the officer filter works
- the officer counts are correct

## Module 2: Map-based Inspection Scheduling (commit 3, ACTION #67)
**Migration (additive):**
- `inspection_schedules`
  - columns: id, officer_id, schedule_date, status, created_by, approved_by, approved_at, review_note, total_distance_km, notes, timestamps
  - status values: draft, submitted, approved, rejected, published, completed, cancelled
  - unique key: officer + date
- `inspection_schedule_items`
  - columns: schedule_id, school_id, schedule_date (denormalised), sequence, distance_from_prev_km, eta (estimated time), status (planned, inspected, skipped), inspection_id
  - unique keys: schedule + school, and **school + date**. The second is the database guard against two officers getting the same school on the same day.
  - Items of a cancelled schedule are deleted.

**`app/Services/ScheduleService.php`:**
- Rules for each school:
  - it is inside the officer's jurisdiction (`JurisdictionService::coversSchool`)
  - the date is not in the past
  - the school is not scheduled for anyone else that day
  - the school is not already submitted this month by another officer (reuse the `InspectionStartGuard` checks)
  - there is a maximum per day (`config('inspection.schedule.max_per_day', 8)`)
- Clear conflict messages.
- Distances use `Inspection::haversine` × a road factor (1.3, configurable) to give estimated km. The ETA uses an average speed (25 km/h, configurable) plus the visit time (60 min, configurable).
- **Route optimisation:** nearest-neighbour plus 2-opt. It starts from the officer's start point (current GPS if they allow it, otherwise their cluster or block centre). The same algorithm runs in JS (a live preview) and in PHP (the authoritative version, on save).
- **No external routing API**, so no data leaves the server and it works on slow links.
  - "Directions" opens the visit sequence in the phone's own maps app.
  - A real-road routing server (OSRM) can be added later.

**Workflow and access:**
- An officer plans their own day: draft → submit.
  - When `schedule.approval_required` is true (the default), any higher-level officer whose jurisdiction covers them approves or rejects it, with a note. The check uses `JurisdictionService::contains`.
  - It is then published.
  - When approval is off, it is published directly.
- State, District and Block officers (and the State Admin) can **assign or reassign** schedules for officers below them in their area. Such a schedule is published at once.
  - Reassigning moves the items to another officer, rechecking the rules.
- Officers cannot assign schedules to others.
- Starting an inspection from a published item uses the existing `inspections.start`. On `submit`, today's matching schedule item of that officer is marked *inspected*. When all items are done, the schedule becomes *completed*.
- **History:** every change is written with `AuditLogger::log('schedule_*')` and shown as a timeline on the schedule page.

**UI:**
- `/schedules`: Day / Week / Month views (Bootstrap grid, no heavy calendar library), with status badges and a filter by officer for superiors.
- `/schedules/plan?date=&officer=`: the planner.
  - Leaflet map of the schools the officer may inspect (reuses the `map.js` helpers). Markers are coloured by inspected or not this month.
  - An "Available schools" list sorted by distance.
  - A "Day plan" list, with drag-and-drop between the two lists and reordering inside the plan. This uses **SortableJS** (npm), which works with touch on phones. Clicking a marker → "Add to plan".
  - The route polyline and numbered markers, km and ETA per stop and in total, and an "Optimise route" button.
  - Buttons: Save draft / Submit / Publish.
- `/schedules/approvals`: the approver's queue.
- "Schedules" sidebar item, plus a "Today's plan" card on the dashboard.

**Tests (`InspectionScheduleTest`):**
- create and save a plan
- a duplicate school for another officer on the same date is rejected
- a school outside the jurisdiction is rejected
- a past date is rejected
- the maximum per day is enforced
- the submit → approve → publish flow works, and rejection works
- a block officer can assign to a cluster officer, but a cluster officer cannot assign to others
- reassigning works
- submitting an inspection marks the item inspected and completes the schedule
- the optimiser order is right on a fixed set of points

## Module 3: Automatic Inspection Rating (commit 4, ACTION #68)
**Migration (additive):**
- `rating_schemes`: id, name, version, is_active, bands JSON, created_by, activated_at.
- `rating_criteria`
  - columns: scheme_id, section (step key), code, label, kind, weight, points JSON (value → 0..1)
  - `kind` is `answer` (a question code) or `metric` (a derived ratio)
- `inspection_ratings`
  - columns: inspection_id (unique), scheme_id, obtained, applicable, score_percent, band label/colour, sections JSON, strengths JSON, weaknesses JSON, calculated_at
  - one row per inspection, so a school's history is all its rated inspections

**Default scheme (seeded, version 1):**

| Section | What is scored |
|---|---|
| Condition | CND yes/no facilities: yes = 1, no = 0. CND_01: Pucca 1, Semi-pucca 0.5, Others 0. CND_12 "ACR required" is inverted. CND_18 and CND_20 are N/A when the parent is No. CND_26 "na" → N/A. CND_25 is not scored |
| Strength | **Metrics:** student attendance = TSI_01/(TSI_01+TSI_02); teacher attendance = TSI_09/total teachers; trained teachers = (TSI_10+TSI_12)/(TSI_10..13) |
| Absentee | Share of absences with a recognised reason (N/A when there are no absentees) |
| Classroom | Average of TLM used (yes = 1) and participation level per observation (N/A when there are none) |
| Fund | Utilisation = expenditure ÷ received over all heads, capped at 100 % (N/A when nothing was received) |
| Governance | Yes/no and satisfactory/unsatisfactory items. GOVR_01: non-conventional 1, conventional 0.5. GOVR_04 is N/A when GOVR_02 = No. Reason text boxes are not scored |
| Photos | Not scored |

- Weights default to 1. **Bands:** Excellent 90+ green, Very Good 75–89 blue, Good 60–74 yellow, Needs Improvement 40–59 orange, Unsatisfactory below 40 red.

**`app/Rating/RatingCalculator.php`:** a pure function of the answers and rows, for unit testing.
- **Score % = Σ(weight × points) ÷ Σ(weight of applicable criteria) × 100.** The same formula gives each section score.
- N/A covers an "na" answer, a question hidden or disabled by `show_if`/`enable_if`, or a metric with a zero denominator. N/A is left out of the denominator.
- A section with an applicable weight of 0 is shown as "N/A". An inspection with nothing applicable is "Not rated".
- Strengths are criteria at full points (the top ones per section). Weaknesses and areas requiring improvement are criteria at 0, plus any section under 60 %.

**`RatingService::rate()`:**
- Called inside `InspectionController::submit`, which covers the first submit and the resubmit after a State Admin unlock. So the rating updates after authorised changes.
- The old and new score are written to the audit log (`inspection_rated`).
- Legacy questionnaire v1 inspections are "not rated".
- Command `php artisan ratings:recalculate [--all]` back-fills the existing submitted inspections.

**Display:**
- Report page:
  - a coloured overall badge and score
  - section bars in band colours
  - the strengths, weaknesses and "areas requiring improvement" lists
  - a "School performance summary" paragraph
- PDF: the same summary block.
- School page: a rating history table with change arrows, to compare over time.
- Area inspections list: a rating column.

**Admin config (`/admin/rating`, `can:manage_masters`, meaning State Admin):**
- Edit the weights and points of each criterion, and the bands. Validation: bands contiguous from 0 to 100, weights ≥ 0, points between 0 and 1.
- Saving creates **a new scheme version**. Old ratings keep the scheme they were calculated with.
- An optional "Recalculate all submitted inspections with this version" button.
- Everything is audit-logged.

**Tests (`InspectionRatingTest`):**
- the formula
- N/A left out of the denominator
- the band boundaries (89.99 → Very Good, 90 → Excellent)
- the metrics' zero denominators
- rated on submit
- recalculated after unlock and resubmit
- a new version on admin save, with old ratings unchanged
- non-admins get 403 on the config screen
- the recalculate command

---

## Rules applied to every module
- **Access:** all data goes through `JurisdictionService`, and policies or gates are added for each new route. Users can never see or plan outside their area.
- **Mobile:** touch targets ≥ 44 px, filters in an offcanvas on phones, map height adapts.
- **Slow networks:**
  - load one level at a time (lazy drill-down)
  - compact JSON
  - marker clustering
  - Leaflet bundled locally
  - cached responses
- Theme colours and components are reused (`x-stat-card`, `x-status-badge`, `x-empty-state`).
- **Existing data:** additive migrations only, and no change to existing tables' data. Their `down()` methods drop only the new tables and columns.
- CLAUDE.md and PROJECT_DOCUMENT.md are updated in each module commit (layout, domain rules, new tables).

## Steps for each module
1. Build the module.
2. Run `npm run build`.
3. Run `php artisan migrate` (local DB; additive).
4. Run `php artisan config:clear`, then `php artisan test`. All tests must pass.
5. Check the page in headless Chrome on the Apache URL.
6. Add the ACTION.md entry.
7. Commit with a descriptive message (Co-Authored-By line), then `git push origin main`.
8. Report to the user.

## Verification
- **Map:**
  - log in as the demo District officer (9000000011): only North District is visible
  - the drill-down reaches schools, and markers are coloured by status
  - the counts match a SQL count
- **Schedule:** as Arjun Saikia (9000000031):
  - drag 3 schools into tomorrow's plan, optimise and submit
  - approve it as the Block officer Sanjay Das (9000000021)
  - start and submit an inspection, and the item shows *inspected*
  - try a duplicate assignment as another officer, and it is blocked
- **Rating:**
  - submit an inspection, and the report shows the score, band, section bars and strengths/weaknesses
  - unlock as the State Admin and change answers, then resubmit, and the score changes and the audit log has old and new
  - edit the weights in the admin screen, and version 2 is created
