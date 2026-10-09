<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppBewerbungen\Enums;

enum KiFeldTyp: string
{
    case Text = 'text';
    case Zahl = 'zahl';
    case Ganzzahl = 'ganzzahl';
    case JaNein = 'ja_nein';
    case Liste = 'liste';
    case Auswahl = 'auswahl';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Text->value => 'Text',
            self::Zahl->value => 'Zahl',
            self::Ganzzahl->value => 'Ganzzahl',
            self::JaNein->value => 'Ja/Nein',
            self::Liste->value => 'Liste',
            self::Auswahl->value => 'Auswahl',
        ];
    }
}
