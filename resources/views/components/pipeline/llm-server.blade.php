@props([
    'llama' => [],
    'kompakt' => false,
])

@php
    use Hwkdo\IntranetAppBewerbungen\Support\PipelineFormat;
@endphp

<flux:card>
    <flux:heading size="lg">LLM / llama.cpp</flux:heading>
    @if ($llama['erreichbar'] ?? false)
        <div class="mt-3 space-y-1">
            <div>Host {{ $llama['host'] ?? '–' }}, Modell {{ $llama['modell'] ?? '–' }}</div>
            <div>Slots {{ $llama['slots_belegt'] ?? 0 }}/{{ $llama['slots_gesamt'] ?? 0 }} belegt</div>
            <div>Generierung jetzt {{ PipelineFormat::zahl(isset($llama['tokens_pro_sekunde']) ? (float) $llama['tokens_pro_sekunde'] : null, 'Tokens/s') }}</div>
            <div>Prompt jetzt {{ PipelineFormat::zahl(isset($llama['prompt_tokens_pro_sekunde']) ? (float) $llama['prompt_tokens_pro_sekunde'] : null, 'Tokens/s') }}</div>
            @if ($llama['metrics_aktiv'] ?? false)
                <div>Anfragen in Arbeit {{ $llama['anfragen_verarbeitung'] ?? '–' }}, am LLM wartend {{ $llama['anfragen_zurueckgestellt'] ?? '–' }}</div>
                <div>
                    Zuletzt gemeldet {{ PipelineFormat::relativ($llama['generierung_gemeldet_at'] ?? null) }}:
                    Generierung {{ PipelineFormat::zahl(isset($llama['generierung_durchschnitt']) ? (float) $llama['generierung_durchschnitt'] : null, 'Tokens/s') }},
                    Prompt {{ PipelineFormat::zahl(isset($llama['prompt_durchschnitt']) ? (float) $llama['prompt_durchschnitt'] : null, 'Tokens/s') }}
                </div>
                @if (($llama['spec_akzeptanz_prozent'] ?? null) !== null)
                    <div>Speculative Decoding angenommen {{ PipelineFormat::zahl((float) $llama['spec_akzeptanz_prozent'], '%') }}</div>
                @endif
                @unless ($kompakt)
                    <flux:text class="mt-2">Die Tokens/s oben sind die Live-Differenz der Slot-Zähler. llama.cpp setzt die Prometheus-Gauges während eines laufenden Requests oft auf 0, deshalb bleibt hier der letzte Wert über 0 stehen, mit dem Zeitpunkt der Meldung.</flux:text>
                @endunless
            @else
                <flux:text class="mt-2">{{ $llama['metrics_hinweis'] ?? '' }} Tokens/s hier sind die Differenz der Slot-Zähler zwischen zwei Abfragen.</flux:text>
            @endif
        </div>
        @unless ($kompakt)
            <flux:table class="mt-3">
                <flux:table.columns>
                    <flux:table.column>Slot</flux:table.column>
                    <flux:table.column>Kontext</flux:table.column>
                    <flux:table.column>Prompt</flux:table.column>
                    <flux:table.column>Dekodiert</flux:table.column>
                    <flux:table.column>Tokens/s</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($llama['slots'] ?? [] as $slot)
                        <flux:table.row wire:key="slot-{{ $slot['id'] }}">
                            <flux:table.cell>
                                {{ $slot['id'] }}
                                @if ($slot['is_processing'] ?? false)
                                    <flux:badge size="sm" color="amber">belegt</flux:badge>
                                @else
                                    <flux:badge size="sm" color="zinc">frei</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>{{ $slot['n_ctx'] ?? '–' }}</flux:table.cell>
                            <flux:table.cell>{{ $slot['n_prompt_tokens_processed'] ?? 0 }}/{{ $slot['n_prompt_tokens'] ?? 0 }}</flux:table.cell>
                            <flux:table.cell>{{ $slot['n_decoded'] ?? 0 }}</flux:table.cell>
                            <flux:table.cell>{{ PipelineFormat::zahl(isset($slot['tokens_pro_sekunde']) ? (float) $slot['tokens_pro_sekunde'] : null, '') }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endunless
    @else
        <flux:text class="mt-3">{{ $llama['hinweis'] ?? 'llama.cpp ist nicht erreichbar.' }}</flux:text>
    @endif
</flux:card>
