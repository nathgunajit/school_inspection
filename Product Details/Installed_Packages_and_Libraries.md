# Installed Packages and Libraries: Use Cases

This report covers every package installed in the project: what it is used for, and where in the code it is used. 19-11-2026.

**Sources:**
- `composer.json` / `composer.lock` and `package.json` / `package-lock.json` (installed versions)
- `tools/docs/package.json`
- `php -m` (PHP extensions)
- `composer audit`, `npm audit`, `composer outdated --direct` and `npm outdated`, run on 19-11-2026

---

## 1. Summary

| Item | Count / result |
|---|---|
| PHP packages declared (direct) | 6 for production, 7 for development |
| PHP packages installed (incl. dependencies) | 129 (94 production, 35 development) |
| JavaScript packages declared (direct) | 14 (all `devDependencies`: they are bundled by Vite or used only at build time) |
| JavaScript packages installed (incl. dependencies) | 157 |
| Documentation builder (`tools/docs`) | 2 (`docx`, `marked`), separate from the application |
| Known vulnerabilities | **None.** `composer audit`: no advisories; `npm audit`: 0 vulnerabilities |
| Updates available | `laravel/framework` 12.72.0 → 12.73.0 (patch), `laravel/sail` 1.70 → 1.71, `laravel/tinker` 2.11 → 3.2 (major). All npm packages are on their latest version |
| Licences | All MIT / BSD, except `dompdf/dompdf` and `ezyang/htmlpurifier` (LGPL-2.1), `node-forge` (BSD-3 or GPL-2, BSD chosen) and `@fontsource-variable/inter` (OFL-1.1). All are compatible with a government web application |

---

## 2. Runtime environment

| Component | Version (local) | Use case |
|---|---|---|
| PHP | 8.2.12 (XAMPP, ZTS x64) | Runs Laravel; the project requires `^8.2` |
| MariaDB | 10.4.32 (XAMPP) | Database `schoolinspection`; production target MySQL 8 / MariaDB 10.6+ |
| Apache | XAMPP (or `php artisan serve --port=8010` locally) | Web server |
| Node.js | 20.17.0 | Build the front end only (Vite); not needed at run time on the server |
| SQLite (PHP `pdo_sqlite`) | built-in | In-memory database for the automated tests |

### 2.1 PHP extensions the packages need (all present locally)

| Extension | Needed by | Use case in this project |
|---|---|---|
| `pdo_mysql` | Laravel | Database connection |
| `pdo_sqlite` | Laravel (tests) | Tests on in-memory SQLite |
| `openssl` | Laravel, `PasswordCrypto` | Encryption, HTTPS, RSA decryption of password fields, encrypted URL IDs |
| `mbstring`, `ctype`, `tokenizer`, `filter`, `hash`, `session`, `pcre`, `json` | Laravel and most packages | Core framework |
| `fileinfo` | Flysystem, `EvidenceStorage` | Real MIME type of uploaded photos and documents |
| `gd` | `mews/captcha`, PhpSpreadsheet | Draws the CAPTCHA image; images in Excel |
| `zip`, `xml`, `xmlreader`, `xmlwriter`, `simplexml`, `libxml`, `zlib`, `iconv` | PhpSpreadsheet (Laravel Excel) | Reading and writing `.xlsx` files |
| `dom` | DomPDF, PhpSpreadsheet | HTML → PDF; spreadsheet XML |

---

## 3. PHP packages (Composer)

### 3.1 Production (`require`)

| Package | Installed | Licence | Use case | Where used |
|---|---|---|---|---|
| `laravel/framework` | 12.72.0 | MIT | The application framework. It provides routing, middleware, Eloquent ORM, Blade views, validation, authentication (session guard, bcrypt), authorization (gates, policies), the scheduler, the cache, sessions, the queue, mail (password reset), rate limiting, encryption and the file storage (`private` disk) | Whole application |
| `barryvdh/laravel-dompdf` | 3.1.2 (DomPDF 3.1.6) | MIT (DomPDF: LGPL-2.1) | Turns Blade HTML into **PDF** | `ReportController@pdf` (inspection report, view `inspections/pdf`), `RatingReportController@pdf` (view `reports/ratings/pdf`) |
| `maatwebsite/excel` | 3.1.70 (PhpSpreadsheet 1.30.8) | MIT | Excel **export and import** | **Export:** `ReportController@export` (My/Area inspections, one column per question code), `RatingReportController@export`, `Admin\LoginReportController@export`, through `App\Exports\ArrayExport`. **Import:** `Admin\ImportController` + `MasterImportService` (location and school masters, templates, error file) |
| `mews/captcha` | 3.5.1 (uses `intervention/image` 3.11) | MIT | **CAPTCHA** image and check on the login form after repeated failures (Modification V3) | `Auth\LoginController` (`captcha` rule), `partials/captcha.blade.php`, routes `/captcha/*`; switch `CAPTCHA_ENABLED` |
| `laravel/tinker` | 2.11.1 | MIT | Interactive console (`php artisan tinker`) for administrators and developers | Maintenance only; not used by the application code |
| `php` | ^8.2 | — | Language version constraint | — |

