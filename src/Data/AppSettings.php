<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppBewerbungen\Data;

use Hwkdo\IntranetAppBase\Data\Attributes\Description;
use Hwkdo\IntranetAppBase\Data\BaseAppSettings;
use Hwkdo\IntranetAppBewerbungen\Enums\BewerbungenAuswertungAiProvider;

class AppSettings extends BaseAppSettings
{
    public function __construct(
        #[Description('KI-Backend für den Artisan-Befehl bewerbungen:auswerten-ai (Laravel-AI-Provider-Name)')]
        public BewerbungenAuswertungAiProvider $bewerbungenAuswertungAiProvider = BewerbungenAuswertungAiProvider::OpenWebUi,

        #[Description('Aktiviert die Beispiel-Funktionalität')]
        public bool $enableExampleFeature = true,
        
        #[Description('Maximale Anzahl von Elementen pro Seite')]
        public int $maxItemsPerPage = 25,
        
        #[Description('Standard-Theme für die App')]
        public string $defaultTheme = 'light',
        
        #[Description('Liste der erlaubten Bereiche')]
        public array $allowedAreas = ['public', 'private'],
    ) {}
}
