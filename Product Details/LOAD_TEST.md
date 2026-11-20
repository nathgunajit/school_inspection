# Load test report

Modification of the Application V5, Step 2: "Load test with the expected number of users" and "how many users can log in and fill the inspection form". Date: 18-11-2026.

## 1. Test setup
| Item | Value |
|---|---|
| Machine | Developer laptop: Intel Core i5-1235U (12 threads), 16 GB RAM, Windows 11 |
| Web server | XAMPP Apache 2.4.58 (mpm_winnt, 150 threads), PHP 8.2.12 as an Apache module, **OPcache off** (XAMPP default) |
| Database | MariaDB 10.4.32, `max_connections` 151, `innodb_buffer_pool_size` **16 MB** (XAMPP default) |
| App | Laravel 12. Sessions, cache and queue on the database. Bcrypt with 12 rounds |
| Data | `LoadTestSeeder`: 500 officers, 5,000 schools, 10,000 submitted inspections, 650,000 answers (99 MB database) |
| Driver | `tools/loadtest/loadtest.php`. Every virtual officer has its own cookies and CSRF token. All of them start **at the same instant**, with no pause between requests, so this is the worst case |

**Scenarios**
- **Login:** `GET /login`, `POST /login`, `GET /dashboard` (3 requests per officer).
- **Inspection form:** log in, then:
  - start or reopen an inspection of the officer's own school
  - open step 1
  - **10 auto-saves** (`POST /answers`, like the wizard)
  - open step 2, then read `/progress`

  That makes 17 requests per officer.

## 2. Results

### Run 1: the app as it is now (APP_DEBUG on, nothing cached)
| Scenario | Users at once | Req/s | Median | P95 | Errors |
|---|---|---|---|---|---|
| Login | 10 | 13.6 | 0.62 s | 0.98 s | 0 |
| Login | 25 | 16.2 | 1.28 s | 2.36 s | 0 |
| Login | 50 | 16.4 | 2.59 s | 4.94 s | 0 |
| Form | 10 | 16.6 | 0.47 s | 0.94 s | **2 %** (HTTP 500 and 419) |

The run stopped on errors. See finding 1.

### Run 2: production-like (APP_DEBUG off; config, routes, views and events cached; views warmed up)
| Scenario | Users at once | Requests | Req/s | Median | P95 | Max | Errors | DB connections |
|---|---|---|---|---|---|---|---|---|
| Login | 10 | 30 | 15.2 | 0.49 s | 0.94 s | 0.95 s | 0 | 11 |
| Login | 25 | 75 | 18.0 | 1.06 s | 2.09 s | 2.13 s | 0 | 26 |
| Login | 50 | 150 | 17.8 | 2.18 s | 4.56 s | 4.64 s | 0 | 51 |
| Login | 100 | 300 | 18.2 | 4.81 s | 8.95 s | 9.79 s | 0 | 98 |
| Form | 10 | 170 | 20.9 | 0.40 s | 0.89 s | 0.94 s | 0 | 11 |
| Form | 25 | 425 | 19.6 | 1.13 s | 1.99 s | 2.12 s | 0 | 26 |
| Form | 50 | 850 | 16.4 | 2.62 s | 4.22 s | 5.47 s | 0 | 51 |
| Form | 100 | 1,700 | 17.2 | 5.06 s | 8.64 s | 14.2 s | 0 | 102 |

**Time per request with 10 users** (median):
| Request | Median |
|---|---|
| Login page | 0.43 s |
| Login (includes the bcrypt check) | 0.92 s |
| Dashboard | 0.46 s |
| Start inspection | 0.42 s |
| Step 1 | 0.49 s |
| Auto-save one answer | 0.39 s |
| Step 2 | 0.45 s |
| Progress | 0.41 s |

**Database during the test:**
- 0 slow queries and 0 lock problems.
- 99.7 % of reads came from the buffer pool.
- One connection per busy request, at most 102 of 151.

**Cross-check with `ab`:**
- The login page with 1 user takes 0.40 s per request (2.5 req/s).
- With 12 users it reaches 23.7 req/s. With 50 users it is still 19.8 req/s, so adding users only makes them wait longer.
- A static logo file serves 1,219 req/s.

So the limit is the time PHP needs per request, not the network or the database.

## 3. The answer: how many users

**On this laptop, as configured:**
| Measure | Value |
|---|---|
| Server capacity | about **18 requests per second** (≈ 1,080 per minute) |
| Officers clicking **at exactly the same moment** | 10 comfortable (under 1 s); 25 acceptable (P95 2 s); 100 still work with **no errors**, but each waits 5–9 s |
| Officers **filling the form at the same time**, at a normal pace | about **250**. One inspection is about 100 requests (around 65 answers auto-saved, 7 steps, 4+ photos) over 20–30 minutes, so about 4 requests per minute per officer |
| Logins per minute (morning peak) | about **360** (each login is 3 requests plus the password check) |
| Registered officers this supports | about **2,500**, if 10 % are online at the peak |

**On a production server with the settings in section 5**, the same test is expected to give roughly 3 to 5 times these numbers. That estimate comes mainly from OPcache, which removes most of the 0.4 s per request. Re-run the test there to confirm (section 6).

