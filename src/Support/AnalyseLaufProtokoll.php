<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppBewerbungen\Support;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Schlanker Fortschritt eines Stellenanalyse-Jobs. Liegt im Cache, damit das
 * Cockpit pollen kann, ohne eine neue Tabelle und ohne die Auswertung zu ändern.
 */
final class AnalyseLaufProtokoll
{
    public const ACTIVE_KEY = 'bewerbungen-ki:active';

    public const ACTIVE_IDS_KEY = 'bewerbungen-ki:active-ids';

    public const RECENT_KEY = 'bewerbungen-ki:recent';

    private const MAX_SCHRITTE = 40;

    private const MAX_DOKUMENTE = 50;

    private const MAX_RECENT = 20;

    /** @var array<string, mixed> */
    private array $state = [];

    public function __construct(public readonly string $requestId) {}

    public static function cacheKey(string $requestId): string
    {
        return 'bewerbungen-ki:run:'.$requestId;
    }

    /**
     * @param  array{bewerbung_id?:int|null,stelle_id?:int|null,definition_id?:int|null,quelle?:string,queue_wait_ms?:int|null,test_label?:string|null,test_name?:string|null}  $meta
     */
    public function starten(array $meta): void
    {
        $this->state = [
            'request_id' => $this->requestId,
            'bewerbung_id' => isset($meta['bewerbung_id']) ? (int) $meta['bewerbung_id'] : null,
            'stelle_id' => isset($meta['stelle_id']) ? (int) $meta['stelle_id'] : null,
            'definition_id' => isset($meta['definition_id']) ? (int) $meta['definition_id'] : null,
            'quelle' => $meta['quelle'] ?? 'stelle',
            'test_label' => isset($meta['test_label']) && is_string($meta['test_label']) && $meta['test_label'] !== '' ? $meta['test_label'] : null,
            'test_name' => isset($meta['test_name']) && is_string($meta['test_name']) && $meta['test_name'] !== '' ? $meta['test_name'] : null,
            'status' => 'running',
            'phase' => 'queue',
            'phase_seit' => now()->toIso8601String(),
            'schritt' => 'Job angenommen',
            'schritte' => [],
            'dokumente' => [],
            'prompt' => null,
            'llm' => null,
            'parse' => null,
            'queue_wait_ms' => $meta['queue_wait_ms'] ?? null,
            'graph_ms' => 0,
            'extraktion_ms' => 0,
            'llm_ms' => 0,
            'started_at' => now()->toIso8601String(),
            'finished_at' => null,
            'duration_ms' => null,
            'fehler' => null,
            'updated_at' => now()->toIso8601String(),
        ];
        $this->schritt('queue', 'Job angenommen');
    }

    public function schritt(string $phase, string $text): void
    {
        if ($this->state === []) {
            $this->laden();
        }

        if (($this->state['phase'] ?? null) !== $phase || ! isset($this->state['phase_seit'])) {
            $this->state['phase_seit'] = now()->toIso8601String();
        }
        $this->state['phase'] = $phase;
        $this->state['schritt'] = $this->bereinigen($text);
        $schritte = $this->state['schritte'] ?? [];
        $schritte[] = [
            'at' => now()->toIso8601String(),
            'phase' => $phase,
            'text' => $this->state['schritt'],
        ];
        if (count($schritte) > self::MAX_SCHRITTE) {
            $schritte = array_slice($schritte, -self::MAX_SCHRITTE);
        }
        $this->state['schritte'] = array_values($schritte);
        $this->persist();
    }

    public function addMs(string $phase, int $ms): void
    {
        if ($this->state === []) {
            $this->laden();
        }

        $feld = match ($phase) {
            'graph' => 'graph_ms',
            'extraktion' => 'extraktion_ms',
            'llm' => 'llm_ms',
            default => null,
        };

        if ($feld === null) {
            return;
        }

        $this->state[$feld] = (int) ($this->state[$feld] ?? 0) + max(0, $ms);
        if (($this->state['phase'] ?? null) !== $phase || ! isset($this->state['phase_seit'])) {
            $this->state['phase_seit'] = now()->toIso8601String();
        }
        $this->state['phase'] = $phase;
        $this->persist();
    }

