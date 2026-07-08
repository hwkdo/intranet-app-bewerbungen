<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppBewerbungen\Data;

use Hwkdo\IntranetAppBase\Contracts\HasAiSettings;
use Hwkdo\IntranetAppBase\Data\Attributes\Description;
use Hwkdo\IntranetAppBase\Data\BaseAppSettings;
use Hwkdo\IntranetAppBase\Enums\AiProvider;
use Hwkdo\IntranetAppBewerbungen\Enums\BewerbungenAuswertungAiProvider;

class AppSettings extends BaseAppSettings implements HasAiSettings
{
    public function __construct(
        #[Description('KI-Backend für den Artisan-Befehl bewerbungen:auswerten-ai (Laravel-AI-Provider-Name)')]
        public BewerbungenAuswertungAiProvider $bewerbungenAuswertungAiProvider = BewerbungenAuswertungAiProvider::OpenWebUi,

        #[Description('Modell für Bewerbungsauswertung (KI) bei Open Web UI / Ollama (z. B. gpt-oss:20b). Leer = Fallback auf OPENWEBUI_DEFAULT_MODEL / config/ai.php.')]
        public string $bewerbungenAuswertungModelOpenWebUi = 'gpt-oss:20b',

        #[Description('Modell für Bewerbungsauswertung (KI) bei Langdock (nur von Langdock erlaubte IDs). Leer = Fallback auf BEWERBUNGEN_AI_LANGDOCK_MODEL / config/ai.php.')]
        public string $bewerbungenAuswertungModelLangdock = 'gpt-5.4-mini',

        #[Description('KI-Text-Provider überschreiben (leer = Intranet-Base-Default)')]
        public ?AiProvider $aiTextProviderOverride = null,

        #[Description('KI-Text-Modell überschreiben (leer = Base- bzw. Provider-Default)')]
        public ?string $aiTextModelOverride = null,

        #[Description('KI-Bild-Provider überschreiben (leer = Intranet-Base-Default)')]
        public ?AiProvider $aiImageProviderOverride = null,

        #[Description('KI-Bild-Modell überschreiben (leer = Base- bzw. Provider-Default)')]
        public ?string $aiImageModelOverride = null,

        #[Description('Aktiviert die Beispiel-Funktionalität')]
        public bool $enableExampleFeature = true,

        #[Description('Maximale Anzahl von Elementen pro Seite')]
        public int $maxItemsPerPage = 25,

        #[Description('Standard-Theme für die App')]
        public string $defaultTheme = 'light',

        #[Description('Liste der erlaubten Bereiche')]
        public array $allowedAreas = ['public', 'private'],
    ) {}

    public function textProviderOverride(): ?AiProvider
    {
        if ($this->aiTextProviderOverride !== null) {
            return $this->aiTextProviderOverride;
        }

        return match ($this->bewerbungenAuswertungAiProvider) {
            BewerbungenAuswertungAiProvider::OpenWebUi => AiProvider::OpenWebUi,
            BewerbungenAuswertungAiProvider::Langdock => AiProvider::Langdock,
        };
    }

    public function textModelOverride(): ?string
    {
        if (is_string($this->aiTextModelOverride) && trim($this->aiTextModelOverride) !== '') {
            return trim($this->aiTextModelOverride);
        }

        $legacy = match ($this->bewerbungenAuswertungAiProvider) {
            BewerbungenAuswertungAiProvider::OpenWebUi => $this->bewerbungenAuswertungModelOpenWebUi,
            BewerbungenAuswertungAiProvider::Langdock => $this->bewerbungenAuswertungModelLangdock,
        };

        return trim($legacy) !== '' ? trim($legacy) : null;
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
}
