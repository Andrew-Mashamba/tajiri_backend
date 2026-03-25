<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Firebase Credentials
    |--------------------------------------------------------------------------
    |
    | Path to the Firebase service account JSON key file. Obtain this from
    | Firebase Console → Project Settings → Service accounts → Generate new
    | private key.
    |
    */
    'credentials' => env('FIREBASE_CREDENTIALS', storage_path('app/firebase/service-account.json')),

    /*
    |--------------------------------------------------------------------------
    | Firebase Project ID
    |--------------------------------------------------------------------------
    |
    | Your Firebase project ID. If left null, it will be read from the
    | service account credentials file.
    |
    */
    'project_id' => env('FIREBASE_PROJECT_ID'),

    /*
    |--------------------------------------------------------------------------
    | Firestore Live-Update Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for the live-update notification system that writes to
    | Firestore `updates/{userId}` documents.
    |
    */
    'live_updates' => [
        'enabled' => env('FIREBASE_LIVE_UPDATES_ENABLED', true),
        'collection' => 'updates',
    ],

];
