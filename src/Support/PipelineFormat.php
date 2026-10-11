<?php

declare(strict_types=1);

namespace Hwkdo\IntranetAppBewerbungen\Support;

use Hwkdo\IntranetAppBase\Enums\AiProvider;
use Hwkdo\IntranetAppBase\Enums\DocumentParseEngine;
use Illuminate\Support\Carbon;

final class PipelineFormat
{
    /**
     * @var array<string, string>
     */
    public const PHASEN = [
        'queue' => 'Queue',
        'graph' => 'Graph',
        'extraktion' => 'Extraktion',
        'llm' => 'LLM',
    ];

    public static function dauer(?int $ms): string
    {
        if ($ms === null) {
            return '–';
        }

        if ($ms < 1000) {
            return $ms.' ms';
        }

        return number_format($ms / 1000, 1, ',', '.').' s';
    }

    public static function zahl(?float $wert, string $einheit): string
    {
        if ($wert === null) {
            return '–';
        }

        $text = number_format($wert, 1, ',', '.');

        return $einheit === '' ? $text : $text.' '.$einheit;
    }

    public static function gib(?float $bytes): string
    {
        if ($bytes === null) {
            return '–';
        }

        return number_format($bytes / 1024 / 1024 / 1024, 1, ',', '.').' GiB';
    }

    public static function balken(?float $wert, ?float $ganz = null): float
    {
        if ($wert === null) {
            return 0;
        }

        $prozent = $ganz === null || $ganz <= 0 ? $wert : ($wert / $ganz * 100);

        return max(0, min(100, $prozent));
    }

    /**
     * @param  array<string, mixed>  $dgx
     */
    public static function cpuBalken(array $dgx): float
    {
        if (is_numeric($dgx['cpu_prozent'] ?? null)) {
            return self::balken((float) $dgx['cpu_prozent']);
        }

        $kerne = $dgx['cpu_kerne'] ?? null;
        $last = $dgx['cpu_last_1m'] ?? null;
        if (is_numeric($kerne) && (float) $kerne > 0 && is_numeric($last)) {
            return self::balken(((float) $last / (float) $kerne) * 100);
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $dgx
     */
    public static function cpuText(array $dgx): string
    {
        $kerne = is_numeric($dgx['cpu_kerne'] ?? null) ? ' auf '.$dgx['cpu_kerne'].' Kernen' : '';
        if (is_numeric($dgx['cpu_prozent'] ?? null)) {
            return self::zahl((float) $dgx['cpu_prozent'], '%').$kerne;
        }

        $last = is_numeric($dgx['cpu_last_1m'] ?? null) ? (float) $dgx['cpu_last_1m'] : null;

        return 'Last '.self::zahl($last, '').$kerne;
    }

    public static function phaseLabel(string $phase): string
    {
        return self::PHASEN[$phase] ?? $phase;
    }

    public static function phaseFarbe(string $phase): string
    {
        return match ($phase) {
            'queue' => 'bg-amber-500',
            'graph' => 'bg-sky-500',
            'extraktion' => 'bg-violet-500',
            'llm' => 'bg-emerald-500',
            default => 'bg-zinc-400',
        };
    }

    public static function uhr(?string $iso): string
    {
        if ($iso === null || $iso === '') {
            return '–';
        }

        return Carbon::parse($iso)->timezone(config('app.timezone'))->format('H:i:s');
    }

    public static function relativ(?string $iso): string
    {
        if ($iso === null || $iso === '') {
            return '–';
        }

        $sekunden = max(0, now()->getTimestamp() - Carbon::parse($iso)->getTimestamp());
        if ($sekunden < 60) {
            return 'vor '.$sekunden.' s';
        }

        $minuten = intdiv($sekunden, 60);
        if ($minuten < 60) {
            return 'vor '.$minuten.' min';
        }

        return 'vor '.intdiv($minuten, 60).' h';
    }

    /**
     * @param  array<string, mixed>  $job
     */
    public static function jobTitel(array $job): string
    {
        if (! empty($job['test_label'])) {
            return (string) $job['test_label'];
        }

        if (! empty($job['bewerbung_id'])) {
            $stelle = $job['stelle_id'] ?? '–';

            return 'Bewerbung '.$job['bewerbung_id'].', Stelle '.$stelle;
        }

        if (! empty($job['test_name'])) {
            return 'Test „'.$job['test_name'].'“';
        }

        return (string) ($job['job'] ?? 'Job');
    }

    /**
     * @param  array<string, mixed>  $lauf
     */
    /**
     * @param  array<string, mixed>  $parse
     */
    public static function parseZeile(array $parse): string
    {
        $engine = DocumentParseEngine::tryFrom((string) ($parse['engine'] ?? ''));
        if (! $engine instanceof DocumentParseEngine && trim((string) ($parse['engine'] ?? '')) === '') {
            return '';
        }

        $teile = [$engine?->label() ?? (string) $parse['engine']];
        if ($engine === DocumentParseEngine::LlamaParse && is_string($parse['tier'] ?? null) && $parse['tier'] !== '') {
            $teile[] = self::tierLabel($parse['tier']);
        }
        if (is_string($parse['provider'] ?? null) && $parse['provider'] !== '') {
            $teile[] = $parse['provider'];
        }
        if (is_string($parse['modell'] ?? null) && $parse['modell'] !== '') {
            $teile[] = $parse['modell'];
        }

        return implode(' · ', $teile);
    }

    /**
     * @param  array<string, mixed>  $llm
     */
    public static function textZeile(array $llm): string
    {
        $provider = trim((string) ($llm['provider'] ?? ''));
        $label = AiProvider::tryFrom($provider)?->label() ?? $provider;
        $modell = trim((string) ($llm['modell'] ?? ''));
        if ($label === '' && $modell === '') {
            return '';
        }

        return $modell !== '' && $label !== '' ? $label.' · '.$modell : ($label !== '' ? $label : $modell);
    }

    public static function tierLabel(string $tier): string
    {
        return match ($tier) {
            'fast' => 'Fast',
            'cost_effective' => 'Cost Effective',
            'agentic' => 'Agentic',
            'agentic_plus' => 'Agentic Plus',
            default => $tier,
        };
    }

    /**
     * @param  array<string, mixed>  $lauf
     */
    public static function laufDauerMs(array $lauf): ?int
    {
        if (is_numeric($lauf['duration_ms'] ?? null) && ($lauf['status'] ?? '') !== 'running') {
            return (int) $lauf['duration_ms'];
        }

        $started = $lauf['started_at'] ?? null;
        if (! is_string($started) || $started === '') {
            return null;
        }

        $zeit = strtotime($started);
        if ($zeit === false) {
            return null;
        }

        return max(0, (int) round((microtime(true) - $zeit) * 1000));
    }
}
