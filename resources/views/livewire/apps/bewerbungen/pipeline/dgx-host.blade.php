<?php

use Hwkdo\IntranetAppBewerbungen\Services\DgxHostMetricsClient;
use Hwkdo\IntranetAppBewerbungen\Support\PipelineZugang;
use Livewire\Component;

new class extends Component
{
    public bool $kompakt = false;

    public function mount(): void
    {
        PipelineZugang::pruefen();
    }

    /**
     * @return array<string, mixed>
     */
    public function dgx(): array
    {
        return app(DgxHostMetricsClient::class)->snapshot();
    }
};
?>

@placeholder
    <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
        <flux:skeleton.group animate="shimmer">
            <flux:skeleton class="h-4 w-28" />
            <flux:skeleton class="mt-3 h-20 w-full" />
        </flux:skeleton.group>
    </div>
@endplaceholder

<div wire:poll.5s>
    <x-intranet-app-bewerbungen::pipeline.dgx-host :dgx="$this->dgx()" :kompakt="$kompakt" />
</div>
