@props([
    'lauf' => [],
])

@php
    use Hwkdo\IntranetAppBewerbungen\Support\PipelineFormat;

    $dokumente = is_array($lauf['dokumente'] ?? null) ? $lauf['dokumente'] : [];
    $verwendet = count(array_filter($dokumente, fn (mixed $dokument): bool => is_array($dokument) && ($dokument['verwendet'] ?? false)));
    $cache = count(array_filter($dokumente, fn (mixed $dokument): bool => is_array($dokument) && ($dokument['aus_cache'] ?? false)));
    $prompt = is_array($lauf['prompt'] ?? null) ? $lauf['prompt'] : [];
    $llm = is_array($lauf['llm'] ?? null) ? $lauf['llm'] : [];
    $parse = is_array($lauf['parse'] ?? null) ? $lauf['parse'] : [];
    $tokens = $llm['tokens_pro_sekunde'] ?? null;
    if (! is_numeric($tokens) && is_numeric($llm['output_tokens'] ?? null) && (int) ($lauf['llm_ms'] ?? 0) > 0) {
        $tokens = round(((int) $llm['output_tokens']) / ((int) $lauf['llm_ms'] / 1000), 1);
    }
    $parseZeile = PipelineFormat::parseZeile($parse);
    $textZeile = PipelineFormat::textZeile($llm);
@endphp

<div class="flex flex-wrap gap-2">
    @if ($parseZeile !== '')
        <flux:badge size="sm" color="purple">Parsing · {{ $parseZeile }}</flux:badge>
    @endif
    @if ($textZeile !== '')
        <flux:badge size="sm" color="sky">Text · {{ $textZeile }}</flux:badge>
    @endif
    <flux:badge size="sm" color="zinc">{{ PipelineFormat::dauer(PipelineFormat::laufDauerMs($lauf)) }}</flux:badge>
    @if ($dokumente !== [])
        <flux:badge size="sm" color="zinc">{{ $verwendet }}/{{ count($dokumente) }} Dokumente</flux:badge>
    @endif
    @if ($cache > 0)
        <flux:badge size="sm" color="purple">{{ $cache }} aus Zwischenspeicher</flux:badge>
    @endif
    @if (isset($prompt['zeichen']))
        <flux:badge size="sm" :color="($prompt['gekuerzt'] ?? false) ? 'amber' : 'zinc'">
            {{ number_format((int) $prompt['zeichen'], 0, ',', '.') }} Zeichen{{ ($prompt['gekuerzt'] ?? false) ? ', gekürzt' : '' }}
        </flux:badge>
    @endif
    @if (is_numeric($llm['input_tokens'] ?? null) || is_numeric($llm['output_tokens'] ?? null))
        <flux:badge size="sm" color="zinc">
            {{ $llm['input_tokens'] ?? '–' }} → {{ $llm['output_tokens'] ?? '–' }} Tokens
        </flux:badge>
    @endif
    @if (is_numeric($tokens))
        <flux:badge size="sm" color="emerald">{{ PipelineFormat::zahl((float) $tokens, 'Tokens/s') }}</flux:badge>
    @endif
</div>
