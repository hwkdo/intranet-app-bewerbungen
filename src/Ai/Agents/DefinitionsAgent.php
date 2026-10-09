<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppBewerbungen\Ai\Agents;

use Hwkdo\IntranetAppBewerbungen\Data\KiFeld;
use Hwkdo\IntranetAppBewerbungen\Enums\KiFeldTyp;
use Hwkdo\IntranetAppBewerbungen\Models\KiDefinition;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

class DefinitionsAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(public KiDefinition $definition) {}

    public function timeout(): int
    {
        return 300;
    }

    public function instructions(): string
    {
        $hinweise = collect($this->definition->kiFelder())
            ->map(fn (KiFeld $feld): string => '- "'.$feld->key.'": '.($feld->hinweis !== '' ? $feld->hinweis : $feld->label))
            ->implode("\n");

        return trim($this->definition->instruktionen."\n\nFelder:\n".$hinweise);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $felder = [];

        foreach ($this->definition->kiFelder() as $feld) {
            $typ = $this->schemaTyp($schema, $feld);
            if ($feld->hinweis !== '') {
                $typ = $typ->description($feld->hinweis);
            }
            $felder[$feld->key] = $feld->pflicht ? $typ->required() : $typ->nullable();
        }

        return $felder;
    }

    private function schemaTyp(JsonSchema $schema, KiFeld $feld): Type
    {
        return match ($feld->typ) {
            KiFeldTyp::Text => $schema->string(),
            KiFeldTyp::Zahl => $schema->number(),
            KiFeldTyp::Ganzzahl => $schema->integer(),
            KiFeldTyp::JaNein => $schema->boolean(),
            KiFeldTyp::Liste => $schema->array()->items($schema->string()),
            KiFeldTyp::Auswahl => $schema->string()->enum($feld->optionen),
        };
    }
}
