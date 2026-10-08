<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppBewerbungen\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class LegacyBewerbungenStelleClient
{
    /**
     * @return array{stelle: array<string, mixed>, bewerbungen: list<array<string, mixed>>}
     */
    public function fetch(int $stelleId): array
    {
        $baseUrl = rtrim((string) config('legacy.base_api_url'), '/');
        $token = (string) config('legacy.base_api_token');
        if ($baseUrl === '' || $token === '') {
            throw new RuntimeException('Legacy-API ist nicht konfiguriert.');
        }

        $response = Http::withoutVerifying()
            ->timeout(60)
            ->withToken($token)
            ->acceptJson()
            ->get($baseUrl.'/apps/bewerbungen/lightrag/'.$stelleId);

        if ($response->status() === 404) {
            throw new RuntimeException('Stelle '.$stelleId.' wurde im Legacy-Intranet nicht gefunden.');
        }

        $response->throw();

        $payload = $response->json();
        if (! is_array($payload) || ! is_array($payload['stelle'] ?? null) || ! is_array($payload['bewerbungen'] ?? null)) {
            throw new RuntimeException('Legacy-Intranet hat keine Bewerbungsliste geliefert.');
        }

        /** @var array{stelle: array<string, mixed>, bewerbungen: list<array<string, mixed>>} $payload */
        return $payload;
    }
}
