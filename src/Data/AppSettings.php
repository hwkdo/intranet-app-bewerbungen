<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppBewerbungen\Data;

use Hwkdo\IntranetAppBase\Contracts\HasAiSettings;
use Hwkdo\IntranetAppBase\Contracts\HasDocumentParseSettings;
use Hwkdo\IntranetAppBase\Data\Attributes\Description;
use Hwkdo\IntranetAppBase\Data\BaseAppSettings;
use Hwkdo\IntranetAppBase\Enums\AiProvider;
use Hwkdo\IntranetAppBase\Enums\DocumentParseEngine;
use Hwkdo\IntranetAppBase\Traits\HasDocumentParseSettingsFields;
use Hwkdo\IntranetAppBewerbungen\Enums\BewerbungenAuswertungAiProvider;

class AppSettings extends BaseAppSettings implements HasAiSettings, HasDocumentParseSettings
{
    use HasDocumentParseSettingsFields;

    public function __construct(
        #[Description('KI-Backend für den Artisan-Befehl bewerbungen:auswerten-ai (Laravel-AI-Provider-Name)')]
        public BewerbungenAuswertungAiProvider $bewerbungenAuswertungAiProvider = BewerbungenAuswertungAiProvider::GemmaLlamaCpp,

        #[Description('Modell für Bewerbungsauswertung (KI) bei Gemma (llama.cpp). Leer = Standard aus config/ai.php.')]
        public string $bewerbungenAuswertungModelGemmaLlamaCpp = 'gemma-4-26b-a4b(llama-cpp)',

        #[Description('Modell für Bewerbungsauswertung (KI) bei Open Web UI / Ollama (z. B. gpt-oss:20b). Leer = Fallback auf OPENWEBUI_DEFAULT_MODEL / config/ai.php.')]
        public string $bewerbungenAuswertungModelOpenWebUi = 'gpt-oss:20b',

        #[Description('Modell für Bewerbungsauswertung (KI) bei Langdock (nur von Langdock erlaubte IDs). Leer = Fallback auf BEWERBUNGEN_AI_LANGDOCK_MODEL / config/ai.php.')]
        public string $bewerbungenAuswertungModelLangdock = 'gpt-5.4-mini',

        #[Description('KI-Text-Provider überschreiben (leer = Intranet-Base-Default)')]
        public ?AiProvider $aiTextProviderOverride = null,

        #[Description('KI-Text-Modell überschreiben (leer = Base- bzw. Provider-Default)')]
        public ?string $aiTextModelOverride = null,

        #[Description('KI-Generate-Provider überschreiben (leer = Intranet-Base-Default)')]
        public ?AiProvider $aiImageProviderOverride = null,

        #[Description('KI-Generate-Modell überschreiben (leer = Base- bzw. Provider-Default)')]
        public ?string $aiImageModelOverride = null,

        #[Description('KI-Edit-Provider überschreiben (leer = Intranet-Base-Default)')]
        public ?AiProvider $aiImageEditProviderOverride = null,

        #[Description('KI-Edit-Modell überschreiben (leer = Base- bzw. Provider-Default)')]
        public ?string $aiImageEditModelOverride = null,

        #[Description('KI-Klassifikation-Provider überschreiben (leer = Intranet-Base-Default)')]
        public ?AiProvider $aiClassificationProviderOverride = null,

        #[Description('KI-Klassifikation-Modell überschreiben (leer = Base- bzw. Provider-Default)')]
        public ?string $aiClassificationModelOverride = null,

        #[Description('Aktiviert die Beispiel-Funktionalität')]
        public bool $enableExampleFeature = true,

        #[Description('Maximale Anzahl von Elementen pro Seite')]
        public int $maxItemsPerPage = 25,

        #[Description('Standard-Theme für die App')]
        public string $defaultTheme = 'light',

        #[Description('Liste der erlaubten Bereiche')]
        public array $allowedAreas = ['public', 'private'],

        #[Description('Document-Parsing-Motor überschreiben (leer = Intranet-Base-Default)')]
        public ?DocumentParseEngine $documentParseEngineOverride = DocumentParseEngine::LlamaParse,

        #[Description('LlamaParse-Tier überschreiben (leer = Intranet-Base-Default)')]
        public ?string $documentParseTierOverride = 'agentic',

        #[Description('Maximale Anzahl paralleler Varianten im Definitions-Test')]
        public int $maxTestVarianten = 4,
    ) {}

    public function textProviderOverride(): ?AiProvider
    {
        return $this->aiTextProviderOverride;
    }

    public function textModelOverride(): ?string
    {
        if (! is_string($this->aiTextModelOverride)) {
            return null;
        }

        $trimmed = trim($this->aiTextModelOverride);

        return $trimmed === '' ? null : $trimmed;
    }

    public function imageProviderOverride(): ?AiProvider
    {
        return $this->aiImageProviderOverride;
    }

    public function imageModelOverride(): ?string
    {
        if (! is_string($this->aiImageModelOverride)) {
            return null;
        }

        $trimmed = trim($this->aiImageModelOverride);

        return $trimmed === '' ? null : $trimmed;
    }

    public function imageEditProviderOverride(): ?AiProvider
    {
        return $this->aiImageEditProviderOverride;
    }

    public function imageEditModelOverride(): ?string
    {
        if (! is_string($this->aiImageEditModelOverride)) {
            return null;
        }

        $trimmed = trim($this->aiImageEditModelOverride);

        return $trimmed === '' ? null : $trimmed;
    }

    public function classificationProviderOverride(): ?AiProvider
    {
        return $this->aiClassificationProviderOverride;
    }

    public function classificationModelOverride(): ?string
    {
        if (! is_string($this->aiClassificationModelOverride)) {
            return null;
        }

        $trimmed = trim($this->aiClassificationModelOverride);

        return $trimmed === '' ? null : $trimmed;
    }
}
