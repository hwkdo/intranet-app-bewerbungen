<?php

use Hwkdo\IntranetAppBewerbungen\Services\KiPipelineMonitor;
use Hwkdo\IntranetAppBewerbungen\Support\PipelineZugang;
use Livewire\Component;

new class extends Component
{
    public function mount(): void
    {
        PipelineZugang::pruefen();
    }

    /**
     * @return array<string, mixed>
     */
    public function befund(): array
    {
        return app(KiPipelineMonitor::class)->befund();
    }
};
?>

@placeholder
    <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
        <flux:skeleton.group animate="shimmer">
            <flux:skeleton class="h-4 w-48" />
            <flux:skeleton class="mt-3 h-8 w-full" />
        </flux:skeleton.group>
    </div>
@endplaceholder

<div wire:poll.10s>
    @php($befund = $this->befund())
    <flux:card>
        <flux:heading size="lg">Zeitanteil der letzten Läufe</flux:heading>
        <flux:text class="mt-2">{{ $befund['text'] ?? '' }}</flux:text>
        @if (($befund['anzahl'] ?? 0) > 0)
            <div class="mt-4">
                <x-intranet-app-bewerbungen::pipeline.phasen-balken
                    :ms="$befund['mittel'] ?? []"
                    :anteile="$befund['anteile'] ?? []"
                />
            </div>
        @endif
    </flux:card>
</div>
