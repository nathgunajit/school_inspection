<?php

/*
| Configurable values marked "Proposed" in PRD Sec 30. Confirm with the Department.
*/
return [
    'draft_expiry_days' => (int) env('INSPECTION_DRAFT_EXPIRY_DAYS', 7),

    'login' => [
        'captcha_after_failures' => 3,
        'lock_after_failures' => 5,
        'lock_minutes' => 15,
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

    'max_suggestions' => 3,
    'per_page' => 25,
    'retention_years' => 5,
];
