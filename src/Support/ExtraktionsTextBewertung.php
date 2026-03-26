<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppBewerbungen\Support;

final readonly class ExtraktionsTextBewertung
{
    public function __construct(
        public bool $istPlausibel,
        public string $beschreibung,
        public float $bedeutungsAnteil,
        public int $zeichenOhneLeerzeichen,
    ) {}
}

