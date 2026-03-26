<?php

namespace Hwkdo\IntranetAppBewerbungen\Models;

use Hwkdo\IntranetAppBewerbungen\Data\AppSettings;
use Illuminate\Database\Eloquent\Model;

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
}
