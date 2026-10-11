@props([
    'phase' => 'queue',
    'status' => 'running',
])

@php
    use Hwkdo\IntranetAppBewerbungen\Support\PipelineFormat;

    $reihenfolge = array_keys(PipelineFormat::PHASEN);
    $aktiv = array_search($phase, $reihenfolge, true);
    $aktiv = $aktiv === false ? 0 : $aktiv;
@endphp

<ol class="flex flex-wrap gap-2">
    @foreach (PipelineFormat::PHASEN as $key => $label)
        @php
            $index = array_search($key, $reihenfolge, true);
            $fertig = $status !== 'running' || $index < $aktiv;
            $jetzt = $status === 'running' && $key === $phase;
        @endphp
        <li @class([
            'rounded-full px-2.5 py-1 text-xs font-medium',
            'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300' => $fertig && ! $jetzt,
            'animate-pulse bg-blue-500/15 text-blue-700 ring-1 ring-blue-500 dark:text-blue-300' => $jetzt,
            'bg-zinc-100 text-zinc-500 dark:bg-zinc-800' => ! $fertig && ! $jetzt,
        ])>
            {{ $fertig && ! $jetzt ? '✓ ' : '' }}{{ $label }}
        </li>
    @endforeach
</ol>
