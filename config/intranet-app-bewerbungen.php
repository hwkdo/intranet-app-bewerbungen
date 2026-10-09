<?php

// config for Hwkdo/IntranetAppBewerbungen
return [
    'roles' => [
        'admin' => [
            'name' => 'App-Bewerbungen-Admin',
            'permissions' => [
                'see-app-bewerbungen',
                'manage-app-bewerbungen',
                'manage-app-bewerbungen-definitionen',
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
    | - Gemma (llama.cpp): llama_cpp_api_key, Modell gemma-4-26b-a4b(llama-cpp) (config/ai.php → gemma-llama-cpp)
    | - Open Web UI: OPENWEBUI_*; Modell: AppSettings bewerbungenAuswertungModelOpenWebUi, sonst OPENWEBUI_DEFAULT_MODEL
    | - Langdock: LANGDOCK_*; Modell: AppSettings bewerbungenAuswertungModelLangdock, sonst BEWERBUNGEN_AI_LANGDOCK_MODEL
    | Dokumente liest LlamaParse (LLAMA_CLOUD_API_KEY). max_dokument_zeichen begrenzt den gesamten Prompt auf das Gemma-Kontextfenster.
    */
    'ai' => [
        'download_cache_path' => env('BEWERBUNGEN_DOWNLOAD_CACHE_PATH', 'bewerbungen_auswertung_cache'),
        'max_dokument_zeichen' => 150_000,
    ],

    'lightrag' => [
        'api_key' => env('LIGHTRAG_PERSO_API_KEY', env('lightrag_perso_api_key')),
        'execute_in_tests' => false,
        'instances' => [
            'perso-azubi-fisi' => [
                'label' => 'Perso Azubi FISI',
                'url' => env('LIGHTRAG_PERSO_AZUBI_FISI_URL', 'https://lightrag-perso-azubi-fisi.swarm.hwkdo.com'),
            ],
            'perso-ae' => [
                'label' => 'Perso AE',
                'url' => env('LIGHTRAG_PERSO_AE_URL', 'https://lightrag-perso-ae.swarm.hwkdo.com'),
            ],
            'perso-fisi' => [
                'label' => 'Perso FISI',
                'url' => env('LIGHTRAG_PERSO_FISI_URL', 'https://lightrag-perso-fisi.swarm.hwkdo.com'),
            ],
        ],
    ],
];
