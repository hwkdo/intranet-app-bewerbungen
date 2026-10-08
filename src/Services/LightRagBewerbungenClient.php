<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppBewerbungen\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class LightRagBewerbungenClient
{
    /**
     * @return array{track_id: string, already_present: bool}
     */
    public function insertText(string $instance, string $text, string $fileSource): array
    {
        $response = $this->request($instance)->post($this->baseUrl($instance).'/documents/text', [
            'text' => $text,
            'file_source' => $fileSource,
        ]);

        if ($response->status() === 409) {
            return ['track_id' => '', 'already_present' => true];
        }

        $response->throw();

        return [
            'track_id' => $this->trackId($response->json('track_id')),
            'already_present' => false,
        ];
    }

    /**
     * @return array{track_id: string, already_present: bool}
     */
    public function uploadContents(string $instance, string $contents, string $fileName): array
    {
        $response = $this->request($instance)
            ->attach('file', $contents, $fileName)
            ->post($this->baseUrl($instance).'/documents/upload');

        if ($response->status() === 409) {
            return ['track_id' => '', 'already_present' => true];
        }

        $response->throw();

        return [
            'track_id' => $this->trackId($response->json('track_id')),
            'already_present' => false,
        ];
    }

    public function baseUrl(string $instance): string
    {
        $configured = config('intranet-app-bewerbungen.lightrag.instances.'.$instance);
        $url = is_array($configured) ? (string) ($configured['url'] ?? '') : '';

        return rtrim($url, '/');
    }

    private function request(string $instance): PendingRequest
    {
        $apiKey = trim((string) config('intranet-app-bewerbungen.lightrag.api_key'));
        if ($apiKey === '' || $this->baseUrl($instance) === '') {
            throw new RuntimeException('Die LightRAG-Instanz '.$instance.' ist nicht konfiguriert.');
        }

        return Http::withHeaders([
            'X-API-Key' => $apiKey,
            'Accept' => 'application/json',
        ])->timeout(180);
    }

    private function trackId(mixed $trackId): string
    {
        if (! is_string($trackId) || $trackId === '') {
            throw new RuntimeException('LightRAG hat keine track_id geliefert.');
        }

        return $trackId;
    }
}
