@props([
    'ms' => [],
    'anteile' => [],
    'vergleich' => [],
])

@php
    use Hwkdo\IntranetAppBewerbungen\Support\PipelineFormat;

    $phasen = PipelineFormat::PHASEN;
@endphp

<div>
    <div class="flex h-3 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
        @foreach ($phasen as $key => $label)
            <div class="{{ PipelineFormat::phaseFarbe($key) }}" style="width: {{ $anteile[$key] ?? 0 }}%"></div>
        @endforeach
    </div>
    <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($phasen as $key => $label)
            @php
                $wert = (int) ($ms[$key] ?? 0);
                $basis = is_array($vergleich) ? ($vergleich[$key] ?? null) : null;
                $delta = is_numeric($basis) && (int) $basis > 0
                    ? (int) round(($wert - (int) $basis) / (int) $basis * 100)
                    : null;
            @endphp
            <div wire:key="phase-{{ $key }}">
                <div class="text-sm text-zinc-500">{{ $label }}</div>
                <div>{{ PipelineFormat::dauer($wert) }}</div>
                @if ($delta !== null)
                    <div @class([
                        'text-xs',
                        'text-amber-600 dark:text-amber-400' => $delta > 0,
                        'text-emerald-600 dark:text-emerald-400' => $delta < 0,
                        'text-zinc-500' => $delta === 0,
                    ])>
                        {{ $delta > 0 ? '+' : '' }}{{ $delta }} % vs. Ø
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</div>
