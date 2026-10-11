<?php

use Hwkdo\IntranetAppBewerbungen\Services\LlamaCppSlotClient;
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
    public function llama(): array
    {
        return app(LlamaCppSlotClient::class)->snapshot();
    }
};
?>

@placeholder
    <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
        <flux:skeleton.group animate="shimmer">
            <flux:skeleton class="h-4 w-32" />
            <flux:skeleton class="mt-3 h-20 w-full" />
        </flux:skeleton.group>
    </div>
@endplaceholder

<div wire:poll.2s>
    <x-intranet-app-bewerbungen::pipeline.llm-server :llama="$this->llama()" :kompakt="$kompakt" />
</div>
