<?php

namespace Hwkdo\IntranetAppBewerbungen\Jobs;

use Hwkdo\IntranetAppBewerbungen\Services\BewerbungAnalyseService;
use Hwkdo\IntranetAppBewerbungen\Services\LegacyBewerbungenAiCallbackService;
use Hwkdo\IntranetAppBewerbungen\Support\KiDefinitionResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Log;
use Throwable;

class AnalyzeLegacyBewerbungJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 1200;

    public int $tries = 3;

    public bool $failOnTimeout = true;

    /**
     * @param  array{request_id:string,bewerbung_id:int,stelle_id:int|null,definition_id?:int|null,cloud_bewerbung_ro:string,cloud_anhang_ro:string|null,triggered_at?:string|null}  $payload
     */
    public function __construct(
        public array $payload
    ) {
        $this->onQueue('bewerbungen-ki');
    }

    public function handle(
        LegacyBewerbungenAiCallbackService $callbackService,
        BewerbungAnalyseService $analyse,
        KiDefinitionResolver $definitionen,
    ): void {
        $bewerbungId = (int) $this->payload['bewerbung_id'];
        $requestId = (string) $this->payload['request_id'];
        $start = microtime(true);
        $logContext = [
            'request_id' => $requestId,
            'bewerbung_id' => $bewerbungId,
            'stelle_id' => $this->payload['stelle_id'] ?? null,
            'definition_id' => $this->payload['definition_id'] ?? null,
            'queue' => $this->queue,
            'attempt' => method_exists($this, 'attempts') ? $this->attempts() : null,
        ];

        Log::info('Legacy KI Analyse gestartet', $logContext);

        try {
            $definitionId = isset($this->payload['definition_id']) ? (int) $this->payload['definition_id'] : null;
            $aufgeloest = $definitionen->resolve($definitionId !== null && $definitionId > 0 ? $definitionId : null);
            Log::info('Legacy KI Analyse: Definition aufgelöst', $logContext + [
                'definition' => $aufgeloest['definition']->name,
                'fallback' => $aufgeloest['fallback'],
            ]);

            $envelope = $analyse->analysiereLinks(
                [
                    'bewerbung_ro' => (string) $this->payload['cloud_bewerbung_ro'],
                    'anhang_ro' => $this->payload['cloud_anhang_ro'] ?? null,
                ],
                $aufgeloest['definition'],
                $aufgeloest['fallback'],
            );

            $durationMs = (int) ((microtime(true) - $start) * 1000);
            $callbackService->sendResult([
                'request_id' => $requestId,
                'bewerbung_id' => $bewerbungId,
                'stelle_id' => $this->payload['stelle_id'] ?? null,
                'definition_id' => $aufgeloest['definition']->id,
                'definition_name' => $aufgeloest['definition']->name,
                'status' => 'success',
                'duration_ms' => $durationMs,
                'result' => $envelope,
            ]);
            Log::info('Legacy KI Analyse erfolgreich abgeschlossen', $logContext + [
                'duration_ms' => $durationMs,
            ]);
        } catch (Throwable $e) {
            $durationMs = (int) ((microtime(true) - $start) * 1000);
            Log::error('Legacy KI Analyse fehlgeschlagen', $logContext + [
                'error' => $e->getMessage(),
                'exception_class' => $e::class,
                'duration_ms' => $durationMs,
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $requestId = (string) ($this->payload['request_id'] ?? '');
        $bewerbungId = (int) ($this->payload['bewerbung_id'] ?? 0);
        $logContext = [
            'request_id' => $requestId !== '' ? $requestId : null,
            'bewerbung_id' => $bewerbungId > 0 ? $bewerbungId : null,
            'stelle_id' => $this->payload['stelle_id'] ?? null,
            'attempt' => method_exists($this, 'attempts') ? $this->attempts() : null,
            'exception_class' => $exception ? $exception::class : null,
            'error' => $exception?->getMessage(),
        ];

        if ($exception instanceof TimeoutExceededException || $exception instanceof MaxAttemptsExceededException) {
            Log::error('Legacy KI Analyse final fehlgeschlagen (Timeout/Attempts)', $logContext);
        } else {
            Log::error('Legacy KI Analyse final fehlgeschlagen', $logContext + [
                'trace' => $exception?->getTraceAsString(),
            ]);
        }

        if ($requestId === '' || $bewerbungId <= 0) {
            Log::warning('Legacy KI Analyse: Fehler-Callback übersprungen, Payload unvollständig', $logContext);

            return;
        }

        try {
            app(LegacyBewerbungenAiCallbackService::class)->sendResult([
                'request_id' => $requestId,
                'bewerbung_id' => $bewerbungId,
                'stelle_id' => $this->payload['stelle_id'] ?? null,
                'definition_id' => $this->payload['definition_id'] ?? null,
                'status' => 'failed',
                'error_message' => $exception?->getMessage() ?? 'Queue-Job fehlgeschlagen',
                'result' => [
                    'definition' => null,
                    'fields' => [],
                    'system' => [],
                ],
            ]);
            Log::info('Legacy KI Analyse: Fehler-Callback aus failed() gesendet', $logContext);
        } catch (Throwable $callbackException) {
            Log::error('Legacy KI Analyse: Fehler-Callback in failed() fehlgeschlagen', $logContext + [
                'callback_error' => $callbackException->getMessage(),
                'callback_exception_class' => $callbackException::class,
            ]);
        }
    }
}
