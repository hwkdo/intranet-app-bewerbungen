<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppBewerbungen\Services;

use Illuminate\Queue\Jobs\InspectedJob;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\Queue;
use Throwable;

class BewerbungenKiQueueInspector
{
    public const QUEUE = 'bewerbungen-ki';

    /**
     * @return array{
     *     verfuegbar: bool,
     *     hinweis: string|null,
     *     pending: int,
     *     delayed: int,
     *     reserved: int,
     *     pending_jobs: list<array<string, mixed>>,
     *     reserved_jobs: list<array<string, mixed>>
     * }
     */
    public function snapshot(): array
    {
        $leer = $this->leer('Die Queue bewerbungen-ki ist über Redis nicht lesbar.');

        try {
            $connection = Queue::connection('redis');
        } catch (Throwable) {
            return $leer;
        }

        if (! $connection instanceof RedisQueue) {
            return $this->leer('Die Redis-Queue ist in dieser Umgebung nicht der aktive Treiber.');
        }

        try {
            $pendingJobs = $connection->pendingJobs(self::QUEUE)
                ->take(20)
                ->map(fn (InspectedJob $job): array => $this->kurzinfo($job))
                ->values()
                ->all();
            $reservedJobs = $connection->reservedJobs(self::QUEUE)
                ->take(10)
                ->map(fn (InspectedJob $job): array => $this->kurzinfo($job))
                ->values()
                ->all();

            return [
                'verfuegbar' => true,
                'hinweis' => null,
                'pending' => $connection->pendingSize(self::QUEUE),
                'delayed' => $connection->delayedSize(self::QUEUE),
                'reserved' => $connection->reservedSize(self::QUEUE),
                'pending_jobs' => $pendingJobs,
                'reserved_jobs' => $reservedJobs,
            ];
        } catch (Throwable) {
            return $this->leer('Redis ist gerade nicht erreichbar.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function kurzinfo(InspectedJob $job): array
    {
        $command = (string) ($job->payload['data']['command'] ?? '');
        $name = (string) ($job->name ?? '');
        $kurz = str_contains($name, '\\') ? substr($name, (int) strrpos($name, '\\') + 1) : $name;
        $felder = $this->eigenschaften($command);
        $requestId = $this->alsString($felder['request_id'] ?? null);
        $token = $this->alsString($felder['token'] ?? null);
        if ($requestId === null && $token !== null && str_contains($kurz, 'TestDefinition')) {
            $requestId = 'test-'.$token;
        }

        return [
            'uuid' => $job->uuid,
            'job' => $kurz,
            'request_id' => $requestId,
            'bewerbung_id' => $this->alsInt($felder['bewerbung_id'] ?? null),
            'stelle_id' => $this->alsInt($felder['stelle_id'] ?? null),
            'token' => $token,
            'test_name' => str_contains($kurz, 'TestDefinition') ? $this->alsString($felder['name'] ?? null) : null,
            'test_label' => str_contains($kurz, 'TestDefinition') ? $this->alsString($felder['label'] ?? null) : null,
            'created_at' => $job->createdAt?->toIso8601String(),
            'warte_ms' => $job->createdAt !== null
                ? max(0, (int) round((microtime(true) - $job->createdAt->getTimestamp()) * 1000))
                : null,
        ];
    }

    /**
     * @return array{verfuegbar: bool, hinweis: string|null, pending: int, delayed: int, reserved: int, pending_jobs: list<array<string, mixed>>, reserved_jobs: list<array<string, mixed>>}
     */
    private function leer(string $hinweis): array
    {
        return [
            'verfuegbar' => false,
            'hinweis' => $hinweis,
            'pending' => 0,
            'delayed' => 0,
            'reserved' => 0,
            'pending_jobs' => [],
            'reserved_jobs' => [],
        ];
    }

    /**
     * Liest nur echte serialisierte Eigenschaften. Texte in Instruktionen,
     * Share-Links oder verschachtelten Feldern zählen nicht, auch nicht bei
     * protected Keys mit Null-Byte (`\0*\0token`). Die flachste Ebene gewinnt.
     *
     * @return array<string, int|string>
     */
    private function eigenschaften(string $command): array
    {
        $werte = [];
        $offset = 0;
        if ($command === '') {
            return [];
        }

        if ($command[0] === 'O' || $command[0] === 'a') {
            $this->leseWert($command, $offset, $werte, 0);
        } else {
            $this->lesePaare($command, $offset, $werte, 1, PHP_INT_MAX);
        }

        $flach = [];
        foreach ($werte as $name => $eintrag) {
            $flach[$name] = $eintrag['value'];
        }

        return $flach;
    }

    /**
     * @param  array<string, array{value: int|string, depth: int}>  $werte
     */
    private function lesePaare(string $command, int &$offset, array &$werte, int $depth, int $anzahl): void
    {
        for ($index = 0; $index < $anzahl; $index++) {
            $roh = $this->leseSchluessel($command, $offset);
            if ($roh === null) {
                return;
            }

            $name = $this->eigenschaftsname($roh);
            $davor = $offset;
            $wert = $this->leseWert($command, $offset, $werte, $depth);
            if ($offset === $davor) {
                return;
            }

            if (! is_string($wert) && ! is_int($wert)) {
                continue;
            }

            if (! in_array($name, ['request_id', 'token', 'name', 'bewerbung_id', 'stelle_id', 'label'], true)) {
                continue;
            }

            $bisher = $werte[$name]['depth'] ?? PHP_INT_MAX;
            if ($depth < $bisher) {
                $werte[$name] = ['value' => $wert, 'depth' => $depth];
            }
        }
    }

    private function leseSchluessel(string $command, int &$offset): ?string
    {
        if ($offset >= strlen($command)) {
            return null;
        }

        if ($command[$offset] === 's') {
            return $this->leseZeichenkette($command, $offset);
        }

        if (preg_match('/\Gi:(-?\d+);/', $command, $treffer, 0, $offset) === 1) {
            $offset += strlen($treffer[0]);

            return $treffer[1];
        }

        return null;
    }

    /**
     * @param  array<string, array{value: int|string, depth: int}>  $werte
     */
    private function leseWert(string $command, int &$offset, array &$werte, int $depth): mixed
    {
        if ($offset >= strlen($command)) {
            return null;
        }

        $typ = $command[$offset];
        if ($typ === 's') {
            return $this->leseZeichenkette($command, $offset);
        }

        if ($typ === 'i' && preg_match('/\Gi:(-?\d+);/', $command, $treffer, 0, $offset) === 1) {
            $offset += strlen($treffer[0]);

            return (int) $treffer[1];
        }

        if ($typ === 'N' && substr($command, $offset, 2) === 'N;') {
            $offset += 2;

            return null;
        }

        if (($typ === 'b' || $typ === 'd') && preg_match('/\G[bd]:[^;]*;/', $command, $treffer, 0, $offset) === 1) {
            $offset += strlen($treffer[0]);

            return null;
        }

        if ($typ === 'a' && preg_match('/\Ga:(\d+):\{/', $command, $treffer, 0, $offset) === 1) {
            $offset += strlen($treffer[0]);
            $this->lesePaare($command, $offset, $werte, $depth + 1, (int) $treffer[1]);
            $this->schliesseKlammer($command, $offset);

            return null;
        }

        if ($typ === 'O' && preg_match('/\GO:(\d+):"/', $command, $treffer, 0, $offset) === 1) {
            $start = $offset + strlen($treffer[0]);
            $classLaenge = (int) $treffer[1];
            if ($start + $classLaenge + 1 > strlen($command) || ($command[$start + $classLaenge] ?? '') !== '"') {
                $offset = strlen($command);

                return null;
            }

            $pos = $start + $classLaenge + 1;
            if (preg_match('/\G:(\d+):\{/', $command, $anzahl, 0, $pos) !== 1) {
                $offset = strlen($command);

                return null;
            }

            $offset = $pos + strlen($anzahl[0]);
            $this->lesePaare($command, $offset, $werte, $depth + 1, (int) $anzahl[1]);
            $this->schliesseKlammer($command, $offset);

            return null;
        }

        return null;
    }

    private function leseZeichenkette(string $command, int &$offset): ?string
    {
        if (preg_match('/\Gs:(\d+):"/', $command, $treffer, 0, $offset) !== 1) {
            return null;
        }

        $laenge = (int) $treffer[1];
        $start = $offset + strlen($treffer[0]);
        $ende = $start + $laenge;
        if ($ende + 1 >= strlen($command) || $command[$ende] !== '"' || $command[$ende + 1] !== ';') {
            return null;
        }

        $inhalt = substr($command, $start, $laenge);
        $offset = $ende + 2;

        return $inhalt;
    }

    private function schliesseKlammer(string $command, int &$offset): void
    {
        if (($command[$offset] ?? '') === '}') {
            $offset++;
        }
    }

    private function eigenschaftsname(string $key): string
    {
        $pos = strrpos($key, "\0");
        if ($pos === false) {
            return $key;
        }

        return substr($key, $pos + 1);
    }

    private function alsString(mixed $wert): ?string
    {
        if (is_int($wert)) {
            return (string) $wert;
        }

        if (! is_string($wert) || $wert === '') {
            return null;
        }

        return $wert;
    }

    private function alsInt(mixed $wert): ?int
    {
        if (is_int($wert)) {
            return $wert;
        }

        if (is_string($wert) && preg_match('/^\d+$/', $wert) === 1) {
            return (int) $wert;
        }

        return null;
    }
}