    /**
     * @param  array{name?:string,quelle?:string,aus_cache?:bool,zeichen?:int,plausibel?:bool,qualitaet?:string,graph_ms?:int,extraktion_ms?:int,verwendet?:bool}  $info
     */
    public function dokument(array $info): void
    {
        if ($this->state === []) {
            $this->laden();
        }

        $dokumente = is_array($this->state['dokumente'] ?? null) ? $this->state['dokumente'] : [];
        $dokumente[] = [
            'name' => mb_substr((string) ($info['name'] ?? ''), 0, 180),
            'quelle' => (string) ($info['quelle'] ?? ''),
            'aus_cache' => (bool) ($info['aus_cache'] ?? false),
            'zeichen' => (int) ($info['zeichen'] ?? 0),
            'plausibel' => (bool) ($info['plausibel'] ?? false),
            'qualitaet' => $this->bereinigen((string) ($info['qualitaet'] ?? '')),
            'graph_ms' => (int) ($info['graph_ms'] ?? 0),
            'extraktion_ms' => (int) ($info['extraktion_ms'] ?? 0),
            'verwendet' => (bool) ($info['verwendet'] ?? false),
        ];
        if (count($dokumente) > self::MAX_DOKUMENTE) {
            $dokumente = array_slice($dokumente, -self::MAX_DOKUMENTE);
        }
        $this->state['dokumente'] = array_values($dokumente);
        $this->persist();
    }

    /**
     * @param  array{zeichen?:int,dokumente?:int,gekuerzt?:bool,grenze?:int}  $prompt
     */
    public function promptSetzen(array $prompt): void
    {
        if ($this->state === []) {
            $this->laden();
        }

        $this->state['prompt'] = [
            'zeichen' => (int) ($prompt['zeichen'] ?? 0),
            'dokumente' => (int) ($prompt['dokumente'] ?? 0),
            'gekuerzt' => (bool) ($prompt['gekuerzt'] ?? false),
            'grenze' => (int) ($prompt['grenze'] ?? 0),
        ];
        $this->persist();
    }

    /**
     * @param  array<string, mixed>  $llm
     */
    public function llmSetzen(array $llm): void
    {
        if ($this->state === []) {
            $this->laden();
        }

        $bisher = is_array($this->state['llm'] ?? null) ? $this->state['llm'] : [];
        $this->state['llm'] = [
            'provider' => isset($llm['provider']) && (string) $llm['provider'] !== '' ? (string) $llm['provider'] : ($bisher['provider'] ?? null),
            'modell' => isset($llm['modell']) && (string) $llm['modell'] !== '' ? (string) $llm['modell'] : ($bisher['modell'] ?? null),
            'input_tokens' => isset($llm['input_tokens']) ? (int) $llm['input_tokens'] : null,
            'output_tokens' => isset($llm['output_tokens']) ? (int) $llm['output_tokens'] : null,
            'tokens_pro_sekunde' => isset($llm['tokens_pro_sekunde']) && is_numeric($llm['tokens_pro_sekunde']) ? (float) $llm['tokens_pro_sekunde'] : null,
            'prompt_tokens_pro_sekunde' => isset($llm['prompt_tokens_pro_sekunde']) && is_numeric($llm['prompt_tokens_pro_sekunde']) ? (float) $llm['prompt_tokens_pro_sekunde'] : null,
            'prompt_ms' => isset($llm['prompt_ms']) ? (int) $llm['prompt_ms'] : null,
        ];
        $this->persist();
    }

