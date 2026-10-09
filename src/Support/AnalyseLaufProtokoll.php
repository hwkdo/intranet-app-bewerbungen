<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppBewerbungen\Support;

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

    private const MAX_RECENT = 20;

    /** @var array<string, mixed> */
    private array $state = [];

    public function __construct(public readonly string $requestId) {}

    public static function cacheKey(string $requestId): string
    {
        return 'bewerbungen-ki:run:'.$requestId;
    }

    /**
     * @param  array{bewerbung_id?:int|null,stelle_id?:int|null,definition_id?:int|null,quelle?:string,queue_wait_ms?:int|null}  $meta
     */
    public function starten(array $meta): void
    {
        $this->state = [
            'request_id' => $this->requestId,
            'bewerbung_id' => isset($meta['bewerbung_id']) ? (int) $meta['bewerbung_id'] : null,
            'stelle_id' => isset($meta['stelle_id']) ? (int) $meta['stelle_id'] : null,
            'definition_id' => isset($meta['definition_id']) ? (int) $meta['definition_id'] : null,
            'quelle' => $meta['quelle'] ?? 'stelle',
            'status' => 'running',
            'phase' => 'queue',
            'schritt' => 'Job angenommen',
            'schritte' => [],
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
        $this->state['phase'] = $phase;
        $this->persist();
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
        foreach (array_keys($ids) as $requestId) {
            if (! is_string($requestId) || $requestId === '') {
                continue;
            }

            $state = Cache::get(self::cacheKey($requestId));
            if (! is_array($state) || ($state['status'] ?? '') !== 'running') {
                continue;
            }

            $laeufe[] = self::ohneInterneFelder($state);
        }

        return $laeufe;
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
            Cache::lock('bewerbungen-ki:active-lock', 5)->block(2, $aendern);
        } catch (Throwable) {
            $aendern();
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
