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
        $requestId = $this->zeichenkette($command, 'request_id');
        $token = $this->zeichenkette($command, 'token');
        if ($requestId === null && $token !== null && str_contains($kurz, 'TestDefinition')) {
            $requestId = 'test-'.$token;
        }

        return [
            'uuid' => $job->uuid,
            'job' => $kurz,
            'request_id' => $requestId,
            'bewerbung_id' => $this->ganzzahl($command, 'bewerbung_id'),
            'stelle_id' => $this->ganzzahl($command, 'stelle_id'),
            'test_name' => str_contains($kurz, 'TestDefinition') ? $this->zeichenkette($command, 'name') : null,
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

    private function ganzzahl(string $command, string $key): ?int
    {
        $key = preg_quote($key, '/');
        if (preg_match('/"'.$key.'";i:(\d+)/', $command, $treffer) === 1) {
            return (int) $treffer[1];
        }

        if (preg_match('/"'.$key.'";s:\d+:"(\d+)"/', $command, $treffer) === 1) {
            return (int) $treffer[1];
        }

        return null;
    }

    private function zeichenkette(string $command, string $key): ?string
    {
        $pattern = '/"'.preg_quote($key, '/').'";s:\d+:"([^"]+)"/';
        if (preg_match($pattern, $command, $treffer) !== 1) {
            return null;
        }

        return $treffer[1];
    }
}
