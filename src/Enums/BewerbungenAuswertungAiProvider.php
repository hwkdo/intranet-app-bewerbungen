<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppBewerbungen\Enums;

enum BewerbungenAuswertungAiProvider: string
{
    case OpenWebUi = 'openwebui';
    case Langdock = 'langdock';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::OpenWebUi->value => 'Open Web UI (Ollama)',
            self::Langdock->value => 'Langdock',
        ];
    }
}
