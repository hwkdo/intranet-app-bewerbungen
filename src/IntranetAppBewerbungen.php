<?php

namespace Hwkdo\IntranetAppBewerbungen;
use Hwkdo\IntranetAppBase\Interfaces\IntranetAppInterface;
use Illuminate\Support\Collection;

class IntranetAppBewerbungen implements IntranetAppInterface 
{
    public static function app_name(): string
    {
        return 'Bewerbungen';
    }

    public static function app_icon(): string
    {
        return 'magnifying-glass';
    }

    public static function identifier(): string
    {
        return 'bewerbungen';
    }

    public static function roles_admin(): Collection
    {
        return collect(config('intranet-app-bewerbungen.roles.admin'));
    }

    public static function roles_user(): Collection
    {
        return collect(config('intranet-app-bewerbungen.roles.user'));
    }
    
    public static function userSettingsClass(): ?string
    {
        return \Hwkdo\IntranetAppBewerbungen\Data\UserSettings::class;
    }
    
    public static function appSettingsClass(): ?string
    {
        return \Hwkdo\IntranetAppBewerbungen\Data\AppSettings::class;
    }

    public static function mcpServers(): array
    {
        return [];
    }
}
