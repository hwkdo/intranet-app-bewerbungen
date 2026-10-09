<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppBewerbungen\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class LegacyBewerbungLinksClient
{
    /**
     * @return array{bewerbung_ro: string, anhang_ro: string|null}
     */
    public function fetch(int $bewerbungId): array
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
            ->get($baseUrl.'/apps/bewerbungen/ai/bewerbung-links/'.$bewerbungId);

        if ($response->status() === 403) {
            throw new RuntimeException('Keine Berechtigung für die KI-Funktionen im Legacy-Intranet.');
        }

        if ($response->status() === 404) {
            throw new RuntimeException('Bewerbung '.$bewerbungId.' wurde im Legacy-Intranet nicht gefunden.');
        }

        $response->throw();
        $payload = $response->json();
        $link = is_array($payload) ? ($payload['cloud_bewerbung_ro'] ?? null) : null;
        if (! is_string($link) || $link === '') {
            throw new RuntimeException('Für Bewerbung '.$bewerbungId.' gibt es keinen OneDrive-Link.');
        }

        $anhang = is_array($payload) ? ($payload['cloud_anhang_ro'] ?? null) : null;

        return [
            'bewerbung_ro' => $link,
            'anhang_ro' => is_string($anhang) && $anhang !== '' ? $anhang : null,
        ];
    }

    /**
     * @return list<array{id: int, bezeichnung: string}>
     */
    public function stellen(): array
    {
        $payload = $this->getJson('/apps/bewerbungen/ai/stellen');
        $rows = is_array($payload['stellen'] ?? null) ? $payload['stellen'] : [];

        return array_values(array_map(function (mixed $row): array {
            $row = is_array($row) ? $row : [];

            return [
                'id' => (int) ($row['id'] ?? 0),
                'bezeichnung' => (string) ($row['bezeichnung'] ?? ''),
            ];
        }, $rows));
    }

    /**
     * @return list<array{id: int, label: string, hat_link: bool}>
     */
    public function bewerbungen(int $stelleId): array
    {
        $payload = $this->getJson('/apps/bewerbungen/ai/stellen/'.$stelleId.'/bewerbungen');
        $rows = is_array($payload['bewerbungen'] ?? null) ? $payload['bewerbungen'] : [];

        return array_values(array_map(function (mixed $row): array {
            $row = is_array($row) ? $row : [];
            $id = (int) ($row['id'] ?? 0);
            $name = trim(((string) ($row['vorname'] ?? '')).' '.((string) ($row['name'] ?? '')));
            $email = trim((string) ($row['email'] ?? ''));
            $hatLink = (bool) ($row['hat_link'] ?? false);
            $teile = array_values(array_filter([
                '#'.$id,
                $name !== '' ? $name : null,
                $email !== '' ? $email : null,
                $hatLink ? null : 'kein OneDrive-Link',
            ]));

            return [
                'id' => $id,
                'label' => implode(' · ', $teile),
                'hat_link' => $hatLink,
            ];
        }, $rows));
    }

    /**
     * @return array<string, mixed>
     */
    private function getJson(string $path): array
    {
        $baseUrl = rtrim((string) config('legacy.base_api_url'), '/');
        $token = (string) config('legacy.base_api_token');
        if ($baseUrl === '' || $token === '') {
            throw new RuntimeException('Legacy-API ist nicht konfiguriert.');
        }

        $response = Http::withoutVerifying()
            ->timeout(30)
            ->withToken($token)
            ->acceptJson()
            ->get($baseUrl.$path);

        if ($response->status() === 403) {
            throw new RuntimeException('Keine Berechtigung für die KI-Funktionen im Legacy-Intranet.');
        }

        if ($response->status() === 404) {
            throw new RuntimeException('Die Auswahl wurde im Legacy-Intranet nicht gefunden.');
        }

        $response->throw();
        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }
}
