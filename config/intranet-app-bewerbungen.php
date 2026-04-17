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
    /*
    | KI für bewerbungen:auswerten-ai (Provider steht in AppSettings → Admin „Bewerbungen“):
    | - Open Web UI: OPENWEBUI_API_KEY, OPENWEBUI_BASE_API_URL_OLLAMA, OPENWEBUI_DEFAULT_MODEL (siehe config/ai.php → openwebui)
    | - Langdock: LANGDOCK_API_KEY, LANGDOCK_BASE_API_URL, BEWERBUNGEN_AI_LANGDOCK_MODEL (siehe config/ai.php → langdock, config/services.php)
    */
    'ai' => [
        'download_cache_path' => env('BEWERBUNGEN_DOWNLOAD_CACHE_PATH', 'bewerbungen_auswertung_cache'),
        'pdftotext_binary' => env('PDFTOTEXT_BINARY'),
    ],
];
