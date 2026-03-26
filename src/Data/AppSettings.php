<?php

namespace Hwkdo\IntranetAppBewerbungen\Data;

use Hwkdo\IntranetAppBase\Data\BaseAppSettings;
use Hwkdo\IntranetAppBase\Data\Attributes\Description;

class AppSettings extends BaseAppSettings
{
    public function __construct(
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
