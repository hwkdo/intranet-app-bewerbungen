<?php

use Hwkdo\IntranetAppBewerbungen\Services\KiPipelineMonitor;
use Hwkdo\IntranetAppBewerbungen\Support\PipelineFormat;
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
    public function lage(): array
    {
        return app(KiPipelineMonitor::class)->lage();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function laufende(): array
    {
        return app(KiPipelineMonitor::class)->laufendeListe();
    }

    /**
     * @return array<string, mixed>
     */
    public function warteschlange(): array
    {
        return app(KiPipelineMonitor::class)->warteschlange();
    }

    /**
     * @param  array<string, mixed>  $daten
     */
    public function stufeAktiv(string $key, array $daten): bool
    {
        $phasen = is_array($daten['phasen'] ?? null) ? $daten['phasen'] : [];
        $jobKarten = array_map(
            fn (mixed $phase): ?string => match ($phase) {
                'queue' => 'laufend',
                'graph' => 'graph',
                'extraktion' => 'extraktion',
                'llm' => 'llm',
                default => null,
            },
            $phasen,
        );

        return ($daten['engpass'] === 'queue' && $key === 'wartend')
            || $daten['engpass'] === $key
            || in_array($key, $jobKarten, true);
    }
};
?>

<div>
    <x-intranet-app-bewerbungen::bewerbungen-layout heading="Stellenanalyse" subheading="Pipeline">
        <div class="space-y-6">
            @island(name: 'lage', lazy: true)
                <div wire:poll.2s class="space-y-6">
                    @php($daten = $this->lage())
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
                            @if (($daten['laufende_anzahl'] ?? 0) === 1)
                                Dieser Job ist in der Stufe {{ PipelineFormat::phaseLabel((string) $daten['erste_phase']) }}.
                            @elseif (($daten['laufende_anzahl'] ?? 0) > 1)
                                {{ $daten['laufende_anzahl'] }} Jobs laufen gleichzeitig.
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
                </div>
            @endisland

            <div class="grid gap-4 lg:grid-cols-2">
                @island(name: 'laufende', lazy: true)
                    <div wire:poll.2s>
                        <flux:card>
                            <flux:heading size="lg">Laufende Jobs</flux:heading>
                            <div class="mt-4 space-y-4">
                                @forelse ($this->laufende() as $lauf)
                                    @if (! empty($lauf['request_id']))
                                        <livewire:intranet-app-bewerbungen::apps.bewerbungen.pipeline.lauf-live
                                            lazy
                                            :request-id="$lauf['request_id']"
                                            :wire:key="'lauf-'.$lauf['request_id']"
                                        />
                                    @else
                                        <flux:text wire:key="lauf-ohne-id-{{ $loop->index }}">{{ PipelineFormat::jobTitel($lauf) }}</flux:text>
                                    @endif
                                @empty
                                    <flux:text>Kein reservierter Job auf bewerbungen-ki.</flux:text>
                                @endforelse
                            </div>
                        </flux:card>
                    </div>
                @endisland

                @island(name: 'warteschlange', lazy: true)
                    <div wire:poll.3s>
                        @php($queue = $this->warteschlange())
                        <flux:card>
                            <flux:heading size="lg">Wartende Jobs</flux:heading>
                            @if (! $queue['verfuegbar'])
                                <flux:text class="mt-3">{{ $queue['hinweis'] }}</flux:text>
                            @else
                                <flux:text class="mt-2">
                                    {{ $queue['pending'] }} wartend, {{ $queue['delayed'] }} verzögert, {{ $queue['reserved'] }} reserviert.
                                </flux:text>
                                <flux:table class="mt-3">
                                    <flux:table.columns>
                                        <flux:table.column>Auftrag</flux:table.column>
                                        <flux:table.column>Wartet</flux:table.column>
                                    </flux:table.columns>
                                    <flux:table.rows>
                                        @forelse ($queue['pending_jobs'] as $job)
                                            <flux:table.row wire:key="pending-{{ $job['uuid'] ?? $loop->index }}">
                                                <flux:table.cell>{{ PipelineFormat::jobTitel($job) }}</flux:table.cell>
                                                <flux:table.cell>{{ PipelineFormat::dauer($job['warte_ms'] ?? null) }}</flux:table.cell>
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
                @endisland
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <livewire:intranet-app-bewerbungen::apps.bewerbungen.pipeline.llm-server lazy />
                <livewire:intranet-app-bewerbungen::apps.bewerbungen.pipeline.dgx-host lazy />
            </div>

            <livewire:intranet-app-bewerbungen::apps.bewerbungen.pipeline.zeitanteile lazy />
        </div>
    </x-intranet-app-bewerbungen::bewerbungen-layout>
</div>
