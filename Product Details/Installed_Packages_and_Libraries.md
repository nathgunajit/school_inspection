# Installed Packages and Libraries: Use Cases

This report lists every package and library installed in the School Inspection Management System. For each one it gives the version, the licence, what it is used for and where the code uses it. Updated 25-11-2026; the previous version was dated 19-11-2026.

**Sources:**
- `composer.json` / `composer.lock` and `package.json` / `package-lock.json` (installed versions)
- `tools/docs/package.json`
- `php -v`, `php -m`, `node -v`, `mysql --version`
- `composer audit`, `npm audit`, `composer outdated --direct` and `npm outdated`, run on 25-11-2026
- A search of today's source code for each import or use

---

## 1. Summary

| Item | Count / result |
|---|---|
| PHP packages declared (direct) | 6 for production (including the `php` version), 7 for development |
| PHP packages installed (incl. dependencies) | 129 (94 production, 35 development) |
| JavaScript packages declared (direct) | 15: 14 `devDependencies` (bundled by Vite or build-time only) + `flatpickr` (`dependencies`) |
| JavaScript packages installed (incl. dependencies) | 158 |
| Documentation builder (`tools/docs`) | 2 (`docx`, `marked`), kept apart from the application |
| Known vulnerabilities | **None.** `composer audit` found no advisories, and `npm audit` found 0 vulnerabilities |
| Updates available | `laravel/framework` 12.72.0 → 12.74.0 (minor), `laravel/sail` 1.70 → 1.71, `laravel/tinker` 2.11 → 3.2 (major), `autoprefixer` 10.6.2 → 10.7.0, `node-forge` 1.4.3 → 1.4.4 (patch) |
| Licences | All MIT / BSD, except `dompdf/dompdf`, `dompdf/php-font-lib` and `ezyang/htmlpurifier` (LGPL-2.1), `node-forge` (BSD-3 or GPL-2, BSD chosen) and `@fontsource-variable/inter` (OFL-1.1). All are compatible with a government web application |

---

## 2. Runtime environment

| Component | Version (local) | Use case |
|---|---|---|
| PHP | 8.2.12 (XAMPP, ZTS x64) | Runs Laravel. The project requires `^8.2` |
| MariaDB | 10.4.32 (XAMPP) | Database `schoolinspection`. Production target: MySQL 8 / MariaDB 10.6+ |
| Apache | XAMPP (or `php artisan serve --port=8010` locally) | Web server |
| Node.js | 20.17.0 | Builds the front end only (Vite). Not needed on the server at run time |
| SQLite (PHP `pdo_sqlite`) | built-in | In-memory database for the automated tests |
| Redis (optional, phpredis) | — | Cache and sessions when the extension is loaded. Otherwise both fall back to files (`failover` cache store) |

### 2.1 PHP extensions the packages need (all present locally)

| Extension | Needed by | Use case in this project |
|---|---|---|
| `pdo_mysql` | Laravel | Database connection |
| `pdo_sqlite` | Laravel (tests) | Tests on in-memory SQLite |
| `openssl` | Laravel, `PasswordCrypto` | Encryption, HTTPS, RSA decryption of password fields, encrypted URL IDs |
| `mbstring`, `ctype`, `tokenizer`, `filter`, `hash`, `session`, `pcre`, `json` | Laravel and most packages | Core framework |
| `fileinfo` | Flysystem, `EvidenceStorage` | Real MIME type of uploaded photos, ID proofs, notice and schedule-order attachments |
| `gd` | `mews/captcha`, PhpSpreadsheet, `App\Support\PdfPhoto` | CAPTCHA image; Excel logos; fits inspection photos into a fixed 4:3 canvas for the PDF |
| `zip`, `xml`, `xmlreader`, `xmlwriter`, `simplexml`, `libxml`, `zlib`, `iconv` | PhpSpreadsheet (Laravel Excel) | Reading and writing `.xlsx` files |
| `dom` | DomPDF, PhpSpreadsheet | HTML → PDF; spreadsheet XML |
| `redis` (optional) | Laravel cache / session | Faster cache and sessions on production |

---

## 3. PHP packages (Composer)

### 3.1 Production (`require`)

