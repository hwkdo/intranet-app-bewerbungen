<?php

namespace Hwkdo\IntranetAppBewerbungen\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LegacyBewerbungenAiCallbackService
{
    /**
     * @param  array<string,mixed>  $payload
     */
    public function sendResult(array $payload): void
    {
        $url = rtrim((string) config('legacy.base_api_url'), '/').'/apps/bewerbungen/ai/callback';
        $token = (string) config('legacy.base_api_token');

        if ($url === '' || $token === '') {
            Log::warning('Legacy Callback übersprungen: URL oder Token fehlt.', [
                'request_id' => $payload['request_id'] ?? null,
            ]);

            return;
        }

        $response = Http::withoutVerifying()
            ->timeout(60)
            ->retry(2, 1000)
            ->withToken($token)
            ->acceptJson()
            ->post($url, $payload);

        if (! $response->successful()) {
            throw new \RuntimeException('Legacy Callback fehlgeschlagen: '.$response->status().' - '.$response->body());
        }
    }
}

