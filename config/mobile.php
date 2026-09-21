<?php

/*
|--------------------------------------------------------------------------
| Mobile app (Flutter) API configuration
|--------------------------------------------------------------------------
|
| Consumed by the /api/mobile/* endpoints. The Flutter app reads
| min_app_version from /api/mobile/ping and prompts the user to update
| when its own version is older.
|
*/

return [

    // Version of the mobile API contract exposed by this server.
    'api_version' => 1,

    // Oldest app version this server still supports (semver).
    'min_app_version' => env('MOBILE_MIN_APP_VERSION', '1.0.0'),

    // Path to the Firebase service-account JSON used for FCM HTTP v1 pushes.
    // Push notifications are silently disabled while this file is absent.
    'fcm_credentials' => env('FIREBASE_CREDENTIALS', storage_path('app/firebase-credentials.json')),

];