| Package | Installed | Licence | Use case | Where used |
|---|---|---|---|---|
| `laravel/framework` | 12.72.0 | MIT | Most of what the application does runs on the framework: routing, middleware, Eloquent ORM, Blade views, validation, authentication (session guard, bcrypt), authorization (gates, policies), the scheduler (`audit:purge`, `cache:purge`, draft expiry), the cache (badge counts, holiday calendar, notices), sessions, the queue, mail (password reset), rate limiting, encryption and the file storage (`private` disk) | Whole application |
| `barryvdh/laravel-dompdf` | 3.1.2 (DomPDF 3.1.6) | MIT (DomPDF: LGPL-2.1) | Turns Blade HTML into **PDF** files | `ReportController` (inspection report `inspections/pdf`, Inspections mine / area PDF), `SchoolController` (blank paper form, My Schools PDF), `RatingReportController` (designed Rating Report), `CoverageReportController` + `CoverageReportService` (Coverage PDF), `NetworkIssueController` (Network issues PDF), `Admin\SchoolMasterController` and `Admin\LocationController` (master list PDFs). Most of these go through `App\Support\MasterPdf` (A4, official header, watermark, Page X of Y) |
| `maatwebsite/excel` | 3.1.70 (PhpSpreadsheet 1.30.8) | MIT | Excel **export and import** | **Export:** `App\Exports\ArrayExport` (Coverage, Rating, Login report, Location Master), `MySchoolsExport` (protected My Schools sheet with official header and logos), `SchoolListExport` (School Master in upload order), `SchoolTemplateExport` (district template + clusters sheet), `HolidayTemplateExport` / `HolidaySheet` (holiday template and the filled Assam 2026 list), `AuditArchiveExport` (audit archive before purge). **Import:** `Admin\ImportController` + `MasterImportService` (Location and School Master, UDISE+ file), `HolidayController` (holiday import) |
| `mews/captcha` | 3.5.1 (uses `intervention/image` 3.11) | MIT | **Image CAPTCHA** on the login form after repeated failures, and on registration (switch `CAPTCHA_ENABLED`) | `Auth\LoginController` (`captcha` rule), `RegisterRequest`, `partials/captcha.blade.php`, routes `/captcha/*`. The math CAPTCHA asked at every login is the app's own `App\Support\MathCaptcha` (SVG) and needs no package |
| `laravel/tinker` | 2.11.1 (PsySH 0.12) | MIT | Interactive console (`php artisan tinker`) for administrators and developers | Maintenance only. The application code does not use it |
| `php` | ^8.2 | — | Language version constraint | — |

### 3.2 Development only (`require-dev`; `composer install --no-dev` leaves these off production servers)

| Package | Installed | Use case | Where used |
|---|---|---|---|
| `phpunit/phpunit` | 11.5.58 | Automated tests (`php artisan test`) | `tests/Feature/*` (50 files, about 285 tests), `phpunit.xml` (SQLite in memory) |
| `fakerphp/faker` | 1.24.1 | Fake names, numbers and similar values for test and demo data | `database/factories/UserFactory.php`, several feature tests |
| `mockery/mockery` | 1.6.15 | Mock objects in tests | Installed by the Laravel skeleton. **The current tests do not use it** |
| `laravel/pint` | 1.30.4 | PHP code formatter (PSR-12 / Laravel style) | `vendor/bin/pint` before commits |
| `laravel/pail` | 1.3.0 | Live log viewer in the terminal | `composer dev` script (`php artisan pail`) |
| `nunomaduro/collision` | 8.9.6 | Readable error output in the console and tests | Automatic in the CLI |
| `laravel/sail` | 1.70.0 | Docker development environment | **Not used** (the project runs on XAMPP) |

### 3.3 Important packages installed as dependencies (not declared directly)

| Package | Version | Licence | Brought by | What it does here |
|---|---|---|---|---|
| `dompdf/dompdf` | 3.1.6 | LGPL-2.1 | laravel-dompdf | The PDF engine |
| `dompdf/php-font-lib`, `sabberworm/php-css-parser`, `masterminds/html5` | 1.0.2 / 9.5.0 / 2.12.0 | LGPL / MIT | DomPDF | Fonts, CSS and HTML5 parsing for the PDFs |
| `phpoffice/phpspreadsheet` | 1.30.8 | MIT | Laravel Excel | Reads and writes `.xlsx`; sheet protection, logos, page setup |
| `maennchen/zipstream-php` | 3.1.2 | MIT | PhpSpreadsheet | Streams the zipped `.xlsx` |
| `ezyang/htmlpurifier` | 4.19.1 | LGPL-2.1 | PhpSpreadsheet | HTML cleaning inside spreadsheets |
| `intervention/image` | 3.11.10 | MIT | mews/captcha | Draws the CAPTCHA picture (GD) |
| `nesbot/carbon` | 3.15.1 | MIT | Laravel | Dates: inspection date, draft expiry, financial years, schedules, coverage periods, holidays, Today's Schools |
| `league/flysystem` | 3.36.0 | MIT | Laravel | File storage (`private` disk: evidence, employee photos, ID proofs, notice attachments, schedule orders, audit archives, keys) |
| `symfony/*` | 7.4 | MIT | Laravel | HTTP foundation, console, mailer, mime |
| `guzzlehttp/guzzle` | 7.16.2 | MIT | Laravel | HTTP client. The application makes no outgoing HTTP calls today: the browser fetches the map tiles and routes |
| `monolog/monolog` | 3.13.0 | MIT | Laravel | Logging (`storage/logs`). The OTP log driver writes here |
| `vlucas/phpdotenv` | 5.8.0 | BSD-3 | Laravel | Reads `.env` |
| `psy/psysh` | 0.12.24 | MIT | Tinker | The Tinker console |

