<?php

use Hwkdo\IntranetAppBewerbungen\Services\KiPipelineMonitor;
use Hwkdo\IntranetAppBewerbungen\Support\AnalyseLaufProtokoll;
use Hwkdo\IntranetAppBewerbungen\Support\PipelineZugang;
use Livewire\Attributes\Reactive;
use Livewire\Component;

new class extends Component
{
    public ?string $requestId = null;

    public ?string $testToken = null;

    public bool $kompakt = false;

    public ?string $parseHinweis = null;

    public ?string $textHinweis = null;

    /** @var array<string, int>|null */
    #[Reactive]
    public ?array $vergleich = null;

    public bool $gemeldet = false;

    public function mount(): void
    {
        PipelineZugang::pruefen();
    }

    public function tick(): void
    {
        if ($this->gemeldet || $this->testToken === null || $this->testToken === '') {
            return;
        }

        $lauf = AnalyseLaufProtokoll::zustand('test-'.$this->testToken);
        $status = is_array($lauf) ? (string) ($lauf['status'] ?? '') : '';
        if (! in_array($status, ['success', 'failed'], true)) {
            return;
        }

        $this->gemeldet = true;
        $this->dispatch('lauf-beendet', token: $this->testToken);
    }

    public function pollt(): bool
    {
        $lauf = $this->lauf();
        if ($lauf === null) {
            return true;
        }

        return ($lauf['status'] ?? '') === 'running';
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lauf(): ?array
    {
        $id = trim((string) $this->requestId);
        if ($id === '' && $this->testToken !== null && $this->testToken !== '') {
            $id = 'test-'.$this->testToken;
        }

        return $id === '' ? null : AnalyseLaufProtokoll::zustand($id);
    }

    public function position(): ?int
    {
        if ($this->lauf() !== null || $this->testToken === null || $this->testToken === '') {
            return null;
        }

        return app(KiPipelineMonitor::class)->queuePosition($this->testToken);
    }

    /**
     * @return array<string, int>
     */
    public function vergleichMs(): array
    {
        if (is_array($this->vergleich) && $this->vergleich !== []) {
            return $this->vergleich;
        }

        $mittel = app(KiPipelineMonitor::class)->befund()['mittel'] ?? [];

        return is_array($mittel) ? $mittel : [];
    }
};
?>

@placeholder
    <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
        <flux:skeleton.group animate="shimmer">
            <flux:skeleton class="h-4 w-40" />
            <flux:skeleton class="mt-3 h-24 w-full" />
        </flux:skeleton.group>
    </div>
@endplaceholder

<div @if ($this->pollt()) wire:poll.1500ms="tick" @endif>
    <x-intranet-app-bewerbungen::pipeline.lauf-karte
        :lauf="$this->lauf()"
        :vergleich="$this->vergleichMs()"
        :kompakt="$kompakt"
        :position="$this->position()"
        :parse-hinweis="$parseHinweis"
        :text-hinweis="$textHinweis"
    />
</div>
