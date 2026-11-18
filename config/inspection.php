<?php

/*
| Configurable values marked "Proposed" in PRD Sec 30. Confirm with the Department.
*/
return [
    'draft_expiry_days' => (int) env('INSPECTION_DRAFT_EXPIRY_DAYS', 7),

    'login' => [
        // Modification V3: lock after 3 wrong attempts for 20 minutes; CAPTCHA from the 3rd attempt
        'captcha_after_failures' => (int) env('LOGIN_CAPTCHA_AFTER', 2),
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

    'max_suggestions' => 3,
    'per_page' => 25,
    'retention_years' => 5,
];
