@props([
    'lauf' => null,
    'vergleich' => [],
    'kompakt' => false,
    'position' => null,
    'parseHinweis' => null,
    'textHinweis' => null,
])

@php
    use Hwkdo\IntranetAppBewerbungen\Support\AnalyseLaufProtokoll;
    use Hwkdo\IntranetAppBewerbungen\Support\PipelineFormat;

    $lauf = is_array($lauf) ? $lauf : [];
    $anteile = $lauf === [] ? null : AnalyseLaufProtokoll::liveAnteile($lauf);
    $schritte = array_slice(is_array($lauf['schritte'] ?? null) ? $lauf['schritte'] : [], -8);
@endphp

<flux:card>
    @if ($lauf === [])
        <flux:heading size="lg">Wartet in der Queue</flux:heading>
        <flux:text class="mt-2">
            @if (is_numeric($position))
                Position {{ $position }}
            @else
                Der Job ist eingereiht. Der Fortschritt erscheint, sobald ein Worker ihn annimmt.
            @endif
        </flux:text>
        @if (filled($parseHinweis) || filled($textHinweis))
            <div class="mt-3 flex flex-wrap gap-2">
                @if (filled($parseHinweis))
                    <flux:badge size="sm" color="purple">Parsing · {{ $parseHinweis }}</flux:badge>
                @endif
                @if (filled($textHinweis))
                    <flux:badge size="sm" color="sky">Text · {{ $textHinweis }}</flux:badge>
                @endif
            </div>
        @endif
    @else
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <flux:heading size="lg">{{ PipelineFormat::jobTitel($lauf) }}</flux:heading>
                <flux:text class="mt-1">{{ $lauf['schritt'] ?? '…' }}</flux:text>
            </div>
            <flux:badge size="sm" :color="($lauf['status'] ?? '') === 'failed' ? 'red' : (($lauf['status'] ?? '') === 'success' ? 'green' : 'amber')">
                {{ match ($lauf['status'] ?? '') {
                    'success' => 'Fertig',
                    'failed' => 'Fehler',
                    default => PipelineFormat::phaseLabel((string) ($lauf['phase'] ?? '')),
                } }}
            </flux:badge>
        </div>

        <div class="mt-4">
            <x-intranet-app-bewerbungen::pipeline.phasen-stepper
                :phase="(string) ($lauf['phase'] ?? 'queue')"
                :status="(string) ($lauf['status'] ?? 'running')"
            />
        </div>

        <div class="mt-4">
            <x-intranet-app-bewerbungen::pipeline.lauf-kennzahlen :lauf="$lauf" />
        </div>

        @if (is_array($anteile))
            <div class="mt-4">
                <x-intranet-app-bewerbungen::pipeline.phasen-balken
                    :ms="$anteile['ms']"
                    :anteile="$anteile['anteile']"
                    :vergleich="$vergleich"
                />
            </div>
        @endif

        @if (! empty($lauf['fehler']))
            <flux:callout class="mt-4" variant="danger">{{ $lauf['fehler'] }}</flux:callout>
        @endif

        <flux:timeline class="mt-4" size="sm">
            @forelse ($schritte as $schritt)
                <flux:timeline.item wire:key="schritt-{{ $loop->index }}" :status="$loop->last && ($lauf['status'] ?? '') === 'running' ? 'current' : 'complete'">
                    <flux:timeline.indicator />
                    <flux:timeline.content>
                        <div class="font-mono text-sm">
                            {{ PipelineFormat::uhr($schritt['at'] ?? null) }}
                            {{ PipelineFormat::phaseLabel((string) ($schritt['phase'] ?? '')) }}
                            — {{ $schritt['text'] ?? '' }}
                        </div>
                    </flux:timeline.content>
                </flux:timeline.item>
            @empty
                <flux:timeline.item status="current">
                    <flux:timeline.indicator />
                    <flux:timeline.content>Noch kein Fortschrittsschritt.</flux:timeline.content>
                </flux:timeline.item>
            @endforelse
        </flux:timeline>

        @if (! $kompakt || is_array($lauf['dokumente'] ?? null))
            <flux:accordion class="mt-4" transition>
                <flux:accordion.item>
                    <flux:accordion.heading>Dokumente</flux:accordion.heading>
                    <flux:accordion.content>
                        <x-intranet-app-bewerbungen::pipeline.dokumente-liste :dokumente="$lauf['dokumente'] ?? []" />
                    </flux:accordion.content>
                </flux:accordion.item>
            </flux:accordion>
        @endif
    @endif
</flux:card>
