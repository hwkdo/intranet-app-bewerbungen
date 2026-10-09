<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppBewerbungen\Support;

use Hwkdo\IntranetAppBewerbungen\Data\KiFeld;
use Hwkdo\IntranetAppBewerbungen\Enums\KiFeldTyp;
use Hwkdo\IntranetAppBewerbungen\Models\KiDefinition;

class KiErgebnisEnvelope
{
    /** @var list<string> */
    public const RESERVED_KEYS = [
        'bewerbung_ro',
        'anhang_ro',
        'verarbeitete_dokumente',
    ];

    /**
     * @param  array<string, mixed>  $roh
     * @param  array{bewerbung_ro?: string|null, anhang_ro?: string|null}  $links
     * @param  list<string>  $dokumente
     * @return array{definition: array{id: int|null, name: string, fallback: bool}, fields: list<array{key: string, label: string, typ: string, value: mixed}>, system: list<array{key: string, label: string, typ: string, value: mixed}>}
     */
    public static function bauen(KiDefinition $definition, array $roh, array $links, array $dokumente, bool $fallback): array
    {
        $fields = [];
        foreach ($definition->kiFelder() as $feld) {
            $fields[] = [
                'key' => $feld->key,
                'label' => $feld->label,
                'typ' => $feld->typ->value,
                'value' => self::wert($feld, $roh[$feld->key] ?? null),
            ];
        }

        return [
            'definition' => [
                'id' => $definition->id !== null ? (int) $definition->id : null,
                'name' => (string) $definition->name,
                'fallback' => $fallback,
            ],
            'fields' => $fields,
            'system' => [
                [
                    'key' => 'bewerbung_ro',
                    'label' => 'OneDrive-Link Bewerbung',
                    'typ' => 'link',
                    'value' => self::link($links['bewerbung_ro'] ?? null),
                ],
                [
                    'key' => 'anhang_ro',
                    'label' => 'OneDrive-Link Anhang',
                    'typ' => 'link',
                    'value' => self::link($links['anhang_ro'] ?? null),
                ],
                [
                    'key' => 'verarbeitete_dokumente',
                    'label' => 'Verarbeitete Dokumente',
                    'typ' => 'liste',
                    'value' => array_values($dokumente),
                ],
            ],
        ];
    }

    private static function wert(KiFeld $feld, mixed $value): mixed
    {
        if ($value === '' || $value === []) {
            return $feld->typ === KiFeldTyp::Liste ? [] : null;
        }

        return match ($feld->typ) {
            KiFeldTyp::Liste => is_array($value) ? array_values($value) : [$value],
            KiFeldTyp::JaNein => is_bool($value) ? $value : null,
            KiFeldTyp::Ganzzahl => is_numeric($value) ? (int) $value : null,
            KiFeldTyp::Zahl => is_numeric($value) ? (float) $value : null,
            default => is_scalar($value) ? (string) $value : null,
        };
    }

    private static function link(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
