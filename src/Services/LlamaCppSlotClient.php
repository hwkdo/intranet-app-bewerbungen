<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppBewerbungen\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class LlamaCppSlotClient
{
    private const LIVE_KEY = 'bewerbungen-ki:llama-live';

    private const PREV_KEY = 'bewerbungen-ki:llama-prev';

    private const GAUGE_KEY = 'bewerbungen-ki:llama-gauge';

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $cached = Cache::get(self::LIVE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $snapshot = $this->frisch();
        Cache::put(self::LIVE_KEY, $snapshot, now()->addSecond());

        return $snapshot;
    }

    /**
     * @return array<string, mixed>
     */
    private function frisch(): array
    {
        $basis = $this->basisUrl();
        $host = $basis !== '' ? (parse_url($basis, PHP_URL_HOST) ?: $basis) : null;
        $ergebnis = [
            'erreichbar' => false,
            'host' => is_string($host) ? $host : null,
            'basis_gesetzt' => $basis !== '',
            'metrics_aktiv' => false,
            'metrics_hinweis' => null,
            'modell' => null,
            'slots_gesamt' => 0,
            'slots_belegt' => 0,
            'tokens_pro_sekunde' => null,
            'prompt_tokens_pro_sekunde' => null,
            'anfragen_verarbeitung' => null,
            'anfragen_zurueckgestellt' => null,
            'generierung_durchschnitt' => null,
            'prompt_durchschnitt' => null,
            'spec_akzeptanz_prozent' => null,
            'slots' => [],
            'hinweis' => null,
        ];

        if ($basis === '') {
            $ergebnis['hinweis'] = 'Keine llama.cpp-Basis-URL. config/ai.php (gemma-llama-cpp.url) oder BEWERBUNGEN_LLAMA_CPP_BASE_URL setzen.';

            return $ergebnis;
        }

        $key = trim((string) config('ai.providers.gemma-llama-cpp.key', ''));
        if ($key === '') {
            $ergebnis['hinweis'] = 'llama.cpp antwortet nur mit API-Key. llama_cpp_api_key ist leer.';

            return $ergebnis;
        }

        try {
            $slotsResponse = $this->anfrage($basis.'/slots', $key);
            $modelsResponse = $this->anfrage($basis.'/v1/models', $key);
        } catch (Throwable) {
            $ergebnis['hinweis'] = 'llama.cpp unter '.$ergebnis['host'].' ist nicht erreichbar.';

            return $ergebnis;
        }

        if ($slotsResponse->status() === 401 || $slotsResponse->status() === 403) {
            $ergebnis['hinweis'] = 'llama.cpp hat die Abfrage abgelehnt (HTTP '.$slotsResponse->status().').';

            return $ergebnis;
        }

        if (! $slotsResponse->successful()) {
            $ergebnis['hinweis'] = 'llama.cpp /slots antwortet mit HTTP '.$slotsResponse->status().'.';

            return $ergebnis;
        }

        $roh = $slotsResponse->json();
        if (! is_array($roh)) {
            $ergebnis['hinweis'] = 'llama.cpp /slots lieferte kein JSON.';

            return $ergebnis;
        }

        $metrics = $this->metriken($basis, $key);

        $slots = $this->slots($roh);
        $raten = $this->raten($slots);
        $modell = null;
        $models = $modelsResponse->json();
        if (is_array($models)) {
            $erste = $models['data'][0]['id'] ?? null;
            $modell = is_string($erste) ? $erste : null;
        }

        $ergebnis['erreichbar'] = true;
        $ergebnis['metrics_aktiv'] = (bool) ($metrics['aktiv'] ?? false);
        $ergebnis['metrics_hinweis'] = $metrics['hinweis'] ?? null;
        $ergebnis['anfragen_verarbeitung'] = $metrics['anfragen_verarbeitung'] ?? null;
        $ergebnis['anfragen_zurueckgestellt'] = $metrics['anfragen_zurueckgestellt'] ?? null;
        $ergebnis['generierung_durchschnitt'] = $metrics['generierung_durchschnitt'] ?? null;
        $ergebnis['prompt_durchschnitt'] = $metrics['prompt_durchschnitt'] ?? null;
        $ergebnis['spec_akzeptanz_prozent'] = $metrics['spec_akzeptanz_prozent'] ?? null;
        $ergebnis['modell'] = $modell;
        $ergebnis['slots_gesamt'] = count($slots);
        $ergebnis['slots_belegt'] = count(array_filter($slots, fn (array $slot): bool => $slot['is_processing']));
        $ergebnis['tokens_pro_sekunde'] = $raten['tokens_pro_sekunde'];
        $ergebnis['prompt_tokens_pro_sekunde'] = $raten['prompt_tokens_pro_sekunde'];
        $ergebnis['slots'] = $slots;

        return $ergebnis;
    }

    /**
     * @param  list<array<string, mixed>>  $slots
     * @return array{tokens_pro_sekunde: float|null, prompt_tokens_pro_sekunde: float|null}
     */
    private function raten(array &$slots): array
    {
        $jetzt = microtime(true);
        $vorher = Cache::get(self::PREV_KEY);
        $summe = 0.0;
        $promptSumme = 0.0;
        $hatGenerierung = false;
        $hatPrompt = false;

        foreach ($slots as $index => $slot) {
            $slots[$index]['tokens_pro_sekunde'] = null;
            $slots[$index]['prompt_tokens_pro_sekunde'] = null;
            if (! is_array($vorher) || ! isset($vorher['slots'][$slot['id']])) {
                continue;
            }

            $alt = $vorher['slots'][$slot['id']];
            $dt = $jetzt - (float) ($vorher['at'] ?? $jetzt);
            if ($dt < 1 || ($alt['id_task'] ?? null) !== $slot['id_task'] || ! $slot['is_processing']) {
                continue;
            }

            $delta = (int) $slot['n_decoded'] - (int) ($alt['n_decoded'] ?? 0);
            if ($delta >= 0) {
                $rate = round($delta / $dt, 1);
                $slots[$index]['tokens_pro_sekunde'] = $rate;
                $summe += $rate;
                $hatGenerierung = true;
            }

            $promptDelta = (int) $slot['n_prompt_tokens_processed'] - (int) ($alt['n_prompt_tokens_processed'] ?? 0);
            if ($promptDelta > 0) {
                $promptRate = round($promptDelta / $dt, 1);
                $slots[$index]['prompt_tokens_pro_sekunde'] = $promptRate;
                $promptSumme += $promptRate;
                $hatPrompt = true;
            }
        }

        $kompakt = [];
        foreach ($slots as $slot) {
            $kompakt[$slot['id']] = [
                'id_task' => $slot['id_task'],
                'n_decoded' => $slot['n_decoded'],
                'n_prompt_tokens_processed' => $slot['n_prompt_tokens_processed'],
            ];
        }
        Cache::put(self::PREV_KEY, ['at' => $jetzt, 'slots' => $kompakt], now()->addMinutes(5));

        return [
            'tokens_pro_sekunde' => $hatGenerierung ? round($summe, 1) : null,
            'prompt_tokens_pro_sekunde' => $hatPrompt ? round($promptSumme, 1) : null,
        ];
    }

    /**
     * @param  array<mixed>  $roh
     * @return list<array<string, mixed>>
     */
    private function slots(array $roh): array
    {
        $liste = array_is_list($roh) ? $roh : [$roh];
        $slots = [];

        foreach ($liste as $eintrag) {
            if (! is_array($eintrag) || ! isset($eintrag['id'])) {
                continue;
            }

            $decoded = $eintrag['next_token'][0]['n_decoded'] ?? null;

            $slots[] = [
                'id' => $eintrag['id'],
                'id_task' => $eintrag['id_task'] ?? null,
                'n_ctx' => isset($eintrag['n_ctx']) ? (int) $eintrag['n_ctx'] : null,
                'is_processing' => (bool) ($eintrag['is_processing'] ?? false),
                'n_prompt_tokens' => (int) ($eintrag['n_prompt_tokens'] ?? 0),
                'n_prompt_tokens_processed' => (int) ($eintrag['n_prompt_tokens_processed'] ?? 0),
                'n_decoded' => is_numeric($decoded) ? (int) $decoded : 0,
            ];
        }

        return $slots;
    }

    /**
     * @return array<string, mixed>
     */
    private function metriken(string $basis, string $key): array
    {
        try {
            $response = $this->anfrage($basis.'/metrics', $key, 'text/plain');
        } catch (Throwable) {
            return ['aktiv' => false, 'hinweis' => '/metrics ist nicht erreichbar.'];
        }

        if ($response->status() === 501) {
            return ['aktiv' => false, 'hinweis' => 'Der Server unterstützt /metrics nicht. llama.cpp braucht den Startparameter --metrics.'];
        }

        if (! $response->successful()) {
            return ['aktiv' => false, 'hinweis' => '/metrics antwortet mit HTTP '.$response->status().'.'];
        }

        $werte = $this->prometheus($response->body());
        $gemerkt = Cache::get(self::GAUGE_KEY, []);
        if (! is_array($gemerkt)) {
            $gemerkt = [];
        }

        $generierung = $werte['llamacpp:predicted_tokens_seconds'] ?? null;
        $prompt = $werte['llamacpp:prompt_tokens_seconds'] ?? null;
        if (is_float($generierung) && $generierung > 0) {
            $gemerkt['generierung'] = round($generierung, 1);
        }
        if (is_float($prompt) && $prompt > 0) {
            $gemerkt['prompt'] = round($prompt, 1);
        }
        Cache::put(self::GAUGE_KEY, $gemerkt, now()->addHour());

        $entwurf = $werte['llamacpp:spec_decode_num_draft_tokens_total'] ?? null;
        $angenommen = $werte['llamacpp:spec_decode_num_accepted_tokens_total'] ?? null;
        $akzeptanz = null;
        if (is_float($entwurf) && $entwurf > 0 && is_float($angenommen)) {
            $akzeptanz = round($angenommen / $entwurf * 100, 1);
        }

        return [
            'aktiv' => true,
            'hinweis' => null,
            'anfragen_verarbeitung' => $this->ganzzahl($werte['llamacpp:requests_processing'] ?? null),
            'anfragen_zurueckgestellt' => $this->ganzzahl($werte['llamacpp:requests_deferred'] ?? null),
            'generierung_durchschnitt' => $gemerkt['generierung'] ?? null,
            'prompt_durchschnitt' => $gemerkt['prompt'] ?? null,
            'spec_akzeptanz_prozent' => $akzeptanz,
        ];
    }

    /**
     * @return array<string, float>
     */
    private function prometheus(string $body): array
    {
        $werte = [];

        foreach (explode("\n", $body) as $zeile) {
            if ($zeile === '' || str_starts_with($zeile, '#') || str_contains($zeile, '{')) {
                continue;
            }

            if (! preg_match('/^([a-zA-Z0-9_:]+)\s+([-+eE0-9.]+)/', $zeile, $treffer)) {
                continue;
            }

            $werte[$treffer[1]] = (float) $treffer[2];
        }

        return $werte;
    }

    private function ganzzahl(mixed $wert): ?int
    {
        if (! is_float($wert)) {
            return null;
        }

        return (int) round($wert);
    }

    private function anfrage(string $url, string $key, string $accept = 'application/json'): Response
    {
        return Http::withHeaders([
            'Authorization' => 'Bearer '.$key,
            'X-API-Key' => $key,
        ])->accept($accept)->timeout(4)->connectTimeout(3)->get($url);
    }

    private function basisUrl(): string
    {
        $override = trim((string) config('intranet-app-bewerbungen.pipeline.llama_base_url', ''));
        if ($override !== '') {
            return rtrim($override, '/');
        }

        $ai = rtrim((string) config('ai.providers.gemma-llama-cpp.url', ''), '/');

        return (string) preg_replace('#/v1$#', '', $ai);
    }
}
