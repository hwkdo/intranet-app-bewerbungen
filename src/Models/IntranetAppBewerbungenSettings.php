<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppBewerbungen\Models;

use Hwkdo\IntranetAppBewerbungen\Data\AppSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class IntranetAppBewerbungenSettings extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'settings' => AppSettings::class.':default',
        ];
    }

    public static function current(): IntranetAppBewerbungenSettings|null
    {
        return self::orderBy('version', 'desc')->first();
    }

    /**
     * App-Settings für KI-Auswertung u. a.: ohne Tabelle (z. B. frische Tests) → Defaults.
     */
    public static function resolvedAppSettings(): AppSettings
    {
        if (! Schema::hasTable((new static)->getTable())) {
            return new AppSettings;
        }

        $row = static::current();

        return $row?->settings instanceof AppSettings ? $row->settings : new AppSettings;
    }
}
