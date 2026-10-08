<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppBewerbungen\Enums;

enum BewerbungenAuswertungAiProvider: string
{
    case OpenWebUi = 'openwebui';
    case Langdock = 'langdock';
    case GemmaLlamaCpp = 'gemma-llama-cpp';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::GemmaLlamaCpp->value => 'Gemma (llama.cpp)',
            self::OpenWebUi->value => 'Open Web UI (Ollama)',
            self::Langdock->value => 'Langdock',
        ];
    }
}
