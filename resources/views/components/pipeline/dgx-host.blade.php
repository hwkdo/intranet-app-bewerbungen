@props([
    'dgx' => [],
    'kompakt' => false,
])

@php
    use Hwkdo\IntranetAppBewerbungen\Support\PipelineFormat;
@endphp

<flux:card>
    <flux:heading size="lg">DGX-Host</flux:heading>
    @if ($dgx['verfuegbar'] ?? false)
        @if (! empty($dgx['lage']))
            <flux:text class="mt-2">{{ $dgx['lage'] }}</flux:text>
        @endif
        <div class="mt-4 space-y-3">
            <x-intranet-app-bewerbungen::pipeline.metrik-balken
                name="GPU"
                :breite="PipelineFormat::balken(isset($dgx['gpu_auslastung_prozent']) ? (float) $dgx['gpu_auslastung_prozent'] : null)"
                :text="PipelineFormat::zahl(isset($dgx['gpu_auslastung_prozent']) ? (float) $dgx['gpu_auslastung_prozent'] : null, '%').', '.PipelineFormat::zahl(isset($dgx['leistung_watt']) ? (float) $dgx['leistung_watt'] : null, 'W').' von '.PipelineFormat::zahl(isset($dgx['leistung_budget_watt']) ? (float) $dgx['leistung_budget_watt'] : null, 'W')"
            />
            <x-intranet-app-bewerbungen::pipeline.metrik-balken
                name="CPU"
                :breite="PipelineFormat::cpuBalken($dgx)"
                :text="PipelineFormat::cpuText($dgx)"
            />
            <x-intranet-app-bewerbungen::pipeline.metrik-balken
                name="RAM"
                :breite="PipelineFormat::balken(isset($dgx['ram_belegt_bytes']) ? (float) $dgx['ram_belegt_bytes'] : null, isset($dgx['ram_gesamt_bytes']) ? (float) $dgx['ram_gesamt_bytes'] : null)"
                :text="PipelineFormat::gib(isset($dgx['ram_belegt_bytes']) ? (float) $dgx['ram_belegt_bytes'] : null).' von '.PipelineFormat::gib(isset($dgx['ram_gesamt_bytes']) ? (float) $dgx['ram_gesamt_bytes'] : null)"
            />
            @if (($dgx['dram_bandbreite_gb_s'] ?? null) !== null)
                <x-intranet-app-bewerbungen::pipeline.metrik-balken
                    name="LPDDR"
                    farbe="bg-emerald-500"
                    :breite="PipelineFormat::balken((float) $dgx['dram_aktiv_prozent'])"
                    :text="PipelineFormat::zahl((float) $dgx['dram_bandbreite_gb_s'], 'GB/s')"
                />
            @endif
        </div>
        @unless ($kompakt)
            <flux:text class="mt-3">
                Der GB10 hat gemeinsamen Speicher, deshalb gibt es hier keinen GPU-Framebuffer. Die Copy-Engine steht auf {{ PipelineFormat::zahl(isset($dgx['speicher_controller_prozent']) ? (float) $dgx['speicher_controller_prozent'] : null, '%') }} und misst nicht, wie schnell die Gewichte bei jedem Token durch den Speicher laufen. Diesen Wert liefert DCGM auf dem Spark nicht.
            </flux:text>
            @if (! empty($dgx['hinweis']))
                <flux:text class="mt-2">{{ $dgx['hinweis'] }}</flux:text>
            @endif
        @endunless
    @else
        <flux:text class="mt-3">{{ $dgx['hinweis'] ?? '' }}</flux:text>
        @if (! empty($dgx['fehlende_quellen']))
            <ul class="mt-3 list-disc space-y-1 pl-5 text-sm">
                @foreach ($dgx['fehlende_quellen'] as $quelle)
                    <li>{{ $quelle }}</li>
                @endforeach
            </ul>
        @endif
    @endif
</flux:card>