**Bandwidth (for V5 "Allow max users as per the bandwidth"):**
- **Pages:** the login page is 17 KB of HTML. The CSS and JS (0.9 MB uncompressed) download once and are then cached by the browser.
- **Photos:** at most 1 MB after processing on the phone; about 300 KB is typical.
- **Example: 250 officers filling forms at once.**
  - Uploads: 4 photos × 250 in 25 minutes = 40 photos per minute ≈ 12 MB per minute ≈ **1.6 Mbit/s up**.
  - Pages: 250 × 4 requests per minute × about 20 KB ≈ 20 MB per minute ≈ **2.7 Mbit/s down**.
- **Formula:** Mbit/s ≈ users × (0.0065 for photos + 0.011 for pages). A 10 Mbit/s line serves about 550 officers working at the same time.

## 4. Findings
| # | Finding | Effect | Action |
|---|---|---|---|
| 1 | **Windows Apache runs PHP in threads, and Laravel's `.env` loading is not thread-safe.** Under parallel load some requests lost `APP_KEY` and `APP_ENV` ("No application encryption key"), which gave HTTP 500 and 419 | Random errors with as few as 10 users | **Always run `php artisan config:cache` on a threaded server** (it removes the `.env` reads). A Linux server with PHP-FPM is not affected |
| 2 | Blade views compiled by many threads at the same moment collide on Windows (`rename(): Access is denied`) | 500 errors right after a deploy | Run `php artisan view:cache` and open each page once after a deploy. The driver does this warm-up |
| 3 | **OPcache is off** in XAMPP, so PHP compiles about 1,000 framework files on every request (0.4 s) | Capacity is limited to about 18 req/s | Turn on OPcache (section 5). This is the biggest gain |
| 4 | `innodb_buffer_pool_size` = 16 MB, while the test database is 99 MB | No problem yet (99.7 % hits). With 50,000 schools and years of answers the data will not fit, and disk reads will slow reports | Set it to 1–2 GB on the server |
| 5 | Sessions and cache were stored in MariaDB: every request read and wrote the `sessions` table | One extra write per request | **Done (Nov 2026):** cache is `failover` (Redis, then file) and sessions use Redis when phpredis is loaded, else files. Check with `php artisan cache:status` |
| 6 | Login (bcrypt with 12 rounds) costs about 0.5 s more than other pages | Fine. It is the intended protection against password guessing | Keep it |
| 7 | No errors at 100 users at the same instant, and no deadlocks on `inspections` or `inspection_answers` | The app is correct under parallel use (start guard, auto-save, unique keys) | — |

## 5. Production settings (action plan)
| # | Action | Status |
|---|---|---|
| 1 | Build the load-test seeder and driver, then run the login and form tests on demo data, then restore the data | Done (ACTION.md #60) |
| 2 | Turn on PHP OPcache: `zend_extension=opcache`, `opcache.enable=1`, `opcache.memory_consumption=256`, `opcache.interned_strings_buffer=16`, `opcache.max_accelerated_files=20000`, `opcache.validate_timestamps=0` (clear it after each deploy) | To do at deployment |
| 3 | Deploy: `php artisan optimize` (config, routes, views and events cached), `APP_DEBUG=false`. **Required on Windows/Apache** (finding 1) | To do at deployment |
| 4 | MariaDB: `innodb_buffer_pool_size=1G` (2G with more than 50,000 schools), `max_connections` ≥ web worker count + 20, slow query log for the first week | To do at deployment |
| 5 | Prefer Linux + Nginx/Apache + PHP-FPM (8–16 workers per 4 cores). If Windows must be used, keep the config cached | Decide with IT |
| 6 | Sessions and cache on Redis. The app picks Redis automatically when the phpredis extension is loaded (`CACHE_STORE=failover`, session driver auto) and falls back to files. Install Redis (Linux: `apt install redis-server php8.2-redis`; Windows: Memurai + `php_redis.dll` TS x64) | Configured; install Redis on the server |
| 7 | Re-run this test on the production server before the pilot and record the numbers here | Before the pilot |
| 8 | Slow-network test of photo upload (V5): Chrome DevTools "Slow 4G" on the photo step | Separate V5 item |

## 6. How to run the test again
Only on a database you can restore, and never on the production database while it is in use.
```bash
mysqldump -uroot schoolinspection > backup.sql                 # 1. back up
php artisan db:seed --class=LoadTestSeeder                     # 2. load data (LOADTEST_USERS/SCHOOLS/INSPECTIONS to change sizes)
# 3. in .env: LOADTEST_MODE=true (lifts the 10-per-minute login limit; ignored when APP_ENV=production)
php artisan optimize                                           # 4. cache config/routes/views/events
php tools/loadtest/loadtest.php --scenario=login --steps=10,25,50,100,200 --p95-limit=5
php tools/loadtest/loadtest.php --scenario=form  --steps=10,25,50,100 --p95-limit=5 --base=https://your-server
# 5. clean up: remove LOADTEST_MODE, php artisan optimize:clear (local), restore backup.sql
mysql -uroot schoolinspection < backup.sql
```
- Results go to `storage/loadtest/*.json` (git-ignored).
- `--offset=N` uses officers N+1 onwards.
- `LOADTEST_DEBUG=1` prints the body of each failed response.
- The test officers use mobiles `7000000001…` and password `Load@1234`.
