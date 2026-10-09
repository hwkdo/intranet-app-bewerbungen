<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppBewerbungen\Data;

use Hwkdo\IntranetAppBewerbungen\Enums\KiFeldTyp;

class KiFeld
{
    /**
     * @param  list<string>  $optionen
     */
    public function __construct(
        public string $key,
        public string $label,
        public KiFeldTyp $typ,
        public string $hinweis = '',
        public bool $pflicht = false,
        public array $optionen = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $optionen = array_key_exists('optionenText', $data) ? $data['optionenText'] : ($data['optionen'] ?? []);
        if (is_string($optionen)) {
            $optionen = array_values(array_filter(array_map(trim(...), explode(',', $optionen))));
        }

        return new self(
            key: (string) ($data['key'] ?? ''),
            label: (string) ($data['label'] ?? ''),
            typ: KiFeldTyp::tryFrom((string) ($data['typ'] ?? '')) ?? KiFeldTyp::Text,
            hinweis: (string) ($data['hinweis'] ?? ''),
            pflicht: (bool) ($data['pflicht'] ?? false),
            optionen: array_values(array_map(strval(...), is_array($optionen) ? $optionen : [])),
        );
    }

    /**
     * @return array{key: string, label: string, typ: string, hinweis: string, pflicht: bool, optionen: list<string>}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'typ' => $this->typ->value,
            'hinweis' => $this->hinweis,
            'pflicht' => $this->pflicht,
            'optionen' => $this->optionen,
        ];
    }
}