---

## 4. JavaScript and CSS packages (npm)

Vite **bundles the browser libraries into `public/build`**, so the site needs no CDN at run time. The other packages are build tools. There are 10 Vite entry points (`vite.config.js`): `app.scss`, `app.js`, `wizard.js`, `camera.js`, `register.js`, `review.js`, `map.js`, `schedule-planner.js`, `schedule-route.js` and `school-map.js`.

### 4.1 Bundled into the browser

| Package | Installed | Licence | Use case | Where used |
|---|---|---|---|---|
| `bootstrap` | 5.3.9 | MIT | UI framework: grid, cards, forms, buttons, modals (Edit windows), dropdowns, off-canvas sidebar, toasts. Themed in SCSS with the Samagra Shiksha colours | `resources/scss/app.scss` (`@import 'bootstrap/scss/bootstrap'`), `app.js`, `review.js` |
| `@popperjs/core` | 2.11.8 | MIT | Positions Bootstrap dropdowns and tooltips (account menu, three-dot menus) | Used internally by Bootstrap |
| `bootstrap-icons` | 1.13.2 | MIT | Icon font (`bi-*`): menu, step, status, office and holiday icons | `app.scss` and all Blade views |
| `axios` | 1.21.1 | MIT | AJAX with the CSRF token: autosave of answers and rows, photo upload, map and planner JSON, school suggestions, session ping | `app.js` (sets `window.axios`), `wizard.js`, `camera.js`, `offline-queue.js`, `schedule-planner.js` |
| `flatpickr` | 4.6.13 | MIT | Date picker for every date box: inspection and report filters, coverage custom period, holidays, notices expiry, schedules, Today's Schools assignment. From / To boxes limit each other (To cannot be before From) | `resources/js/date-picker.js` (imported by `app.js`), with `flatpickr.css` |
| `leaflet` | 1.9.4 | BSD-2 | Interactive maps on OpenStreetMap tiles: Map View, school location, Plan Inspections map, plan route, district outlines, District HQ / Block office markers, directions | `map.js`, `map-common.js`, `school-map.js`, `schedule-planner.js`, `schedule-route.js`, `leaflet-global.js` |
| `leaflet.markercluster` | 1.5.3 | MIT | Groups many school markers into counted school icons on the Map View; clustering stops at zoom 16 | `map.js` (needs the global `L` from `leaflet-global.js`) |
| `sortablejs` | 1.15.7 | MIT | Drag-and-drop ordering of the schools in a day plan | `schedule-planner.js` |
| `node-forge` | 1.4.3 | BSD-3 / GPL-2 | RSA-OAEP encryption of password fields **when Web Crypto is unavailable** (plain HTTP on a LAN address). Loaded only when needed | `password-encrypt.js` (`import('node-forge')`) |
| `@fontsource-variable/inter` | 5.3.0 | OFL-1.1 | Inter web font. **Nothing imports it**: the theme uses system fonts (Segoe UI, Noto Sans, Roboto). It can be removed | — |

### 4.2 Build tools

| Package | Installed | Use case |
|---|---|---|
| `vite` | 6.4.6 | Bundler and dev server (`npm run dev`, `npm run build`). Targets Chrome / Edge / Firefox 100+ and Safari 15+ (`browserslist`) |
| `laravel-vite-plugin` | 1.3.0 | Connects Vite to Laravel (`@vite` in Blade, hot reload, `public/build/manifest.json`) |
| `sass` | 1.99.0 | Compiles `resources/scss/app.scss` (theme, field sizes, dashboard, map, holiday chips) |
| `autoprefixer` | 10.6.2 | Adds CSS vendor prefixes for the browsers in `browserslist` (`postcss.config.js`) |
| `concurrently` | 9.2.5 | Runs server, queue, logs and Vite together in the `composer dev` script |

### 4.3 Documentation builder (`tools/docs`, not part of the application)

| Package | Installed | Use case |
|---|---|---|
| `docx` | 9.16.0 | Writes the Word files `Product Details/School_Inspection_Product_Details.docx` and this report as `Installed_Packages_and_Libraries.docx` |
| `marked` | 14.1.4 | Reads the Markdown documents for those Word files |

