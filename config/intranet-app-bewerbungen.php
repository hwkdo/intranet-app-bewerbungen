<?php

// config for Hwkdo/IntranetAppBewerbungen
return [
    'roles' => [
        'admin' => [
            'name' => 'App-Bewerbungen-Admin',
            'permissions' => [
                'see-app-bewerbungen',
                'manage-app-bewerbungen',
            ],
        ],
        'user' => [
            'name' => 'App-Bewerbungen-Benutzer',
            'permissions' => [
                'see-app-bewerbungen',
            ],
        ],
    ],
    'ai' => [
        'download_cache_path' => env('BEWERBUNGEN_DOWNLOAD_CACHE_PATH', 'bewerbungen_auswertung_cache'),
        'pdftotext_binary' => env('PDFTOTEXT_BINARY'),
    ],
];