### 3.2 Development only (`require-dev`, not installed on production with `composer install --no-dev`)

| Package | Installed | Use case | Where used |
|---|---|---|---|
| `phpunit/phpunit` | 11.5.58 | Automated tests (`php artisan test`) | `tests/Feature/*` (20 files), `phpunit.xml` (SQLite in memory) |
| `fakerphp/faker` | 1.24.1 | Fake names, numbers, etc. for test and demo data | `database/factories/UserFactory.php`, several feature tests |
| `mockery/mockery` | 1.6.15 | Mock objects in tests | Installed by the Laravel skeleton; **not used** by the current tests |
| `laravel/pint` | 1.30.4 | PHP code formatter (PSR-12 / Laravel style) | `vendor/bin/pint` before commits |
| `laravel/pail` | 1.3.0 | Live log viewer in the terminal | `composer dev` script (`php artisan pail`) |
| `nunomaduro/collision` | 8.9.6 | Readable error output in the console and tests | Automatic in the CLI |
| `laravel/sail` | 1.70.0 | Docker development environment | **Not used** (the project runs on XAMPP) |

### 3.3 Important packages installed as dependencies (not declared directly)

| Package | Version | Brought by | What it does here |
|---|---|---|---|
| `dompdf/dompdf` | 3.1.6 | laravel-dompdf | The PDF engine |
| `phpoffice/phpspreadsheet` | 1.30.8 | Laravel Excel | Reads and writes `.xlsx` |
| `maennchen/zipstream-php` | 3.1.2 | PhpSpreadsheet | Streams the zipped `.xlsx` |
| `intervention/image` | 3.11.10 | mews/captcha | Draws the CAPTCHA picture (GD) |
| `ezyang/htmlpurifier` | 4.19.1 | PhpSpreadsheet | HTML cleaning inside spreadsheets |
| `nesbot/carbon` | 3.15.1 | Laravel | Dates: inspection date, draft expiry, financial years, schedules |
| `league/flysystem` | 3.36.0 | Laravel | File storage (`private` disk: evidence, photos, ID proofs, keys) |
| `symfony/*` | 7.4 | Laravel | HTTP foundation, console, mailer, mime |
| `guzzlehttp/guzzle` | 7.16.2 | Laravel | HTTP client. The application makes no outgoing HTTP calls today; the map tiles and the route are fetched by the browser |
| `monolog/monolog` | 3.13.0 | Laravel | Logging (`storage/logs`); the OTP log driver writes here |
| `vlucas/phpdotenv` | — | Laravel | Reads `.env` |

---

## 4. JavaScript and CSS packages (npm)

All of these are in `devDependencies`. The browser libraries are **bundled into `public/build` by Vite**, so the site needs no CDN at run time. The rest are build tools.

### 4.1 Bundled into the browser

| Package | Installed | Licence | Use case | Where used |
|---|---|---|---|---|
| `bootstrap` | 5.3.9 | MIT | UI framework: grid, cards, forms, buttons, modals, dropdowns, off-canvas sidebar, toasts; themed in SCSS with the Samagra Shiksha colours | `resources/scss/app.scss` (`@import 'bootstrap/scss/bootstrap'`), `resources/js/app.js`, `review.js` |
| `@popperjs/core` | 2.11.8 | MIT | Positions Bootstrap dropdowns and tooltips (account menu, sidebar tooltips) | Used internally by Bootstrap |
| `bootstrap-icons` | 1.13.2 | MIT | Icon font (`bi-*`): menu icons, step icons, status icons | `app.scss` + all Blade views |
| `axios` | 1.21.1 | MIT | AJAX with the CSRF token: autosave of answers and rows, photo upload, progress, map and planner JSON, session ping | `app.js` (sets `window.axios`), `wizard.js`, `camera.js`, `offline-queue.js`, `schedule-planner.js` |
| `leaflet` | 1.9.4 | BSD-2 | Interactive maps with OpenStreetMap tiles: Map View, school location, planner map, plan route, district outlines | `map.js`, `map-common.js`, `school-map.js`, `schedule-planner.js`, `schedule-route.js`, `leaflet-global.js` |
| `leaflet.markercluster` | 1.5.3 | MIT | Groups many school markers into clusters on the Map View | `map.js` (needs the global `L` from `leaflet-global.js`) |
| `sortablejs` | 1.15.7 | MIT | Drag-and-drop ordering of the schools in a day plan | `schedule-planner.js` |
| `node-forge` | 1.4.3 | BSD-3 / GPL-2 | RSA-OAEP encryption of password fields **when Web Crypto is unavailable** (plain HTTP on a LAN address). Loaded on demand only | `password-encrypt.js` (`import('node-forge')`) |
| `@fontsource-variable/inter` | 5.3.0 | OFL-1.1 | Inter web font. **Not imported anywhere**: the theme uses system fonts (Segoe UI, Noto Sans, Roboto). Can be removed | — |

