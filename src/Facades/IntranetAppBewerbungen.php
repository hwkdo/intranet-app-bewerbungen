<?php

namespace Hwkdo\IntranetAppBewerbungen\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Hwkdo\IntranetAppBewerbungen\IntranetAppBewerbungen
 */
class IntranetAppBewerbungen extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Hwkdo\IntranetAppBewerbungen\IntranetAppBewerbungen::class;
    }
}
