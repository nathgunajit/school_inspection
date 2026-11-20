<?php

/*
| Configurable values marked "Proposed" in PRD Sec 30. Confirm with the Department.
*/
return [
    'draft_expiry_days' => (int) env('INSPECTION_DRAFT_EXPIRY_DAYS', 7),

    'login' => [
        // Modification V3: lock after 3 wrong attempts for 20 minutes; CAPTCHA from the 3rd attempt
        'captcha_after_failures' => (int) env('LOGIN_CAPTCHA_AFTER', 2),
        // Math CAPTCHA ("7 + 4 = ?") on every login (AppSupportMathCaptcha)
        'math_captcha' => (bool) env('LOGIN_MATH_CAPTCHA', true),
        'lock_after_failures' => (int) env('LOGIN_LOCK_AFTER', 3),
        'lock_minutes' => (int) env('LOGIN_LOCK_MINUTES', 20),
    ],

    'id_proof' => [
        'max_kb' => 2048,
        'mimes' => ['pdf', 'jpg', 'jpeg', 'png'],
    ],

    'employee_photo' => [
        'max_kb' => 1024,
        'mimes' => ['jpg', 'jpeg', 'png'],
    ],

    'evidence' => [
        'max_photos' => 20,              // total incl. mandatory set
        'photo_max_kb' => 1024,          // after processing on phone
        'photo_max_side_px' => 1600,
        'max_documents' => 5,
        'document_max_kb' => 5120,
        'document_mimes' => ['application/pdf', 'image/jpeg', 'image/png'],
        'photo_mimes' => ['image/jpeg'],
        'clamav_binary' => env('CLAMAV_BINARY'), // e.g. C:\ClamAV\clamscan.exe ; null = skip scan
    ],

    // Mandatory photograph categories and minimum count (Modification V1, step 7)
    'mandatory_photos' => [
        'school' => 1,
        'toilet_block' => 1,
        'ict_smart' => 1,
        'classroom_transaction' => 1,
    ],

    'captcha_enabled' => (bool) env('CAPTCHA_ENABLED', true),

    // Demo accounts panel on the login page (DemoUserSeeder). Never shown when APP_ENV=production.
    'demo_logins' => (bool) env('DEMO_LOGINS', true),

    // Load testing only (V5 Step 2, Product Details/LOAD_TEST.md): lifts the per-IP login rate limit. Always off in production.
    'loadtest' => (bool) env('LOADTEST_MODE', false) && env('APP_ENV') !== 'production',

    // New Module 1, Map View: OpenStreetMap tiles through Leaflet (bundled, no CDN).
    // For a self-hosted or commercial tile server set MAP_TILE_URL / MAP_TILE_ATTRIBUTION.
    'map' => [
        'tiles' => env('MAP_TILE_URL', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),
        'attribution' => env('MAP_TILE_ATTRIBUTION', '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'),
        'center' => [26.2, 92.9],   // Assam
        'zoom' => 7,
        'cache_seconds' => (int) env('MAP_CACHE_SECONDS', 60),
        // Boundaries drawn when the files exist. Built from the KML files by `php artisan map:boundaries`.
        // District features match the District Master by property "code" or "name".
        'geojson' => [
            'state' => 'geo/assam-state.geojson',
            'districts' => 'geo/assam-districts.geojson',
            // V6 Module 7: one dissolved outline per KML district, plus index.json (name, slug, bbox)
            'district_dir' => 'geo/districts',
        ],
        // V6 Module 8: road route from the officer's position to a school (OSRM, called from the browser only when
        // the officer presses "Show route"). The public demo server is for light use; point MAP_ROUTING_URL to your own.
        'routing' => [
            'enabled' => (bool) env('MAP_ROUTING', true),
            'url' => rtrim(env('MAP_ROUTING_URL', 'https://router.project-osrm.org'), '/'),
            'profile' => env('MAP_ROUTING_PROFILE', 'driving'),
            'timeout' => 8000, // ms
        ],
    ],

    // New Module 2, inspection scheduling
    'schedule' => [
        'approval_required' => (bool) env('SCHEDULE_APPROVAL_REQUIRED', true), // an officer's own plan needs a higher officer's approval
        'max_per_day' => (int) env('SCHEDULE_MAX_PER_DAY', 8),
        'max_days_ahead' => 92,         // plans can be made up to about 3 months ahead
        'road_factor' => 1.3,           // straight-line km × factor ≈ road km
        'avg_speed_kmh' => 25,          // rural roads, for the estimated travel time
        'visit_minutes' => 60,          // time spent at each school
        'day_start' => '09:30',         // planned start of the day's visits
    ],

    // V6 Module 5: passwords are RSA-encrypted in the browser before submit (App\Services\PasswordCrypto)
    'security' => [
        'encrypt_passwords' => (bool) env('PASSWORD_ENCRYPTION', true),
        // refuse a plain-text password (off in phpunit.xml; also accepted in load-test mode)
        'require_encrypted_password' => (bool) env('PASSWORD_ENCRYPTION_REQUIRED', true),
        'key_dir' => 'keys', // on the private disk: storage/app/private/keys
    ],

    // Modification V9: ratings are still calculated at submit, but not shown to users (report, lists,
    // school page, map, Rating Report) unless RATING_DISPLAY=true. Admin Rating Settings stays available.
    'rating' => [
        'display' => (bool) env('RATING_DISPLAY', false),
    ],

    // Inspection Coverage Report: defaults used until the State Admin saves a coverage setting
    // (table coverage_settings). Bands colour the coverage % per area (minimum % => label, colour).
    'coverage' => [
        'period_type' => 'monthly',        // daily | weekly | monthly | quarterly | custom
        'inspection_cycle' => 'quarterly', // monthly | quarterly | half_yearly | yearly
        'verify_radius_m' => 500,          // a visit is verified when a location is within this distance of the school
        'bands' => [75 => ['Good', 'success'], 50 => ['Moderate', 'primary'], 25 => ['Low', 'warning'], 0 => ['Critical', 'danger']],
    ],

    'max_suggestions' => 3,
    'per_page' => 25,
    'retention_years' => 5,
];
