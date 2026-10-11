<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppBewerbungen\Data;

use Hwkdo\IntranetAppBase\Enums\AiProvider;
use Hwkdo\IntranetAppBase\Enums\DocumentParseEngine;

final class AnalyseOverrides
{
    public function __construct(
        public ?DocumentParseEngine $engine = null,
        public ?string $tier = null,
        public ?AiProvider $provider = null,
        public ?string $modell = null,
        public bool $ohneCache = false,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $tier = trim((string) ($data['tier'] ?? ''));
        $modell = trim((string) ($data['modell'] ?? ''));

        return new self(
            engine: DocumentParseEngine::tryFrom((string) ($data['engine'] ?? '')),
            tier: $tier !== '' ? $tier : null,
            provider: AiProvider::tryFrom((string) ($data['provider'] ?? '')),
            modell: $modell !== '' ? $modell : null,
            ohneCache: (bool) ($data['ohneCache'] ?? false),
        );
    }

    /**
     * @return array{engine: string|null, tier: string|null, provider: string|null, modell: string|null, ohneCache: bool}
     */
    public function toArray(): array
    {
        return [
            'engine' => $this->engine?->value,
            'tier' => $this->tier,
            'provider' => $this->provider?->value,
            'modell' => $this->modell,
            'ohneCache' => $this->ohneCache,
        ];
    }
}
