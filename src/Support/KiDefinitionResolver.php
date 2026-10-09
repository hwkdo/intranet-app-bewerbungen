<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppBewerbungen\Support;

use Hwkdo\IntranetAppBewerbungen\Models\KiDefinition;
use RuntimeException;

class KiDefinitionResolver
{
    /**
     * @return array{definition: KiDefinition, fallback: bool}
     */
    public function resolve(?int $id): array
    {
        if ($id !== null && $id > 0) {
            $gefunden = KiDefinition::query()->find($id);
            if ($gefunden instanceof KiDefinition) {
                return ['definition' => $gefunden, 'fallback' => false];
            }
        }

        $standard = KiDefinition::defaultDefinition();
        if (! $standard instanceof KiDefinition) {
            throw new RuntimeException('Keine KI-Definition vorhanden. Bitte zuerst eine Standard-Definition anlegen.');
        }

        return [
            'definition' => $standard,
            'fallback' => $id !== null && $id > 0,
        ];
    }
}