### 4.2 Build tools

| Package | Installed | Use case |
|---|---|---|
| `vite` | 6.4.6 | Bundler and dev server (`npm run dev`, `npm run build`), 10 entry points (`vite.config.js`), targets Chrome/Edge/Firefox 100+ and Safari 15+ |
| `laravel-vite-plugin` | 1.3.0 | Connects Vite to Laravel (`@vite` in Blade, hot reload, `public/build/manifest.json`) |
| `sass` | 1.99.0 | Compiles `resources/scss/app.scss` |
| `autoprefixer` | 10.6.2 | Adds CSS vendor prefixes for the browsers in `browserslist` (`postcss.config.js`) |
| `concurrently` | 9.2.5 | Runs server, queue, logs and Vite together in the `composer dev` script |

### 4.3 Documentation builder (`tools/docs`, not part of the application)

| Package | Installed | Use case |
|---|---|---|
| `docx` | 9.16.0 | Writes the combined Word file `Product Details/School_Inspection_Product_Details.docx` |
| `marked` | 14.1.4 | Reads the Markdown documents for that Word file |

---

## 5. Browser features used (no package needed)

| Browser API | Use case | Where |
|---|---|---|
| `getUserMedia` (camera) | In-app camera for geo-stamped photos | `camera.js` |
| Canvas | Resize to ≤ 1600 px, JPEG, stamp strip | `camera.js` |
| Web Crypto (`crypto.subtle`) | SHA-256 of each photo; RSA-OAEP password encryption (secure context) | `camera.js`, `password-encrypt.js` |
| Geolocation | GPS at start, submit and for each photo; "My location" for directions; the planner start point | `schools/show.blade.php` (start), `review.js` (submit), `camera.js`, `map-common.js`, `schedule-planner.js`, `schedule-route.js` |
| IndexedDB | Offline queue of answers, rows and photos | `offline-queue.js` |
| `localStorage` | Sidebar collapsed state (`si-sidebar`) | `app.js` |

These need **HTTPS** (or localhost) for the camera, geolocation and Web Crypto.

## 6. External services and optional system tools

| Service / tool | Use case | Setting | Note |
|---|---|---|---|
| OpenStreetMap tiles | Map background | `MAP_TILE_URL` | Public tiles are for light use; self-host or contract for production |
| OSRM routing server | Road route from "My location" to a school (only when "Show route" is pressed) | `MAP_ROUTING_URL` | Public demo server for light use |
| ClamAV (`clamscan`) | Optional virus scan of uploaded evidence | `CLAMAV_BINARY` | Off unless configured |
| SMTP server | Password reset emails | `MAIL_*` | `log` driver locally |
| SMS gateway | Not used (OTP is Phase 2) | `OTP_DRIVER=log` | — |

---

## 7. Use case → package map

| Use case | Packages / APIs |
|---|---|
| Login, sessions, passwords, permissions | laravel/framework, mews/captcha (+ intervention/image), node-forge / Web Crypto |
| Registration uploads, evidence storage | laravel/framework (Flysystem), PHP `fileinfo`, optional ClamAV |
| Inspection wizard and autosave | Bootstrap, axios, IndexedDB |
| Photos | getUserMedia, Canvas, Web Crypto, Geolocation, axios |
| Reports as PDF | barryvdh/laravel-dompdf (dompdf) |
| Excel exports and master imports | maatwebsite/excel (PhpSpreadsheet) |
| Map View, directions, outlines | Leaflet, leaflet.markercluster, OSM tiles, OSRM |
| Schedule planner | SortableJS, Leaflet, axios |
| UI look and icons | Bootstrap, Popper, Bootstrap Icons, Sass, Autoprefixer |
| Build | Vite, laravel-vite-plugin |
| Tests and code quality | PHPUnit, Faker, Pint, Collision |
| Developer tools | Tinker, Pail, concurrently |
| Combined Word document | docx, marked (`tools/docs`) |

---

## 8. Recommendations

| # | Recommendation | Priority |
|---|---|---|
| 1 | Apply the Laravel patch update (12.72 → 12.73) with `composer update laravel/framework`, then run the tests | Medium |
| 2 | On production servers install with `composer install --no-dev --optimize-autoloader` and deploy only `public/build` (no `node_modules`) | High |
| 3 | Remove unused packages: `@fontsource-variable/inter` (npm), `laravel/sail` and `mockery/mockery` (dev) | Low |
| 4 | Plan `laravel/tinker` 3.x together with the next Laravel major upgrade (it is a developer tool only) | Low |
| 5 | Run `composer audit` and `npm audit` monthly, or in CI | Medium |
| 6 | Keep the DomPDF (LGPL) and node-forge (BSD option) licence notices in the release documentation | Low |
| 7 | Production map tiles and routing from a self-hosted or contracted provider | Medium |
| 8 | Enable ClamAV on production servers | Medium |
