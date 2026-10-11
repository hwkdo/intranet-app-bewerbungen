<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppBewerbungen\Services;

use Hwkdo\IntranetAppBewerbungen\Support\AnalyseLaufProtokoll;

class KiPipelineMonitor
{
    public function __construct(
        private readonly BewerbungenKiQueueInspector $queue,
        private readonly LlamaCppSlotClient $llama,
        private readonly DgxHostMetricsClient $dgx,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $queue = $this->queue->snapshot();
        $letzte = AnalyseLaufProtokoll::letzte();
        $llama = $this->llama->snapshot();
        $worker = $this->worker();
        $laufende = $this->laufende($queue['reserved_jobs']);

        return [
            'queue' => $queue,
            'aktiv' => $laufende[0] ?? null,
            'laufend' => $laufende[0] ?? null,
            'laufende' => $laufende,
            'letzte' => $letzte,
            'llama' => $llama,
            'dgx' => $this->dgx->snapshot(),
            'worker' => $worker,
            'stufen' => $this->stufen($queue, $laufende, $letzte),
            'engpass' => $this->engpass($queue, $laufende, $llama, $worker['max_prozesse']),
            'befund' => $this->befund($letzte),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $letzte
     * @return array<string, mixed>
     */
    public function befund(?array $letzte = null): array
    {
        $letzte ??= AnalyseLaufProtokoll::letzte();
        $fertig = array_values(array_filter(
            $letzte,
            fn (array $lauf): bool => in_array($lauf['status'] ?? '', ['success', 'failed'], true),
        ));

        if ($fertig === []) {
            return [
                'anzahl' => 0,
                'champion' => null,
                'champion_label' => null,
                'mittel' => [],
                'text' => 'Noch kein abgeschlossener Lauf mit Phasenzeit in diesem Cache. Die Anteile erscheinen, sobald ein Analysejob den Fortschrittshaken durchläuft.',
            ];
        }

        $felder = [
            'queue' => 'queue_wait_ms',
            'graph' => 'graph_ms',
            'extraktion' => 'extraktion_ms',
            'llm' => 'llm_ms',
        ];
        $mittel = [];
        foreach ($felder as $name => $feld) {
            $werte = array_map(
                fn (array $lauf): int => (int) ($lauf[$feld] ?? 0),
                $fertig,
            );
            $mittel[$name] = (int) round(array_sum($werte) / count($werte));
        }

        $champion = array_keys($mittel, max($mittel))[0];
        $labels = [
            'queue' => 'Queue-Wartezeit',
            'graph' => 'Graph-Download',
            'extraktion' => 'Text-Extraktion',
            'llm' => 'LLM',
        ];
        $summe = max(1, array_sum($mittel));

        return [
            'anzahl' => count($fertig),
            'champion' => $champion,
            'champion_label' => $labels[$champion],
            'mittel' => $mittel,
            'anteile' => array_map(
                fn (int $ms): float => round($ms / $summe * 100, 1),
                $mittel,
            ),
            'text' => 'Unter den letzten '.count($fertig).' abgeschlossenen Läufen liegt der größte Zeitanteil bei '.$labels[$champion].'.',
        ];
    }

    /**
     * Engpass und Stufen, ohne Host-Metriken der DGX.
     *
     * @return array<string, mixed>
     */
    public function lage(): array
    {
        $queue = $this->queue->snapshot();
        $letzte = AnalyseLaufProtokoll::letzte();
        $llama = $this->llama->snapshot();
        $worker = $this->worker();
        $laufende = $this->laufende($queue['reserved_jobs']);

        return [
            'engpass' => $this->engpass($queue, $laufende, $llama, $worker['max_prozesse']),
            'stufen' => $this->stufen($queue, $laufende, $letzte),
            'worker' => $worker,
            'laufende_anzahl' => count($laufende),
            'erste_phase' => $laufende[0]['phase'] ?? null,
            'phasen' => array_map(
                fn (array $lauf): string => (string) ($lauf['phase'] ?? ''),
                $laufende,
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function laufendeListe(): array
    {
        $queue = $this->queue->snapshot();

        return $this->laufende($queue['reserved_jobs']);
    }

    /**
     * @return array{verfuegbar: bool, hinweis: mixed, pending: int, delayed: int, reserved: int, pending_jobs: mixed}
     */
    public function warteschlange(): array
    {
        $queue = $this->queue->snapshot();

        return [
            'verfuegbar' => (bool) ($queue['verfuegbar'] ?? false),
            'hinweis' => $queue['hinweis'] ?? null,
            'pending' => (int) ($queue['pending'] ?? 0),
            'delayed' => (int) ($queue['delayed'] ?? 0),
            'reserved' => (int) ($queue['reserved'] ?? 0),
            'pending_jobs' => is_array($queue['pending_jobs'] ?? null) ? $queue['pending_jobs'] : [],
        ];
    }

    public function queuePosition(string $token): ?int
    {
        if ($token === '') {
            return null;
        }

        $jobs = $this->warteschlange()['pending_jobs'];
        if (! is_array($jobs)) {
            return null;
        }

        foreach (array_values($jobs) as $index => $job) {
            if (! is_array($job)) {
                continue;
            }

            if (($job['token'] ?? null) === $token || ($job['request_id'] ?? null) === 'test-'.$token) {
                return $index + 1;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $queue
     * @param  list<array<string, mixed>>  $laufende
     * @param  list<array<string, mixed>>  $letzte
     * @return array<string, int>
     */
    private function stufen(array $queue, array $laufende, array $letzte): array
    {
        $phasen = array_count_values(array_map(
            fn (array $lauf): string => (string) ($lauf['phase'] ?? ''),
            $laufende,
        ));

        return [
            'wartend' => (int) ($queue['pending'] ?? 0),
            'laufend' => (int) ($queue['reserved'] ?? 0),
            'graph' => (int) ($phasen['graph'] ?? 0),
            'extraktion' => (int) ($phasen['extraktion'] ?? 0),
            'llm' => (int) ($phasen['llm'] ?? 0),
            'fertig' => count(array_filter($letzte, fn (array $lauf): bool => ($lauf['status'] ?? '') === 'success')),
            'fehlgeschlagen' => count(array_filter($letzte, fn (array $lauf): bool => ($lauf['status'] ?? '') === 'failed')),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $reserviert
     * @return list<array<string, mixed>>
     */
    private function laufende(array $reserviert): array
    {
        $protokolle = [];
        foreach (AnalyseLaufProtokoll::aktive() as $lauf) {
            $id = (string) ($lauf['request_id'] ?? '');
            if ($id !== '') {
                $protokolle[$id] = $lauf;
            }
        }

        if ($reserviert === []) {
            $liste = [];
            foreach ($protokolle as $aktiv) {
                $liste[] = $this->laufEintrag($aktiv, null);
            }

            return $liste;
        }

        $liste = [];
        foreach ($reserviert as $job) {
            $id = (string) ($job['request_id'] ?? '');
            $aktiv = null;
            if ($id !== '') {
                $aktiv = $protokolle[$id] ?? AnalyseLaufProtokoll::fuer($id);
            }
            $liste[] = $this->laufEintrag(is_array($aktiv) ? $aktiv : null, $job);
        }

        return $liste;
    }

    /**
     * @param  array<string, mixed>|null  $aktiv
     * @param  array<string, mixed>|null  $job
     * @return array<string, mixed>
     */
    private function laufEintrag(?array $aktiv, ?array $job): array
    {
        $job ??= [];

        return [
            'uuid' => $job['uuid'] ?? null,
            'request_id' => $aktiv['request_id'] ?? ($job['request_id'] ?? null),
            'bewerbung_id' => $aktiv['bewerbung_id'] ?? ($job['bewerbung_id'] ?? null),
            'stelle_id' => $aktiv['stelle_id'] ?? ($job['stelle_id'] ?? null),
            'test_name' => $job['test_name'] ?? ($aktiv['test_name'] ?? null),
            'test_label' => $aktiv['test_label'] ?? ($job['test_label'] ?? null),
            'quelle' => $aktiv['quelle'] ?? ($job['quelle'] ?? 'stelle'),
            'phase' => $aktiv['phase'] ?? 'queue',
            'schritt' => $aktiv['schritt'] ?? 'In der Queue reserviert',
            'schritte' => $aktiv['schritte'] ?? [],
            'started_at' => $aktiv['started_at'] ?? ($job['created_at'] ?? null),
            'queue_wait_ms' => $aktiv['queue_wait_ms'] ?? ($job['warte_ms'] ?? null),
            'job' => $job['job'] ?? 'AnalyzeLegacyBewerbungJob',
        ];
    }

    /**
     * @param  array<string, mixed>  $queue
     * @param  list<array<string, mixed>>  $laufende
     * @param  array<string, mixed>  $llama
     */
    private function engpass(array $queue, array $laufende, array $llama, int $worker): string
    {
        $belegt = (int) ($llama['slots_belegt'] ?? 0);
        $gesamt = (int) ($llama['slots_gesamt'] ?? 0);
        if ($gesamt > 0 && $belegt >= $gesamt) {
            return 'llm';
        }

        $phasen = array_map(
            fn (array $lauf): string => (string) ($lauf['phase'] ?? ''),
            $laufende,
        );
        foreach (['llm', 'extraktion', 'graph'] as $phase) {
            if (in_array($phase, $phasen, true)) {
                return $phase;
            }
        }

        if ((int) ($queue['pending'] ?? 0) > 0 && (int) ($queue['reserved'] ?? 0) >= $worker) {
            return 'queue';
        }

        return 'ruhig';
    }

    /**
     * @return array{max_prozesse: int, timeout: int, queue: string}
     */
    private function worker(): array
    {
        $defaults = config('horizon.defaults.supervisor-bewerbungen-ki', []);
        $env = config('horizon.environments.'.config('app.env').'.supervisor-bewerbungen-ki', []);
        if (! is_array($defaults)) {
            $defaults = [];
        }
        if (! is_array($env)) {
            $env = [];
        }

        return [
            'max_prozesse' => (int) ($env['maxProcesses'] ?? $defaults['maxProcesses'] ?? 1),
            'timeout' => (int) ($env['timeout'] ?? $defaults['timeout'] ?? 1210),
            'queue' => BewerbungenKiQueueInspector::QUEUE,
        ];
    }
}
