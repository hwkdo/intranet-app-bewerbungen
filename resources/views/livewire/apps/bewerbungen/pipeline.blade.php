<?php

use Hwkdo\IntranetAppBewerbungen\Services\KiPipelineMonitor;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Stellenanalyse-Pipeline')] class extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user()?->can('manage-app-bewerbungen'), 403);
    }

    /**
     * @return array<string, mixed>
     */
    public function daten(): array
    {
        return app(KiPipelineMonitor::class)->snapshot();
    }

    public function dauer(?int $ms): string
    {
        if ($ms === null) {
            return '–';
        }

        if ($ms < 1000) {
            return $ms.' ms';
        }

        return number_format($ms / 1000, 1, ',', '.').' s';
    }

    public function zahl(?float $wert, string $einheit): string
    {
        if ($wert === null) {
            return '–';
        }

        return number_format($wert, 1, ',', '.').' '.$einheit;
    }

    public function gib(?float $bytes): string
    {
        if ($bytes === null) {
            return '–';
        }

        return number_format($bytes / 1024 / 1024 / 1024, 1, ',', '.').' GiB';
    }

    public function balken(?float $wert, ?float $ganz = null): float
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
    public function cpuBalken(array $dgx): float
    {
        if (is_numeric($dgx['cpu_prozent'] ?? null)) {
            return $this->balken((float) $dgx['cpu_prozent']);
        }

        $kerne = $dgx['cpu_kerne'] ?? null;
        $last = $dgx['cpu_last_1m'] ?? null;
        if (is_numeric($kerne) && (float) $kerne > 0 && is_numeric($last)) {
            return $this->balken(((float) $last / (float) $kerne) * 100);
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $dgx
     */
    public function cpuText(array $dgx): string
    {
        $kerne = is_numeric($dgx['cpu_kerne'] ?? null) ? ' auf '.$dgx['cpu_kerne'].' Kernen' : '';
        if (is_numeric($dgx['cpu_prozent'] ?? null)) {
            return $this->zahl((float) $dgx['cpu_prozent'], '%').$kerne;
        }

        $last = is_numeric($dgx['cpu_last_1m'] ?? null) ? (float) $dgx['cpu_last_1m'] : null;

        return 'Last '.$this->zahl($last, '').$kerne;
    }

    public function phaseLabel(string $phase): string
    {
        return match ($phase) {
            'queue' => 'Queue',
            'graph' => 'Graph-Download',
            'extraktion' => 'Text-Extraktion',
            'llm' => 'LLM',
            default => $phase,
        };
    }

    public function uhr(?string $iso): string
    {
        if ($iso === null || $iso === '') {
            return '–';
        }

        return Carbon::parse($iso)->timezone(config('app.timezone'))->format('H:i:s');
    }

    /**
     * @param  array<string, mixed>  $job
     */
    public function jobTitel(array $job): string
    {
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
     * @param  array<string, mixed>  $daten
     */
    public function stufeAktiv(string $key, array $daten): bool
    {
        $jobKarte = null;
        foreach ($daten['laufende'] ?? [] as $lauf) {
            $karte = match ($lauf['phase'] ?? null) {
                'queue' => 'laufend',
                'graph' => 'graph',
                'extraktion' => 'extraktion',
                'llm' => 'llm',
                default => null,
            };
            if ($karte !== null) {
                $jobKarte = $karte;
            }
        }

        return ($daten['engpass'] === 'queue' && $key === 'wartend')
            || $daten['engpass'] === $key
            || $jobKarte === $key;
    }
};
?>

<div wire:poll.2s>
    @php($daten = $this->daten())
    <x-intranet-app-bewerbungen::bewerbungen-layout heading="Stellenanalyse" subheading="Pipeline">
        <div class="space-y-6">
            <flux:card>
                <flux:heading size="lg">Wo die Kette gerade steht</flux:heading>
                <flux:text class="mt-2">
                    {{ match ($daten['engpass']) {
                        'llm' => 'Ausgelastet ist der LLM-Server: alle Slots sind belegt.',
                        'graph' => 'Ausgelastet ist der Graph-Download des laufenden Jobs.',
                        'extraktion' => 'Ausgelastet ist die Text-Extraktion des laufenden Jobs.',
                        'queue' => 'Ausgelastet ist die Queue: es warten Jobs, und der Worker ist belegt.',
                        default => 'Gerade kein Stau. Queue und LLM-Slots haben Luft.',
                    } }}
                </flux:text>
                <flux:text class="mt-1">
                    Worker für {{ $daten['worker']['queue'] }}: {{ $daten['worker']['max_prozesse'] }} {{ $daten['worker']['max_prozesse'] === 1 ? 'Prozess' : 'Prozesse' }}, Job-Timeout {{ $daten['worker']['timeout'] }} s.
                    @if (count($daten['laufende']) === 1)
                        Dieser Job ist in der Stufe {{ $this->phaseLabel((string) $daten['laufende'][0]['phase']) }}.
                    @elseif (count($daten['laufende']) > 1)
                        {{ count($daten['laufende']) }} Jobs laufen gleichzeitig.
                    @endif
                </flux:text>
            </flux:card>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7">
                @foreach ([
                    'wartend' => 'Wartend',
                    'laufend' => 'Läuft',
                    'graph' => 'Graph',
                    'extraktion' => 'Extraktion',
                    'llm' => 'LLM',
                    'fertig' => 'Fertig',
                    'fehlgeschlagen' => 'Fehler',
                ] as $key => $label)
                    <div @class([
                        'rounded-lg border p-3',
                        'border-blue-500 ring-2 ring-blue-500' => $this->stufeAktiv($key, $daten),
                        'border-zinc-200 dark:border-zinc-700' => ! $this->stufeAktiv($key, $daten),
                    ])>
                        <div class="text-sm text-zinc-500">{{ $label }}</div>
                        <div class="mt-1 text-2xl font-semibold">{{ $daten['stufen'][$key] }}</div>
                    </div>
                @endforeach
            </div>
            <flux:text class="text-sm">
                Fertig und Fehler zählen die letzten gespeicherten Läufe, nicht die Live-Queue.
            </flux:text>

            <div class="grid gap-4 lg:grid-cols-2">
                <flux:card>
                    <flux:heading size="lg">Laufende Jobs</flux:heading>
                    @forelse ($daten['laufende'] as $lauf)
                        <div class="mt-4 space-y-1 border-t border-zinc-200 pt-3 dark:border-zinc-700" wire:key="lauf-{{ $lauf['request_id'] ?? $loop->index }}">
                            <div>{{ $this->jobTitel($lauf) }}</div>
                            <div>Stufe: {{ $this->phaseLabel((string) $lauf['phase']) }}</div>
                            <div>Gerade: {{ $lauf['schritt'] }}</div>
                            <div>Gestartet: {{ $this->uhr($lauf['started_at']) }}, Queue-Wartezeit {{ $this->dauer($lauf['queue_wait_ms']) }}</div>
                            <div class="max-h-40 space-y-1 overflow-y-auto font-mono text-sm">
                                @forelse (array_slice($lauf['schritte'] ?? [], -8) as $schritt)
                                    <div wire:key="schritt-{{ $lauf['request_id'] ?? $loop->parent->index }}-{{ $loop->index }}">
                                        {{ $this->uhr($schritt['at'] ?? null) }}
                                        {{ $this->phaseLabel((string) ($schritt['phase'] ?? '')) }}
                                        — {{ $schritt['text'] ?? '' }}
                                    </div>
                                @empty
                                    <flux:text>Noch kein Fortschrittsschritt.</flux:text>
                                @endforelse
                            </div>
                        </div>
                    @empty
                        <flux:text class="mt-3">Kein reservierter Job auf {{ $daten['worker']['queue'] }}.</flux:text>
                    @endforelse
                </flux:card>

                <flux:card>
                    <flux:heading size="lg">Wartende Jobs</flux:heading>
                    @if (! $daten['queue']['verfuegbar'])
                        <flux:text class="mt-3">{{ $daten['queue']['hinweis'] }}</flux:text>
                    @else
                        <flux:text class="mt-2">
                            {{ $daten['queue']['pending'] }} wartend, {{ $daten['queue']['delayed'] }} verzögert, {{ $daten['queue']['reserved'] }} reserviert.
                        </flux:text>
                        <flux:table class="mt-3">
                            <flux:table.columns>
                                <flux:table.column>Auftrag</flux:table.column>
                                <flux:table.column>Wartet</flux:table.column>
                            </flux:table.columns>
                            <flux:table.rows>
                                @forelse ($daten['queue']['pending_jobs'] as $job)
                                    <flux:table.row wire:key="pending-{{ $job['uuid'] ?? $loop->index }}">
                                        <flux:table.cell>{{ $this->jobTitel($job) }}</flux:table.cell>
                                        <flux:table.cell>{{ $this->dauer($job['warte_ms']) }}</flux:table.cell>
                                    </flux:table.row>
                                @empty
                                    <flux:table.row>
                                        <flux:table.cell colspan="2">Keine wartenden Jobs.</flux:table.cell>
                                    </flux:table.row>
                                @endforelse
                            </flux:table.rows>
                        </flux:table>
                    @endif
                </flux:card>
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <flux:card>
                    <flux:heading size="lg">LLM / llama.cpp</flux:heading>
                    @if ($daten['llama']['erreichbar'])
                        <div class="mt-3 space-y-1">
                            <div>Host {{ $daten['llama']['host'] }}, Modell {{ $daten['llama']['modell'] ?? '–' }}</div>
                            <div>Slots {{ $daten['llama']['slots_belegt'] }}/{{ $daten['llama']['slots_gesamt'] }} belegt</div>
                            <div>Generierung jetzt {{ $this->zahl($daten['llama']['tokens_pro_sekunde'], 'Tokens/s') }}</div>
                            <div>Prompt jetzt {{ $this->zahl($daten['llama']['prompt_tokens_pro_sekunde'], 'Tokens/s') }}</div>
                            @if ($daten['llama']['metrics_aktiv'])
                                <div>Anfragen in Arbeit {{ $daten['llama']['anfragen_verarbeitung'] ?? '–' }}, am LLM wartend {{ $daten['llama']['anfragen_zurueckgestellt'] ?? '–' }}</div>
                                <div>Zuletzt gemeldet: Generierung {{ $this->zahl($daten['llama']['generierung_durchschnitt'], 'Tokens/s') }}, Prompt {{ $this->zahl($daten['llama']['prompt_durchschnitt'], 'Tokens/s') }}</div>
                                @if ($daten['llama']['spec_akzeptanz_prozent'] !== null)
                                    <div>Speculative Decoding angenommen {{ $this->zahl($daten['llama']['spec_akzeptanz_prozent'], '%') }}</div>
                                @endif
                                <flux:text class="mt-2">Die Tokens/s oben sind die Live-Differenz der Slot-Zähler. llama.cpp setzt die Prometheus-Gauges während eines laufenden Requests oft auf 0, deshalb bleibt hier der letzte Wert über 0 stehen.</flux:text>
                            @else
                                <flux:text class="mt-2">{{ $daten['llama']['metrics_hinweis'] }} Tokens/s hier sind die Differenz der Slot-Zähler zwischen zwei Abfragen.</flux:text>
                            @endif
                        </div>
                        <flux:table class="mt-3">
                            <flux:table.columns>
                                <flux:table.column>Slot</flux:table.column>
                                <flux:table.column>Kontext</flux:table.column>
                                <flux:table.column>Prompt</flux:table.column>
                                <flux:table.column>Dekodiert</flux:table.column>
                                <flux:table.column>Tokens/s</flux:table.column>
                            </flux:table.columns>
                            <flux:table.rows>
                                @foreach ($daten['llama']['slots'] as $slot)
                                    <flux:table.row wire:key="slot-{{ $slot['id'] }}">
                                        <flux:table.cell>
                                            {{ $slot['id'] }}
                                            @if ($slot['is_processing'])
                                                <flux:badge size="sm" color="amber">belegt</flux:badge>
                                            @else
                                                <flux:badge size="sm" color="zinc">frei</flux:badge>
                                            @endif
                                        </flux:table.cell>
                                        <flux:table.cell>{{ $slot['n_ctx'] ?? '–' }}</flux:table.cell>
                                        <flux:table.cell>{{ $slot['n_prompt_tokens_processed'] }}/{{ $slot['n_prompt_tokens'] }}</flux:table.cell>
                                        <flux:table.cell>{{ $slot['n_decoded'] }}</flux:table.cell>
                                        <flux:table.cell>{{ $this->zahl($slot['tokens_pro_sekunde'], '') }}</flux:table.cell>
                                    </flux:table.row>
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    @else
                        <flux:text class="mt-3">{{ $daten['llama']['hinweis'] }}</flux:text>
                    @endif
                </flux:card>

                <flux:card>
                    <flux:heading size="lg">DGX-Host</flux:heading>
                    @if ($daten['dgx']['verfuegbar'])
                        <flux:text class="mt-2">{{ $daten['dgx']['lage'] }}</flux:text>
                        <div class="mt-4 space-y-3">
                            @foreach ([
                                ['GPU', $this->balken($daten['dgx']['gpu_auslastung_prozent']), $this->zahl($daten['dgx']['gpu_auslastung_prozent'], '%').', '.$this->zahl($daten['dgx']['leistung_watt'], 'W').' von '.$this->zahl($daten['dgx']['leistung_budget_watt'], 'W')],
                                ['CPU', $this->cpuBalken($daten['dgx']), $this->cpuText($daten['dgx'])],
                                ['RAM', $this->balken($daten['dgx']['ram_belegt_bytes'], $daten['dgx']['ram_gesamt_bytes']), $this->gib($daten['dgx']['ram_belegt_bytes']).' von '.$this->gib($daten['dgx']['ram_gesamt_bytes'])],
                            ] as [$name, $breite, $text])
                                <div wire:key="dgx-{{ $name }}">
                                    <div class="flex justify-between text-sm">
                                        <span>{{ $name }}</span>
                                        <span>{{ $text }}</span>
                                    </div>
                                    <div class="mt-1 h-2 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                                        <div class="h-full bg-sky-500" style="width: {{ $breite }}%"></div>
                                    </div>
                                </div>
                            @endforeach
                            @if ($daten['dgx']['dram_bandbreite_gb_s'] !== null)
                                <div>
                                    <div class="flex justify-between text-sm">
                                        <span>LPDDR</span>
                                        <span>{{ $this->zahl($daten['dgx']['dram_bandbreite_gb_s'], 'GB/s') }}</span>
                                    </div>
                                    <div class="mt-1 h-2 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                                        <div class="h-full bg-emerald-500" style="width: {{ $this->balken($daten['dgx']['dram_aktiv_prozent']) }}%"></div>
                                    </div>
                                </div>
                            @endif
                        </div>
                        <flux:text class="mt-3">
                            Der GB10 hat gemeinsamen Speicher, deshalb gibt es hier keinen GPU-Framebuffer. Die Copy-Engine steht auf {{ $this->zahl($daten['dgx']['speicher_controller_prozent'], '%') }} und misst nicht, wie schnell die Gewichte bei jedem Token durch den Speicher laufen. Diesen Wert liefert DCGM auf dem Spark nicht.
                        </flux:text>
                        @if ($daten['dgx']['hinweis'])
                            <flux:text class="mt-2">{{ $daten['dgx']['hinweis'] }}</flux:text>
                        @endif
                    @else
                        <flux:text class="mt-3">{{ $daten['dgx']['hinweis'] }}</flux:text>
                        <ul class="mt-3 list-disc space-y-1 pl-5 text-sm">
                            @foreach ($daten['dgx']['fehlende_quellen'] as $quelle)
                                <li>{{ $quelle }}</li>
                            @endforeach
                        </ul>
                    @endif
                </flux:card>
            </div>

            <flux:card>
                <flux:heading size="lg">Zeitanteil der letzten Läufe</flux:heading>
                <flux:text class="mt-2">{{ $daten['befund']['text'] }}</flux:text>
                @if (($daten['befund']['anzahl'] ?? 0) > 0)
                    <div class="mt-4 flex h-3 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                        <div class="bg-amber-500" style="width: {{ $daten['befund']['anteile']['queue'] }}%"></div>
                        <div class="bg-sky-500" style="width: {{ $daten['befund']['anteile']['graph'] }}%"></div>
                        <div class="bg-violet-500" style="width: {{ $daten['befund']['anteile']['extraktion'] }}%"></div>
                        <div class="bg-emerald-500" style="width: {{ $daten['befund']['anteile']['llm'] }}%"></div>
                    </div>
                    <div class="mt-3 grid gap-2 sm:grid-cols-4">
                        <div>Queue {{ $this->dauer($daten['befund']['mittel']['queue']) }}</div>
                        <div>Graph {{ $this->dauer($daten['befund']['mittel']['graph']) }}</div>
                        <div>Extraktion {{ $this->dauer($daten['befund']['mittel']['extraktion']) }}</div>
                        <div>LLM {{ $this->dauer($daten['befund']['mittel']['llm']) }}</div>
                    </div>
                @endif
            </flux:card>
        </div>
    </x-intranet-app-bewerbungen::bewerbungen-layout>
</div>
