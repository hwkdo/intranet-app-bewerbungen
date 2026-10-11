@props([
    'dokumente' => [],
])

@php
    use Hwkdo\IntranetAppBewerbungen\Support\PipelineFormat;
@endphp

@if ($dokumente === [])
    <flux:text>Noch keine Datei verarbeitet.</flux:text>
@else
    <flux:table>
        <flux:table.columns>
            <flux:table.column>Datei</flux:table.column>
            <flux:table.column>Quelle</flux:table.column>
            <flux:table.column>Zeichen</flux:table.column>
            <flux:table.column>Qualität</flux:table.column>
            <flux:table.column>Graph</flux:table.column>
            <flux:table.column>Extraktion</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach ($dokumente as $dokument)
                @if (is_array($dokument))
                    <flux:table.row wire:key="dok-{{ $loop->index }}-{{ $dokument['name'] ?? '' }}">
                        <flux:table.cell>
                            {{ $dokument['name'] ?? '' }}
                            @if ($dokument['aus_cache'] ?? false)
                                <flux:badge size="sm" color="purple">Cache</flux:badge>
                            @endif
                            @if (! ($dokument['verwendet'] ?? false))
                                <flux:badge size="sm" color="amber">nicht verwendet</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>{{ $dokument['quelle'] ?? '' }}</flux:table.cell>
                        <flux:table.cell>{{ number_format((int) ($dokument['zeichen'] ?? 0), 0, ',', '.') }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="($dokument['plausibel'] ?? false) ? 'green' : 'amber'">
                                {{ ($dokument['qualitaet'] ?? '') !== '' ? $dokument['qualitaet'] : '–' }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell>{{ PipelineFormat::dauer((int) ($dokument['graph_ms'] ?? 0)) }}</flux:table.cell>
                        <flux:table.cell>{{ PipelineFormat::dauer((int) ($dokument['extraktion_ms'] ?? 0)) }}</flux:table.cell>
                    </flux:table.row>
                @endif
            @endforeach
        </flux:table.rows>
    </flux:table>
@endif
