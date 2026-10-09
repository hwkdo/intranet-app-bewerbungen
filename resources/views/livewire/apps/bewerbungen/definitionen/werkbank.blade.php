<?php

use Hwkdo\IntranetAppBewerbungen\Data\KiFeld;
use Hwkdo\IntranetAppBewerbungen\Jobs\TestDefinitionJob;
use Hwkdo\IntranetAppBewerbungen\Services\LegacyBewerbungLinksClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Reactive;
use Livewire\Component;

new class extends Component
{
    #[Reactive]
    public string $name = '';

    #[Reactive]
    public string $instruktionen = '';

    /**
     * @var list<array<string, mixed>>
     */
    #[Reactive]
    public array $felder = [];

    public string $stelleId = '';

    public string $bewerbungId = '';

    /**
     * @var list<array{id: int, bezeichnung: string}>
     */
    public array $stellen = [];

    /**
     * @var list<array{id: int, label: string, hat_link: bool}>
     */
    public array $bewerbungen = [];

    public string $status = 'idle';

    public ?string $token = null;

    public ?string $fehler = null;

    public ?string $hinweis = null;

    /** @var array<string, mixed>|null */
    public ?array $ergebnis = null;

    public function stellenLaden(): void
    {
        $this->hinweis = null;

        try {
            $this->stellen = app(LegacyBewerbungLinksClient::class)->stellen();
            if ($this->stellen === []) {
                $this->hinweis = 'Es gibt keine aktive, nicht abgeschlossene Stelle.';
            }
        } catch (\Throwable $exception) {
            $this->stellen = [];
            $this->hinweis = $exception->getMessage();
        }
    }

    public function updatedStelleId(mixed $value): void
    {
        $this->bewerbungId = '';
        $this->bewerbungen = [];
        $this->ergebnis = null;
        $this->fehler = null;
        $this->hinweis = null;

        if (! is_numeric($value) || (int) $value <= 0) {
            return;
        }

        try {
            $this->bewerbungen = app(LegacyBewerbungLinksClient::class)->bewerbungen((int) $value);
            if ($this->bewerbungen === []) {
                $this->hinweis = 'Diese Stelle hat keine Bewerbungen.';
            }
        } catch (\Throwable $exception) {
            $this->hinweis = $exception->getMessage();
        }
    }

    public function testen(): void
    {
        $this->fehler = null;
        $this->ergebnis = null;

        if (trim($this->instruktionen) === '' || $this->felder === []) {
            $this->fehler = 'Instruktionen und mindestens ein Feld werden zum Testen gebraucht.';
            $this->status = 'failed';

            return;
        }

        $bewerbung = collect($this->bewerbungen)->first(
            fn (array $eintrag): bool => (string) $eintrag['id'] === $this->bewerbungId,
        );

        if (! is_array($bewerbung)) {
            $this->fehler = 'Bitte zuerst eine Stelle und eine Bewerbung wählen.';
            $this->status = 'failed';

            return;
        }

        if (! $bewerbung['hat_link']) {
            $this->fehler = 'Diese Bewerbung hat keinen OneDrive-Link. Die Dokumente können nicht geladen werden.';
            $this->status = 'failed';

            return;
        }

        try {
            $links = app(LegacyBewerbungLinksClient::class)->fetch((int) $this->bewerbungId);
        } catch (\Throwable $exception) {
            $this->fehler = $exception->getMessage();
            $this->status = 'failed';

            return;
        }

        $this->token = (string) Str::uuid();
        Cache::put(TestDefinitionJob::cacheKey($this->token), ['status' => 'running'], now()->addHour());
        TestDefinitionJob::dispatch(
            $this->token,
            $links['bewerbung_ro'],
            $links['anhang_ro'],
            $this->name,
            $this->instruktionen,
            $this->bereinigteFelder(),
        );
        $this->status = 'running';
    }

    public function poll(): void
    {
        if ($this->status !== 'running' || $this->token === null) {
            return;
        }

        $cached = Cache::get(TestDefinitionJob::cacheKey($this->token));
        if (! is_array($cached) || ($cached['status'] ?? '') === 'running') {
            return;
        }

        if (($cached['status'] ?? '') === 'done') {
            $this->ergebnis = is_array($cached['result'] ?? null) ? $cached['result'] : null;
            $this->status = 'done';

            return;
        }

        $this->fehler = (string) ($cached['error'] ?? 'Test fehlgeschlagen.');
        $this->status = 'failed';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function bereinigteFelder(): array
    {
        return array_values(array_map(
            fn (mixed $feld): array => KiFeld::fromArray(is_array($feld) ? $feld : [])->toArray(),
            $this->felder,
        ));
    }
};
?>

<div class="space-y-4" wire:init="stellenLaden" @if ($status === 'running') wire:poll.2s="poll" @endif>
    <flux:heading size="lg">Testen</flux:heading>
    <flux:text>Wählt eine aktive Stelle und eine Bewerbung. Der Test lädt die Dokumente aus OneDrive und wertet sie mit der ungespeicherten Definition aus.</flux:text>

    <div wire:loading wire:target="stellenLaden" class="text-sm text-zinc-500">Stellen werden geladen…</div>

    @if ($hinweis)
        <flux:callout>{{ $hinweis }}</flux:callout>
    @endif

    <flux:select wire:model.live="stelleId" label="Stelle" placeholder="Stelle wählen">
        @foreach ($stellen as $stelle)
            <flux:select.option value="{{ $stelle['id'] }}">{{ $stelle['bezeichnung'] }}</flux:select.option>
        @endforeach
    </flux:select>

    @if ($stelleId !== '')
        <flux:select wire:model="bewerbungId" label="Bewerbung" placeholder="Bewerbung wählen">
            @foreach ($bewerbungen as $bewerbung)
                <flux:select.option value="{{ $bewerbung['id'] }}">{{ $bewerbung['label'] }}</flux:select.option>
            @endforeach
        </flux:select>
    @endif

    <flux:button type="button" variant="primary" wire:click="testen" wire:loading.attr="disabled" :disabled="$bewerbungId === ''">
        <span wire:loading.remove wire:target="testen">Testen</span>
        <span wire:loading wire:target="testen">Test wird gestartet…</span>
    </flux:button>

    @if ($fehler)
        <flux:callout variant="danger">{{ $fehler }}</flux:callout>
    @endif

    @if ($status === 'running')
        <flux:callout>Analyse läuft. Dokumente werden geladen und ausgewertet. Die Seite aktualisiert sich von selbst.</flux:callout>
    @endif

    @if (is_array($ergebnis))
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Feld</flux:table.column>
                <flux:table.column>Wert</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach (array_merge($ergebnis['fields'] ?? [], $ergebnis['system'] ?? []) as $eintrag)
                    <flux:table.row wire:key="test-{{ $eintrag['key'] ?? $loop->index }}">
                        <flux:table.cell>{{ $eintrag['label'] ?? '' }}</flux:table.cell>
                        <flux:table.cell class="whitespace-pre-wrap">
                            @php
                                $wert = $eintrag['value'] ?? null;
                            @endphp
                            @if (is_array($wert))
                                {{ implode(', ', array_map(strval(...), $wert)) }}
                            @elseif ($wert === true)
                                Ja
                            @elseif ($wert === false)
                                Nein
                            @else
                                {{ $wert === null || $wert === '' ? '—' : $wert }}
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
        <pre class="max-h-80 overflow-auto rounded-lg border border-zinc-200 p-3 text-xs dark:border-zinc-700">{{ json_encode($ergebnis, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
    @endif
</div>
