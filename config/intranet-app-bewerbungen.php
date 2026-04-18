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
    | KI für bewerbungen:auswerten-ai (Provider + optionale Modell-Overrides in AppSettings → Admin „Bewerbungen“):
    | - Open Web UI: OPENWEBUI_*; Modell: AppSettings bewerbungenAuswertungModelOpenWebUi, sonst OPENWEBUI_DEFAULT_MODEL (config/ai.php → openwebui)
    | - Langdock: LANGDOCK_*; Modell: AppSettings bewerbungenAuswertungModelLangdock, sonst BEWERBUNGEN_AI_LANGDOCK_MODEL (config/ai.php → langdock)
    */
    'ai' => [
        'download_cache_path' => env('BEWERBUNGEN_DOWNLOAD_CACHE_PATH', 'bewerbungen_auswertung_cache'),
        'pdftotext_binary' => env('PDFTOTEXT_BINARY'),
    ],
];
