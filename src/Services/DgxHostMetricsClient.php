<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppBewerbungen\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class DgxHostMetricsClient
{
    private const CPU_KEY = 'bewerbungen-ki:dgx-cpu';

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $dcgm = trim((string) config('intranet-app-bewerbungen.pipeline.dcgm_metrics_url', ''));
        $node = trim((string) config('intranet-app-bewerbungen.pipeline.node_exporter_url', ''));
        $key = trim((string) config('ai.providers.gemma-llama-cpp.key', ''));

        return Cache::remember('bewerbungen-ki:dgx:'.sha1($dcgm.'|'.$node.'|'.($key !== '' ? '1' : '0')), 5, function () use ($dcgm, $node, $key): array {
            if ($dcgm === '' && $node === '') {
                return $this->leer(
                    'DGX-Hostmetriken sind nicht angebunden. CPU, VRAM und gemeinsamer Speicher liegen nicht in llama.cpp.',
                    [
                        'BEWERBUNGEN_DGX_DCGM_METRICS_URL (z. B. https://dcgm.ai.hwkdo.com/metrics)',
                        'BEWERBUNGEN_DGX_NODE_EXPORTER_URL (z. B. https://node.ai.hwkdo.com/metrics)',
                    ],
                );
            }

            if ($key === '') {
                return $this->leer(
                    'DGX-Hostmetriken liegen hinter dem Traefik-API-Key. llama_cpp_api_key ist leer.',
                    ['llama_cpp_api_key'],
                );
            }

            $dcgmAntwort = $dcgm !== '' ? $this->lesen($dcgm, $key) : null;
            $nodeAntwort = $node !== '' ? $this->lesen($node, $key) : null;
            $dcgmBody = $dcgmAntwort['body'] ?? null;
            $nodeBody = $nodeAntwort['body'] ?? null;
            $ramGesamt = $this->gauge($nodeBody, 'node_memory_MemTotal_bytes');
            $ramFrei = $this->gauge($nodeBody, 'node_memory_MemAvailable_bytes');
            $vramBelegt = $this->gauge($dcgmBody, 'DCGM_FI_DEV_FB_USED');
            $vramFrei = $this->gauge($dcgmBody, 'DCGM_FI_DEV_FB_FREE');
            $dram = $this->dram($dcgmBody);
            $cpu = $this->cpu($nodeBody);
            $gpu = $this->gauge($dcgmBody, 'DCGM_FI_DEV_GPU_UTIL');
            $leistung = $this->gauge($dcgmBody, 'DCGM_FI_DEV_POWER_USAGE');
            $last = $this->gauge($nodeBody, 'node_load1');
            $ramBelegt = ($ramGesamt !== null && $ramFrei !== null) ? max(0, $ramGesamt - $ramFrei) : null;

            return [
                'verfuegbar' => $dcgmBody !== null || $nodeBody !== null,
                'lage' => $this->lage($gpu, $cpu['prozent'], $last, $cpu['kerne'], $dram['bandbreite_gb_s'], $ramBelegt, $ramGesamt),
                'hinweis' => $this->hinweis($dcgm, $node, $dcgmAntwort, $nodeAntwort),
                'fehlende_quellen' => array_values(array_filter([
                    $dcgm === '' ? 'BEWERBUNGEN_DGX_DCGM_METRICS_URL' : null,
                    $node === '' ? 'BEWERBUNGEN_DGX_NODE_EXPORTER_URL' : null,
                ])),
                'gpu_auslastung_prozent' => $gpu,
                'leistung_watt' => $leistung,
                'leistung_budget_watt' => (float) config('intranet-app-bewerbungen.pipeline.gpu_budget_watt', 140),
                'vram_belegt_mib' => $vramBelegt,
                'vram_frei_mib' => $vramFrei,
                'speicher_controller_prozent' => $this->gauge($dcgmBody, 'DCGM_FI_DEV_MEM_COPY_UTIL'),
                'dram_aktiv_prozent' => $dram['prozent'],
                'dram_bandbreite_gb_s' => $dram['bandbreite_gb_s'],
                'cpu_last_1m' => $last,
                'cpu_prozent' => $cpu['prozent'],
                'cpu_kerne' => $cpu['kerne'],
                'ram_belegt_bytes' => $ramBelegt,
                'ram_gesamt_bytes' => $ramGesamt,
                'dcgm_host' => $this->host($dcgm),
                'node_host' => $this->host($node),
            ];
        });
    }

    /**
     * @param  list<string>  $fehlendeQuellen
     * @return array<string, mixed>
     */
    private function leer(string $hinweis, array $fehlendeQuellen): array
    {
        return [
            'verfuegbar' => false,
            'lage' => null,
            'hinweis' => $hinweis,
            'fehlende_quellen' => $fehlendeQuellen,
            'gpu_auslastung_prozent' => null,
            'vram_belegt_mib' => null,
            'vram_frei_mib' => null,
            'leistung_watt' => null,
            'leistung_budget_watt' => null,
            'speicher_controller_prozent' => null,
            'dram_aktiv_prozent' => null,
            'dram_bandbreite_gb_s' => null,
            'cpu_last_1m' => null,
            'cpu_prozent' => null,
            'cpu_kerne' => null,
            'ram_belegt_bytes' => null,
            'ram_gesamt_bytes' => null,
        ];
    }

    /**
     * @return array{status: int|null, body: string|null}
     */
    private function lesen(string $url, string $key): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$key,
                'X-API-Key' => $key,
            ])->timeout(4)->connectTimeout(3)->get($url);
        } catch (Throwable) {
            return ['status' => null, 'body' => null];
        }

        if (! $response->successful()) {
            return ['status' => $response->status(), 'body' => null];
        }

        return ['status' => $response->status(), 'body' => $response->body()];
    }

    /**
     * @param  array{status: int|null, body: string|null}|null  $antwort
     */
    private function abgelehnt(?array $antwort): bool
    {
        return in_array($antwort['status'] ?? null, [401, 403], true);
    }

    /**
     * @param  array{status: int|null, body: string|null}|null  $dcgmAntwort
     * @param  array{status: int|null, body: string|null}|null  $nodeAntwort
     */
    private function hinweis(string $dcgm, string $node, ?array $dcgmAntwort, ?array $nodeAntwort): ?string
    {
        if ($this->abgelehnt($dcgmAntwort) || $this->abgelehnt($nodeAntwort)) {
            return 'Traefik hat die Hostmetriken abgelehnt. llama_cpp_api_key passt nicht zur Middleware ollama-apikey.';
        }

        $teile = [];
        if ($dcgm !== '' && ($dcgmAntwort['body'] ?? null) === null) {
            $teile[] = $this->quelleHinweis('DCGM', $dcgm, $dcgmAntwort);
        }
        if ($node !== '' && ($nodeAntwort['body'] ?? null) === null) {
            $teile[] = $this->quelleHinweis('Node-Exporter', $node, $nodeAntwort);
        }

        return $teile === [] ? null : implode(' ', $teile);
    }

    /**
     * @param  array{status: int|null, body: string|null}|null  $antwort
     */
    private function quelleHinweis(string $name, string $url, ?array $antwort): string
    {
        $host = $this->host($url) ?? $url;
        $status = $antwort['status'] ?? null;
        if ($status === null) {
            return $name.' ('.$host.') hat nicht geantwortet (Timeout oder keine Verbindung).';
        }

        return $name.' ('.$host.') antwortet mit HTTP '.$status.'.';
    }

    /**
     * @return array{prozent: float|null, bandbreite_gb_s: float|null}
     */
    private function dram(?string $body): array
    {
        $roh = $this->gauge($body, 'DCGM_FI_PROF_DRAM_ACTIVE');
        if ($roh === null) {
            return ['prozent' => null, 'bandbreite_gb_s' => null];
        }

        $anteil = $roh <= 1.0 ? $roh : $roh / 100;
        $peak = (float) config('intranet-app-bewerbungen.pipeline.dram_peak_gb_s', 273);

        return [
            'prozent' => round($anteil * 100, 1),
            'bandbreite_gb_s' => $peak > 0 ? round($anteil * $peak, 1) : null,
        ];
    }

    /**
     * @return array{prozent: float|null, kerne: int|null}
     */
    private function cpu(?string $body): array
    {
        if ($body === null || $body === '') {
            return ['prozent' => null, 'kerne' => null];
        }

        $idle = 0.0;
        $gesamt = 0.0;
        $kerne = [];

        foreach (explode("\n", $body) as $zeile) {
            if (! str_starts_with($zeile, 'node_cpu_seconds_total{')) {
                continue;
            }

            if (preg_match('/^node_cpu_seconds_total\{([^}]*)\}\s+(\S+)/', $zeile, $treffer) !== 1) {
                continue;
            }

            if (preg_match('/cpu="(\d+)"/', $treffer[1], $cpu) !== 1 || preg_match('/mode="([^"]+)"/', $treffer[1], $mode) !== 1) {
                continue;
            }

            $wert = (float) $treffer[2];
            $kerne[$cpu[1]] = true;
            $gesamt += $wert;
            if ($mode[1] === 'idle') {
                $idle += $wert;
            }
        }

        if ($kerne === []) {
            return ['prozent' => null, 'kerne' => null];
        }

        $vorher = Cache::get(self::CPU_KEY);
        Cache::put(self::CPU_KEY, ['idle' => $idle, 'gesamt' => $gesamt], now()->addMinutes(5));
        $prozent = null;
        if (is_array($vorher) && isset($vorher['idle'], $vorher['gesamt'])) {
            $deltaGesamt = $gesamt - (float) $vorher['gesamt'];
            $deltaIdle = $idle - (float) $vorher['idle'];
            if ($deltaGesamt >= 0.5 && $deltaIdle >= 0 && $deltaIdle <= $deltaGesamt) {
                $prozent = round((1 - $deltaIdle / $deltaGesamt) * 100, 1);
            }
        }

        return ['prozent' => $prozent, 'kerne' => count($kerne)];
    }

    private function lage(
        ?float $gpu,
        ?float $cpuProzent,
        ?float $last,
        ?int $kerne,
        ?float $bandbreite,
        ?float $ramBelegt,
        ?float $ramGesamt,
    ): string {
        $saetze = [];

        if ($gpu !== null && $gpu >= 50) {
            $saetze[] = 'Belegt ist die GPU.';
        } elseif ($gpu !== null) {
            $saetze[] = 'Die GPU hat Luft.';
        }

        if ($cpuProzent !== null && $cpuProzent < 30) {
            $saetze[] = 'Die CPU ist kaum ausgelastet.';
        } elseif ($cpuProzent !== null) {
            $saetze[] = 'Die CPU liegt bei '.number_format($cpuProzent, 0, ',', '.').' %.';
        } elseif ($last !== null && $kerne !== null && $kerne > 0) {
            $saetze[] = 'Die CPU-Last liegt bei '.number_format($last, 1, ',', '.').' auf '.$kerne.' Kernen.';
        }

        if ($ramBelegt !== null && $ramGesamt !== null && $ramGesamt > 0) {
            $saetze[] = 'Der Arbeitsspeicher ist zu '.round($ramBelegt / $ramGesamt * 100).' % belegt.';
        }

        if ($bandbreite !== null) {
            $saetze[] = 'Das Speicherinterface bewegt etwa '.number_format($bandbreite, 0, ',', '.').' GB/s.';
        } else {
            $saetze[] = 'Die LPDDR-Bandbreite fehlt in diesen Quellen.';
        }

        return implode(' ', $saetze);
    }

    private function gauge(?string $body, string $metric): ?float
    {
        if ($body === null || $body === '') {
            return null;
        }

        $pattern = '/^'.preg_quote($metric, '/').'(?:\{[^}]*\})?\s+([+-]?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?)/m';
        if (preg_match($pattern, $body, $treffer) !== 1) {
            return null;
        }

        return (float) $treffer[1];
    }

    private function host(string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? $host : null;
    }
}
