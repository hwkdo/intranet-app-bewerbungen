<?php

namespace Hwkdo\IntranetAppBewerbungen\Jobs;

use Hwkdo\IntranetAppBewerbungen\Services\LegacyBewerbungenAiCallbackService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Artisan;
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
     * @param  array{request_id:string,bewerbung_id:int,stelle_id:int|null,cloud_bewerbung_ro:string,cloud_anhang_ro:string|null,triggered_at?:string|null}  $payload
     */
    public function __construct(
        public array $payload
    ) {}

    public function handle(LegacyBewerbungenAiCallbackService $callbackService): void
    {
        $bewerbungId = (int) $this->payload['bewerbung_id'];
        $requestId = (string) $this->payload['request_id'];
        $start = microtime(true);
        $logContext = [
            'request_id' => $requestId,
            'bewerbung_id' => $bewerbungId,
            'stelle_id' => $this->payload['stelle_id'] ?? null,
            'queue' => $this->queue,
            'attempt' => method_exists($this, 'attempts') ? $this->attempts() : null,
        ];

        Log::info('Legacy KI Analyse gestartet', $logContext);

        try {
            $tempJson = storage_path('app/tmp_legacy_bewerbung_'.$requestId.'.json');
            $ausgabeBase = storage_path('app/legacy_ai_'.$requestId);
            $csv = $ausgabeBase.'.csv';
            $xlsx = $ausgabeBase.'.xlsx';

            Log::info('Legacy KI Analyse: Pfade vorbereitet', $logContext + [
                'temp_json' => $tempJson,
                'csv' => $csv,
                'xlsx' => $xlsx,
            ]);

            $input = [
                (string) $bewerbungId => array_filter([
                    'bewerbung_ro' => $this->payload['cloud_bewerbung_ro'],
                    'anhang_ro' => $this->payload['cloud_anhang_ro'] ?? null,
                ]),
            ];

            file_put_contents($tempJson, json_encode($input, JSON_THROW_ON_ERROR));
            Log::info('Legacy KI Analyse: Input-JSON geschrieben', $logContext + [
                'has_anhang_link' => ! empty($this->payload['cloud_anhang_ro']),
            ]);

            Log::info('Legacy KI Analyse: Command bewerbungen:auswerten-ai startet', $logContext);
            $exitCode = Artisan::call('bewerbungen:auswerten-ai', [
                'json-datei' => $tempJson,
                '--id' => (string) $bewerbungId,
                '--ausgabe' => $ausgabeBase,
            ]);
            $artisanOutput = Artisan::output();
            Log::info('Legacy KI Analyse: Command beendet', $logContext + [
                'exit_code' => $exitCode,
                'output_preview' => mb_substr($artisanOutput, 0, 1500),
            ]);

            $parsed = $this->parseSingleCsvResult($csv);
            Log::info('Legacy KI Analyse: CSV geparst', $logContext + [
                'parsed_status' => empty($parsed['fehler']) ? 'success' : 'failed',
                'hat_name' => ! empty($parsed['vorname']) || ! empty($parsed['nachname']),
            ]);

            @unlink($tempJson);
            @unlink($csv);
            @unlink($xlsx);
            Log::info('Legacy KI Analyse: Temporäre Dateien bereinigt', $logContext);

            $durationMs = (int) ((microtime(true) - $start) * 1000);

            Log::info('Legacy KI Analyse: Callback an Legacy wird gesendet', $logContext + [
                'duration_ms' => $durationMs,
            ]);
            $callbackService->sendResult([
                'request_id' => $requestId,
                'bewerbung_id' => $bewerbungId,
                'stelle_id' => $this->payload['stelle_id'] ?? null,
                'status' => empty($parsed['fehler']) ? 'success' : 'failed',
                'duration_ms' => $durationMs,
                'result' => $parsed,
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
            /** @var LegacyBewerbungenAiCallbackService $callbackService */
            $callbackService = app(LegacyBewerbungenAiCallbackService::class);
            $callbackService->sendResult([
                'request_id' => $requestId,
                'bewerbung_id' => $bewerbungId,
                'stelle_id' => $this->payload['stelle_id'] ?? null,
                'status' => 'failed',
                'error_message' => $exception?->getMessage() ?? 'Queue-Job fehlgeschlagen',
                'result' => [],
            ]);
            Log::info('Legacy KI Analyse: Fehler-Callback aus failed() gesendet', $logContext);
        } catch (Throwable $callbackException) {
            Log::error('Legacy KI Analyse: Fehler-Callback in failed() fehlgeschlagen', $logContext + [
                'callback_error' => $callbackException->getMessage(),
                'callback_exception_class' => $callbackException::class,
            ]);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function parseSingleCsvResult(string $csvPath): array
    {
        if (! is_file($csvPath)) {
            throw new \RuntimeException('CSV-Ausgabe nicht gefunden: '.$csvPath);
        }

        $lines = file($csvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false || count($lines) < 2) {
            throw new \RuntimeException('CSV-Ausgabe ist leer oder unvollständig.');
        }

        $headerLine = preg_replace('/^\xEF\xBB\xBF/', '', $lines[0]) ?? $lines[0];
        $headers = str_getcsv($headerLine, ';');
        $values = str_getcsv($lines[1], ';');

        if ($headers === false || $values === false) {
            throw new \RuntimeException('CSV konnte nicht geparst werden.');
        }

        $mapped = [];
        foreach ($headers as $i => $header) {
            $mapped[$header] = $values[$i] ?? null;
        }

        return [
            'id' => $mapped['ID'] ?? null,
            'nachname' => $mapped['Nachname'] ?? null,
            'vorname' => $mapped['Vorname'] ?? null,
            'hoechster_schulabschluss' => $mapped['Höchster Schulabschluss'] ?? null,
            'durchschnittsnote' => $mapped['Durchschnittsnote'] ?? null,
            'berufserfahrung_fachspezifisch' => $mapped['Berufserfahrung (fachspezifisch)'] ?? null,
            'fuehrerschein' => $mapped['Führerschein'] ?? null,
            'it_kenntnisse' => $mapped['IT-Kenntnisse'] ?? null,
            'letzte_schulform' => $mapped['Letzte Schulform'] ?? null,
            'luecken_im_lebenslauf' => $mapped['Lücken im Lebenslauf'] ?? null,
            'fehlstunden' => $mapped['Fehlstunden'] ?? null,
            'fehlstunden_unentschuldigt' => $mapped['Fehlstunden unentschuldigt'] ?? null,
            'auffaelligkeiten' => $mapped['Auffälligkeiten'] ?? null,
            'verarbeitete_zeugnisse' => $mapped['Verarbeitete Zeugnisse'] ?? null,
            'bewerbung_ro' => $mapped['OneDrive Link Bewerbung'] ?? null,
            'anhang_ro' => $mapped['OneDrive Link Anhang'] ?? null,
            'fehler' => $mapped['Fehler'] ?? null,
        ];
    }
}