    public function textPlanen(?string $provider, ?string $modell): void
    {
        if ($this->state === []) {
            $this->laden();
        }

        $aktuell = is_array($this->state['llm'] ?? null) ? $this->state['llm'] : [];
        if ($aktuell !== [] && (isset($aktuell['input_tokens']) || isset($aktuell['output_tokens']))) {
            return;
        }

        $aktuell['provider'] = $provider;
        $aktuell['modell'] = $modell;
        $this->state['llm'] = $aktuell;
        $this->persist();
    }

    public function parseSetzen(string $engine, ?string $tier, ?string $provider = null, ?string $modell = null): void
    {
        if ($this->state === []) {
            $this->laden();
        }

        $bisher = is_array($this->state['parse'] ?? null) ? $this->state['parse'] : [];
        $this->state['parse'] = [
            'engine' => $engine,
            'tier' => $tier,
            'provider' => $provider ?? ($bisher['provider'] ?? null),
            'modell' => $modell ?? ($bisher['modell'] ?? null),
        ];
        $this->persist();
    }

    /**
     * Lauf inklusive abgeschlossener Zustände. Für die Live-Karte nach dem Ende.
     *
     * @return array<string, mixed>|null
     */
    public static function zustand(string $requestId): ?array
    {
        if ($requestId === '') {
            return null;
        }

        $state = Cache::get(self::cacheKey($requestId));
        if (! is_array($state)) {
            return null;
        }

        return self::ohneInterneFelder($state);
    }

    /**
     * Phasen-ms inklusive der noch laufenden Phase (now − phase_seit).
     *
     * @param  array<string, mixed>  $lauf
     * @return array{ms: array<string, int>, anteile: array<string, float>, summe: int}
     */
    public static function liveAnteile(array $lauf): array
    {
        $ms = [
            'queue' => (int) ($lauf['queue_wait_ms'] ?? 0),
            'graph' => (int) ($lauf['graph_ms'] ?? 0),
            'extraktion' => (int) ($lauf['extraktion_ms'] ?? 0),
            'llm' => (int) ($lauf['llm_ms'] ?? 0),
        ];

        $phase = (string) ($lauf['phase'] ?? '');
        $seit = $lauf['phase_seit'] ?? null;
        if (($lauf['status'] ?? '') === 'running' && isset($ms[$phase]) && is_string($seit) && $seit !== '') {
            $zeit = strtotime($seit);
            if ($zeit !== false) {
                $ms[$phase] = max($ms[$phase], (int) round((microtime(true) - $zeit) * 1000));
            }
        }

        $summe = array_sum($ms);
        $teiler = max(1, $summe);
        $anteile = [];
        foreach ($ms as $name => $wert) {
            $anteile[$name] = round($wert / $teiler * 100, 1);
        }

        return [
            'ms' => $ms,
            'anteile' => $anteile,
            'summe' => $summe,
        ];
    }

    public function abschliessen(string $status, ?string $fehler = null): void
    {
        if ($this->state === []) {
            $this->laden();
        }

        if ($this->state === [] || ($this->state['status'] ?? '') !== 'running') {
            return;
        }

        $this->state['status'] = $status === 'success' ? 'success' : 'failed';
        $this->state['finished_at'] = now()->toIso8601String();
        $this->state['fehler'] = $fehler !== null ? $this->bereinigen($fehler) : null;
        $started = $this->state['started_at'] ?? null;
        if (is_string($started)) {
            $this->state['duration_ms'] = max(0, (int) round((microtime(true) - strtotime($started)) * 1000));
        }
        $this->persist();

        $recent = Cache::get(self::RECENT_KEY, []);
        if (! is_array($recent)) {
            $recent = [];
        }
        array_unshift($recent, $this->oeffentlich());
        Cache::put(self::RECENT_KEY, array_slice($recent, 0, self::MAX_RECENT), now()->addDays(7));

        $this->aktivSetzen(false);
    }

