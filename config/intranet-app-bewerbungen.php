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

    /*
    | Operator-Cockpit der Stellenanalyse. Leer lassen, dann leitet das Cockpit die
    | llama.cpp-Basis von config/ai.php (gemma-llama-cpp.url, ohne /v1) ab.
    | Hostmetriken nur setzen, wenn ein Exporter erreichbar ist. Keine Defaults erfinden.
    |
    | BEWERBUNGEN_LLAMA_CPP_BASE_URL       optional, z. B. https://llama.example
    | BEWERBUNGEN_DGX_DCGM_METRICS_URL     optional, z. B. https://dcgm.ai.hwkdo.com/metrics
    | BEWERBUNGEN_DGX_NODE_EXPORTER_URL    optional, z. B. https://node.ai.hwkdo.com/metrics
    |
    | Beide Hosts liegen hinter der Traefik-Middleware ollama-apikey.
    | Das Cockpit sendet dafür llama_cpp_api_key (config/ai.php, gemma-llama-cpp.key)
    | als Authorization: Bearer und als X-API-Key.
    */
    'pipeline' => [
        'llama_base_url' => env('BEWERBUNGEN_LLAMA_CPP_BASE_URL'),
        'dcgm_metrics_url' => env('BEWERBUNGEN_DGX_DCGM_METRICS_URL'),
        'node_exporter_url' => env('BEWERBUNGEN_DGX_NODE_EXPORTER_URL'),
        'dram_peak_gb_s' => (float) env('BEWERBUNGEN_DGX_DRAM_PEAK_GB_S', 273),
        'gpu_budget_watt' => (float) env('BEWERBUNGEN_DGX_GPU_BUDGET_WATT', 140),
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
