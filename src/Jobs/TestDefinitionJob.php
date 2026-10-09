<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppBewerbungen\Jobs;

use Hwkdo\IntranetAppBewerbungen\Models\KiDefinition;
use Hwkdo\IntranetAppBewerbungen\Services\BewerbungAnalyseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

class TestDefinitionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 1200;

    public int $tries = 1;

    /**
     * @param  list<array<string, mixed>>  $felder
     */
    public function __construct(
        public string $token,
        public string $bewerbungRo,
        public ?string $anhangRo,
        public string $name,
        public string $instruktionen,
        public array $felder,
    ) {
        $this->onQueue('bewerbungen-ki');
    }

    public static function cacheKey(string $token): string
    {
        return 'bewerbungen-ki-test:'.$token;
    }

    public function handle(BewerbungAnalyseService $analyse): void
    {
        $definition = new KiDefinition([
            'name' => $this->name !== '' ? $this->name : 'Test',
            'instruktionen' => $this->instruktionen,
            'felder' => $this->felder,
        ]);

        try {
            $result = $analyse->analysiereLinks([
                'bewerbung_ro' => $this->bewerbungRo,
                'anhang_ro' => $this->anhangRo,
            ], $definition, false);
            Cache::put(self::cacheKey($this->token), [
                'status' => 'done',
                'result' => $result,
            ], now()->addHour());
        } catch (Throwable $exception) {
            Cache::put(self::cacheKey($this->token), [
                'status' => 'failed',
                'error' => $exception->getMessage(),
            ], now()->addHour());
        }
    }
}