    public static function fehlgeschlagenMarkieren(string $requestId, string $fehler): void
    {
        if ($requestId === '') {
            return;
        }

        (new self($requestId))->abschliessen('failed', $fehler);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function aktiv(): ?array
    {
        return self::aktive()[0] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function aktive(): array
    {
        $ids = Cache::get(self::ACTIVE_IDS_KEY, []);
        if (! is_array($ids)) {
            $ids = [];
        }

        $legacy = Cache::get(self::ACTIVE_KEY);
        if (is_array($legacy) && is_string($legacy['request_id'] ?? null)) {
            $ids[$legacy['request_id']] = true;
        }

        $laeufe = [];
        foreach ($ids as $requestId => $markiert) {
            if ($markiert === false) {
                continue;
            }

            $requestId = trim((string) $requestId);
            if ($requestId === '') {
                continue;
            }

            $state = self::fuer($requestId);
            if ($state === null) {
                continue;
            }

            $laeufe[] = $state;
        }

        return $laeufe;
    }

    /**
     * Laufendes Protokoll direkt über den Run-Key, unabhängig von der Active-ID-Liste.
     *
     * @return array<string, mixed>|null
     */
    public static function fuer(string $requestId): ?array
    {
        if ($requestId === '') {
            return null;
        }

        $state = Cache::get(self::cacheKey($requestId));
        if (! is_array($state) || ($state['status'] ?? '') !== 'running') {
            return null;
        }

        return self::ohneInterneFelder($state);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function letzte(): array
    {
        $recent = Cache::get(self::RECENT_KEY, []);

        if (! is_array($recent)) {
            return [];
        }

        return array_values(array_filter($recent, is_array(...)));
    }

    /**
     * @return array<string, mixed>
     */
    public function oeffentlich(): array
    {
        if ($this->state === []) {
            $this->laden();
        }

        return self::ohneInterneFelder($this->state);
    }

    private function laden(): void
    {
        $state = Cache::get(self::cacheKey($this->requestId));
        if (is_array($state)) {
            $this->state = $state;
        }
    }

    private function persist(): void
    {
        $this->state['updated_at'] = now()->toIso8601String();
        Cache::put(self::cacheKey($this->requestId), $this->state, now()->addHours(6));
        $this->aktivSetzen(($this->state['status'] ?? '') === 'running');
    }

    private function aktivSetzen(bool $laeuft): void
    {
        $aendern = function () use ($laeuft): void {
            $ids = Cache::get(self::ACTIVE_IDS_KEY, []);
            if (! is_array($ids)) {
                $ids = [];
            }

            if ($laeuft) {
                $ids[$this->requestId] = true;
            } else {
                unset($ids[$this->requestId]);
            }

            Cache::put(self::ACTIVE_IDS_KEY, $ids, now()->addHours(6));

            $legacy = Cache::get(self::ACTIVE_KEY);
            if (! $laeuft && is_array($legacy) && ($legacy['request_id'] ?? null) === $this->requestId) {
                Cache::forget(self::ACTIVE_KEY);
            }
        };

        try {
            Cache::lock('bewerbungen-ki:active-lock', 5)->block(3, $aendern);
        } catch (LockTimeoutException) {
            try {
                Cache::lock('bewerbungen-ki:active-lock', 5)->block(3, $aendern);
            } catch (LockTimeoutException) {
                // Die ID-Liste bleibt unverändert. Das Cockpit liest den Lauf über den Run-Key.
            }
        } catch (Throwable) {
            // Kein Schreiben ohne Lock: sonst überschreibt der zweite Job die ID des ersten.
        }
    }

    private function bereinigen(string $text): string
    {
        $text = preg_replace('#https?://\S+#', '[link]', $text) ?? $text;
        $text = preg_replace('/(api[_-]?key|bearer|token)\s*[:=]\s*\S+/i', '$1 [redacted]', $text) ?? $text;

        return mb_substr(trim($text), 0, 300);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private static function ohneInterneFelder(array $state): array
    {
        unset($state['phase_started_at']);

        return $state;
    }
}