---

## 5. Browser features used (no package needed)

| Browser API | Use case | Where |
|---|---|---|
| `getUserMedia` (camera) | In-app camera for geo-stamped photos | `camera.js` |
| Canvas | Resizes to ≤ 1600 px, saves as JPEG, adds the stamp strip | `camera.js` |
| Web Crypto (`crypto.subtle`) | SHA-256 of each photo; RSA-OAEP password encryption (secure context) | `camera.js`, `password-encrypt.js` |
| Geolocation | GPS at start, at submit and for each photo; "My location" for directions; geo-capturing Start form on Today's Schools | `schools/show.blade.php`, `review.js`, `camera.js`, `map-common.js`, `schedule-planner.js`, `schedule-route.js`, `app.js` (`form[data-geo-start]`) |
| IndexedDB | Offline queue of answers, rows and photos | `offline-queue.js` |
| `fetch` + History API | Section loading of filter results without a full page reload | `filter-partial.js` |
| Network Information / online events | Slow / offline / back-online banner | `network-status.js` |
| `localStorage` | Sidebar open state (`si-sidebar`) | `app.js` |

The camera, geolocation and Web Crypto need **HTTPS** (or localhost).

## 6. External services and optional system tools

| Service / tool | Use case | Setting | Note |
|---|---|---|---|
| OpenStreetMap tiles | Map background | `MAP_TILE_URL` | Public tiles are for light use. Self-host or contract a provider for production |
| OSRM routing server | Road route from "My location" to a school, fetched only when "Show route" is pressed | `MAP_ROUTING_URL` | Public demo server, for light use only |
| Google Maps (link only) | "Directions" opens Google Maps in a new tab. Nothing is sent from the server | — | No API key |
| ClamAV (`clamscan`) | Optional virus scan of uploaded evidence | `CLAMAV_BINARY` | Off unless configured |
| SMTP server | Password reset emails | `MAIL_*` | `log` driver locally |
| SMS gateway | Not used (OTP is Phase 2) | `OTP_DRIVER=log` | — |

---

## 7. Use case → package map

| Use case | Packages / APIs |
|---|---|
| Login, sessions, passwords, permissions | laravel/framework, mews/captcha (+ intervention/image), node-forge / Web Crypto |
| Registration uploads, evidence, attachments | laravel/framework (Flysystem), PHP `fileinfo`, optional ClamAV |
| Inspection wizard and autosave | Bootstrap, axios, IndexedDB |
| Photos | getUserMedia, Canvas, Web Crypto, Geolocation, axios; PHP `gd` for the PDF photo canvas |
| All PDF reports and lists | barryvdh/laravel-dompdf (dompdf), `App\Support\MasterPdf` |
| Excel exports, templates and master / holiday imports | maatwebsite/excel (PhpSpreadsheet) |
| Map View, offices, directions, outlines | Leaflet, leaflet.markercluster, OSM tiles, OSRM |
| Schedule planner | SortableJS, Leaflet, axios |
| Date boxes (filters, holidays, notices, schedules) | flatpickr |
| Holidays, notices, Today's Schools | laravel/framework (Eloquent, cache, scheduler), Carbon, flatpickr, maatwebsite/excel (holiday template) |
| UI look and icons | Bootstrap, Popper, Bootstrap Icons, Sass, Autoprefixer |
| Build | Vite, laravel-vite-plugin |
| Tests and code quality | PHPUnit, Faker, Pint, Collision |
| Developer tools | Tinker, Pail, concurrently |
| Word documents | docx, marked (`tools/docs`) |

---

## 8. Recommendations

| # | Recommendation | Priority |
|---|---|---|
| 1 | Update Laravel 12.72 → 12.74 with `composer update laravel/framework`, then run the tests | Medium |
| 2 | Apply the npm patch updates (`autoprefixer`, `node-forge`) with `npm update`, then `npm run build` | Low |
| 3 | On production servers install with `composer install --no-dev --optimize-autoloader` and deploy only `public/build` (no `node_modules`) | High |
| 4 | Remove the unused packages: `@fontsource-variable/inter` (npm), and `laravel/sail` and `mockery/mockery` (dev) | Low |
| 5 | Plan `laravel/tinker` 3.x together with the next Laravel major upgrade (it is a developer tool only) | Low |
| 6 | Run `composer audit` and `npm audit` monthly, or in CI | Medium |
| 7 | Keep the DomPDF / HTMLPurifier (LGPL) and node-forge (BSD option) licence notices in the release documentation | Low |
| 8 | Use self-hosted or contracted map tiles and routing on production | Medium |
| 9 | Enable ClamAV and the phpredis extension on production servers | Medium |
