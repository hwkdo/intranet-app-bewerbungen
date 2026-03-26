<?php

namespace Hwkdo\IntranetAppBewerbungen\Data;

use Hwkdo\IntranetAppBase\Data\BaseUserSettings;
use Hwkdo\IntranetAppBase\Data\Attributes\Description;
use Hwkdo\IntranetAppBewerbungen\Enums\ViewModeEnum;

class UserSettings extends BaseUserSettings
{
    public function __construct(
        #[Description('Standard-Anzeigemodus für die App')]
        public ViewModeEnum $defaultViewMode = ViewModeEnum::Grid,
        
        #[Description('Favoriten-Bereiche des Benutzers')]
        public array $favoriteAreas = [],
        
        #[Description('Benachrichtigungen aktiviert')]
        public bool $notificationsEnabled = true,
    ) {}
}
