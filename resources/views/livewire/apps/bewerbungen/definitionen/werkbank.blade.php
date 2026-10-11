<?php

use Hwkdo\IntranetAppBase\Contracts\DocumentParseConfigResolverInterface;
use Hwkdo\IntranetAppBase\Enums\AiProvider;
use Hwkdo\IntranetAppBase\Enums\DocumentParseEngine;
use Hwkdo\IntranetAppBewerbungen\Data\AnalyseOverrides;
use Hwkdo\IntranetAppBewerbungen\Data\KiFeld;
use Hwkdo\IntranetAppBewerbungen\Jobs\TestDefinitionJob;
use Hwkdo\IntranetAppBewerbungen\Models\IntranetAppBewerbungenSettings;
use Hwkdo\IntranetAppBewerbungen\Services\LegacyBewerbungLinksClient;
use Hwkdo\IntranetAppBewerbungen\Support\AnalyseLaufProtokoll;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Attributes\Reactive;
use Livewire\Component;

new class extends Component
{
    /** @var array<string, string> */
    private const TIERS = [
        'fast' => 'Fast',
        'cost_effective' => 'Cost Effective',
        'agentic' => 'Agentic',
        'agentic_plus' => 'Agentic Plus',
    ];

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

    public bool $stellenGeladen = false;

    /**
     * @var list<array<string, mixed>>
     */
    public array $varianten = [];

    /**
     * @var array<string, array{token: string, status: string, ergebnis: array<string, mixed>|null, fehler: string|null}>
     */
    public array $laeufe = [];

    public string $status = 'idle';

    public ?string $fehler = null;

    public ?string $hinweis = null;

    public bool $serverSichtbar = false;

    public bool $nurUnterschiede = false;

    public function mount(): void
    {
        if ($this->varianten === []) {
            $this->varianten = [$this->standardVariante()];
        }
    }

    public function stellenLaden(): void
    {
        if ($this->stellenGeladen) {
            return;
        }

        $this->hinweis = null;

        try {
            $this->stellen = app(LegacyBewerbungLinksClient::class)->stellen();
            $this->stellenGeladen = true;
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
        $this->laeufe = [];
        $this->fehler = null;
        $this->hinweis = null;
        $this->status = 'idle';

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

    public function varianteHinzufuegen(): void
    {
        if (count($this->varianten) >= $this->maxVarianten()) {
            return;
        }

        $letzte = $this->varianten[array_key_last($this->varianten)] ?? $this->standardVariante();
        $this->varianten[] = $this->kopie($letzte, count($this->varianten));
        $this->hinweis = null;
    }

    public function varianteDuplizieren(string $uid): void
    {
        if (count($this->varianten) >= $this->maxVarianten()) {
            return;
        }

        foreach ($this->varianten as $index => $variante) {
            if (($variante['_uid'] ?? '') !== $uid) {
                continue;
            }

            array_splice($this->varianten, $index + 1, 0, [$this->kopie($variante, $index + 1)]);
            $this->varianten = array_values($this->varianten);
            $this->benenne();

            return;
        }
    }

    public function varianteEntfernen(string $uid): void
    {
        if (count($this->varianten) <= 1) {
            return;
        }

        $this->varianten = array_values(array_filter(
            $this->varianten,
            fn (array $variante): bool => ($variante['_uid'] ?? '') !== $uid,
        ));
        unset($this->laeufe[$uid]);
        $this->benenne();
    }

    public function varianteZuruecksetzen(string $uid): void
    {
        $standard = $this->standardVariante();
        foreach ($this->varianten as $index => $variante) {
            if (($variante['_uid'] ?? '') !== $uid) {
                continue;
            }

            $standard['_uid'] = $variante['_uid'];
            $standard['name'] = $variante['name'] ?? $this->buchstabe($index);
            $this->varianten[$index] = $standard;
        }
    }

    public function testen(): void
    {
        $this->fehler = null;

        if (count($this->varianten) > $this->maxVarianten()) {
            $this->fehler = 'Höchstens '.$this->maxVarianten().' Varianten.';
            $this->status = 'failed';

            return;
        }

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

        $this->laeufe = [];
        foreach ($this->varianten as $variante) {
            $token = (string) Str::uuid();
            $uid = (string) $variante['_uid'];
            Cache::put(TestDefinitionJob::cacheKey($token), ['status' => 'running'], now()->addHour());
            TestDefinitionJob::dispatch(
                $token,
                $links['bewerbung_ro'],
                $links['anhang_ro'],
                $this->name,
                $this->instruktionen,
                $this->bereinigteFelder(),
                AnalyseOverrides::fromArray([
                    'engine' => $variante['engine'] ?? null,
                    'tier' => $variante['tier'] ?? null,
                    'provider' => $variante['provider'] ?? null,
                    'modell' => $variante['modell'] ?? null,
                    'ohneCache' => (bool) ($variante['ohneCache'] ?? false),
                ])->toArray(),
                $this->variantenLabel($variante),
            );
            $this->laeufe[$uid] = [
                'token' => $token,
                'status' => 'running',
                'ergebnis' => null,
                'fehler' => null,
            ];
        }

        $this->status = 'running';
        $this->serverSichtbar = true;
        $this->dispatch('test-laeuft', aktiv: true);
    }

    #[On('lauf-beendet')]
    public function laufBeendet(string $token): void
    {
        foreach ($this->laeufe as $uid => $lauf) {
            if (($lauf['token'] ?? '') !== $token) {
                continue;
            }

            $cached = Cache::get(TestDefinitionJob::cacheKey($token));
            if (! is_array($cached) || ($cached['status'] ?? '') === 'running') {
                return;
            }

            if (($cached['status'] ?? '') === 'done') {
                $this->laeufe[$uid]['status'] = 'done';
                $this->laeufe[$uid]['ergebnis'] = is_array($cached['result'] ?? null) ? $cached['result'] : null;
                $this->laeufe[$uid]['fehler'] = null;
            } else {
                $this->laeufe[$uid]['status'] = 'failed';
                $this->laeufe[$uid]['fehler'] = (string) ($cached['error'] ?? 'Test fehlgeschlagen.');
            }
        }

        $offen = collect($this->laeufe)->contains(
            fn (array $lauf): bool => ($lauf['status'] ?? '') === 'running',
        );
        if ($offen) {
            return;
        }

        $this->status = collect($this->laeufe)->contains(
            fn (array $lauf): bool => ($lauf['status'] ?? '') === 'done',
        ) ? 'done' : 'failed';
        $this->dispatch('test-laeuft', aktiv: false);
    }

    public function maxVarianten(): int
    {
        return max(1, (int) IntranetAppBewerbungenSettings::resolvedAppSettings()->maxTestVarianten);
    }

    public function zeigeServer(): bool
    {
        if (! $this->serverSichtbar) {
            return false;
        }

        foreach ($this->varianten as $variante) {
            if (($variante['provider'] ?? '') === AiProvider::GemmaLlamaCpp->value) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    public function providerOptionen(): array
    {
        $konfiguriert = config('ai.providers', []);
        $optionen = [];
        foreach (AiProvider::cases() as $provider) {
            if (is_array($konfiguriert[$provider->value] ?? null)) {
                $optionen[$provider->value] = $provider->label();
            }
        }

        return $optionen;
    }

    /**
     * @return list<string>
     */
    public function modellVorschlaege(string $provider): array
    {
        $settings = IntranetAppBewerbungenSettings::resolvedAppSettings();
        $werte = [
            $settings->textModelOverride(),
            $settings->bewerbungenAuswertungModelGemmaLlamaCpp,
            $settings->bewerbungenAuswertungModelOpenWebUi,
            $settings->bewerbungenAuswertungModelLangdock,
            config('ai.providers.'.$provider.'.models.text.default'),
        ];

        return array_values(array_unique(array_filter(
            array_map(fn (mixed $wert): string => trim((string) $wert), $werte),
            fn (string $wert): bool => $wert !== '',
        )));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function kopf(string $uid): ?array
    {
        $lauf = $this->laeufe[$uid] ?? null;
        if (! is_array($lauf)) {
            return null;
        }

        $protokoll = AnalyseLaufProtokoll::zustand('test-'.(string) ($lauf['token'] ?? ''));

        return is_array($protokoll) ? $protokoll : null;
    }

    /**
     * @return list<array{key: string, label: string, pflicht: bool, werte: list<string>, leer: list<bool>, unterschiedlich: bool}>
     */
    public function vergleichZeilen(): array
    {
        $spalten = [];
        foreach ($this->varianten as $variante) {
            $lauf = $this->laeufe[$variante['_uid']] ?? null;
            $ergebnis = is_array($lauf) ? ($lauf['ergebnis'] ?? null) : null;
            if (! is_array($ergebnis)) {
                $spalten[] = null;

                continue;
            }

            $map = [];
            foreach (array_merge($ergebnis['fields'] ?? [], $ergebnis['system'] ?? []) as $eintrag) {
                if (! is_array($eintrag)) {
                    continue;
                }
                $map[(string) ($eintrag['key'] ?? '')] = $eintrag;
            }
            $spalten[] = $map;
        }

        $keys = [];
        foreach ($spalten as $map) {
            if (! is_array($map)) {
                continue;
            }
            foreach ($map as $key => $eintrag) {
                $keys[$key] = (string) ($eintrag['label'] ?? $key);
            }
        }

        $pflicht = [];
        foreach ($this->felder as $feld) {
            if (! empty($feld['pflicht'])) {
                $pflicht[] = (string) ($feld['key'] ?? '');
            }
        }

        $zeilen = [];
        foreach ($keys as $key => $label) {
            $werte = [];
            $leer = [];
            foreach ($spalten as $map) {
                $wert = is_array($map) ? ($map[$key]['value'] ?? null) : null;
                $text = $this->wertText($wert);
                $werte[] = $text;
                $leer[] = $text === '—';
            }
            $vorhanden = [];
            foreach ($werte as $index => $wert) {
                if ($spalten[$index] !== null) {
                    $vorhanden[] = $wert;
                }
            }
            $zeilen[] = [
                'key' => (string) $key,
                'label' => $label,
                'pflicht' => in_array((string) $key, $pflicht, true),
                'werte' => $werte,
                'leer' => $leer,
                'unterschiedlich' => count($vorhanden) > 1 && count(array_unique($vorhanden)) > 1,
            ];
        }

        return $zeilen;
    }

    /**
     * @return array<string, list<string>>
     */
    public function auszeichnungen(): array
    {
        $fertig = [];
        foreach ($this->varianten as $variante) {
            $uid = (string) $variante['_uid'];
            $lauf = $this->laeufe[$uid] ?? null;
            if (! is_array($lauf) || ($lauf['status'] ?? '') !== 'done') {
                continue;
            }

            $protokoll = $this->kopf($uid);
            $llm = is_array($protokoll['llm'] ?? null) ? $protokoll['llm'] : [];
            $gefuellt = 0;
            foreach ($lauf['ergebnis']['fields'] ?? [] as $feld) {
                if (! is_array($feld)) {
                    continue;
                }
                $wert = $feld['value'] ?? null;
                if ($wert !== null && $wert !== '' && $wert !== []) {
                    $gefuellt++;
                }
            }
            $fertig[$uid] = [
                'dauer' => is_numeric($protokoll['duration_ms'] ?? null) ? (int) $protokoll['duration_ms'] : null,
                'tokens' => (int) ($llm['input_tokens'] ?? 0) + (int) ($llm['output_tokens'] ?? 0),
                'gefuellt' => $gefuellt,
            ];
        }

        if (count($fertig) < 2) {
            return [];
        }

        $badges = [];
        $dauern = array_filter(array_column($fertig, 'dauer'), is_int(...));
        if ($dauern !== []) {
            $beste = min($dauern);
            foreach ($fertig as $uid => $werte) {
                if ($werte['dauer'] === $beste) {
                    $badges[$uid][] = 'schnellste';
                }
            }
        }

        $tokenWerte = array_column($fertig, 'tokens');
        if (max($tokenWerte) > 0) {
            $wenigste = min($tokenWerte);
            foreach ($fertig as $uid => $werte) {
                if ($werte['tokens'] === $wenigste) {
                    $badges[$uid][] = 'wenigste Tokens';
                }
            }
        }

        $meiste = max(array_column($fertig, 'gefuellt'));
        foreach ($fertig as $uid => $werte) {
            if ($werte['gefuellt'] === $meiste) {
                $badges[$uid][] = 'meiste Felder gefüllt';
            }
        }

        return $badges;
    }

    /**
     * @return array<string, string>
     */
    public function tierOptionen(): array
    {
        return self::TIERS;
    }

    /**
     * @param  array<string, mixed>  $variante
     * @return array<string, mixed>
     */
    private function kopie(array $variante, int $index): array
    {
        $variante['_uid'] = (string) Str::uuid();
        $variante['name'] = $this->buchstabe($index);

        return $variante;
    }

    private function benenne(): void
    {
        foreach ($this->varianten as $index => $variante) {
            if (trim((string) ($variante['name'] ?? '')) === '' || preg_match('/^[A-Z]+$/', (string) $variante['name']) === 1) {
                $this->varianten[$index]['name'] = $this->buchstabe($index);
            }
        }
    }

    private function buchstabe(int $index): string
    {
        $name = '';
        $index++;
        while ($index > 0) {
            $index--;
            $name = chr(65 + ($index % 26)).$name;
            $index = intdiv($index, 26);
        }

        return $name;
    }

    /**
     * @param  array<string, mixed>  $variante
     */
    private function variantenLabel(array $variante): string
    {
        $titel = trim($this->name) !== '' ? $this->name : 'Test';

        return 'Test „'.$titel.'“ · Variante '.($variante['name'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    private function standardVariante(): array
    {
        $settings = IntranetAppBewerbungenSettings::resolvedAppSettings();
        $engine = DocumentParseEngine::LlamaParse->value;
        $tier = 'agentic';

        try {
            $parse = app(DocumentParseConfigResolverInterface::class)->resolve('bewerbungen');
            $engine = $parse->engine->value;
            $tier = $parse->llamaParseTier;
        } catch (\Throwable) {
            // App-Standard bleibt der Fallback, der Test lässt sich trotzdem starten.
        }

        return [
            '_uid' => (string) Str::uuid(),
            'name' => 'A',
            'engine' => $engine,
            'tier' => $tier,
            'provider' => ($settings->textProviderOverride() ?? AiProvider::GemmaLlamaCpp)->value,
            'modell' => (string) ($settings->textModelOverride() ?? ''),
            'ohneCache' => false,
        ];
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

    private function wertText(mixed $wert): string
    {
        if (is_array($wert)) {
            return $wert === [] ? '—' : implode(', ', array_map(strval(...), $wert));
        }

        if ($wert === true) {
            return 'Ja';
        }

        if ($wert === false) {
            return 'Nein';
        }

        if ($wert === null || $wert === '') {
            return '—';
        }

        return (string) $wert;
    }
};
?>

<div class="space-y-6">
    @island(name: 'auswahl', lazy: true)
        <div wire:init="stellenLaden" class="space-y-4">
            <div>
                <flux:heading size="lg">Bewerbung</flux:heading>
                <flux:text class="mt-1">Der Test lädt die Dokumente aus OneDrive und wertet sie mit der ungespeicherten Definition aus.</flux:text>
            </div>

            <div wire:loading wire:target="stellenLaden" class="text-sm text-zinc-500">Stellen werden geladen…</div>

            @if ($hinweis)
                <flux:callout>{{ $hinweis }}</flux:callout>
            @endif

            <div class="grid gap-4 lg:grid-cols-2">
                <flux:select wire:model.live="stelleId" variant="listbox" searchable label="Stelle" placeholder="Stelle wählen">
                    @foreach ($stellen as $stelle)
                        <flux:select.option value="{{ $stelle['id'] }}">{{ $stelle['bezeichnung'] }}</flux:select.option>
                    @endforeach
                </flux:select>

                @if ($stelleId !== '')
                    <flux:select wire:model.live="bewerbungId" variant="listbox" searchable label="Bewerbung" placeholder="Bewerbung wählen">
                        @foreach ($bewerbungen as $bewerbung)
                            <flux:select.option value="{{ $bewerbung['id'] }}">{{ $bewerbung['label'] }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
            </div>
        </div>
    @endisland

    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:heading size="lg">Varianten</flux:heading>
        <flux:button
            type="button"
            size="sm"
            icon="plus"
            wire:click="varianteHinzufuegen"
            :disabled="count($varianten) >= $this->maxVarianten()"
        >
            Variante
        </flux:button>
    </div>
    @if (count($varianten) >= $this->maxVarianten())
        <flux:text class="text-sm">Maximal {{ $this->maxVarianten() }} Varianten (App-Einstellung).</flux:text>
    @endif

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        @foreach ($varianten as $index => $variante)
            <flux:card wire:key="variante-{{ $variante['_uid'] }}" class="space-y-3">
                <div class="flex items-center justify-between gap-2">
                    <flux:input wire:model="varianten.{{ $index }}.name" placeholder="Name" class="font-medium" />
                    <flux:dropdown>
                        <flux:button type="button" variant="ghost" size="sm" icon="ellipsis-horizontal" />
                        <flux:menu>
                            <flux:menu.item icon="document-duplicate" wire:click="varianteDuplizieren('{{ $variante['_uid'] }}')">Duplizieren</flux:menu.item>
                            <flux:menu.item icon="arrow-path" wire:click="varianteZuruecksetzen('{{ $variante['_uid'] }}')">Auf App-Standard</flux:menu.item>
                            <flux:menu.item icon="trash" wire:click="varianteEntfernen('{{ $variante['_uid'] }}')">Entfernen</flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                </div>

                <flux:select wire:model.live="varianten.{{ $index }}.engine" label="Parse-Motor" variant="listbox">
                    @foreach (DocumentParseEngine::cases() as $engine)
                        <flux:select.option value="{{ $engine->value }}">{{ $engine->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                @if (($variante['engine'] ?? '') === DocumentParseEngine::LlamaParse->value)
                    <flux:select wire:model="varianten.{{ $index }}.tier" label="LlamaParse-Tier" variant="listbox">
                        @foreach ($this->tierOptionen() as $wert => $label)
                            <flux:select.option value="{{ $wert }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif

                <flux:select wire:model.live="varianten.{{ $index }}.provider" label="Text-Provider" variant="listbox">
                    @foreach ($this->providerOptionen() as $wert => $label)
                        <flux:select.option value="{{ $wert }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:autocomplete wire:model="varianten.{{ $index }}.modell" label="Modell" placeholder="Modell">
                    @foreach ($this->modellVorschlaege((string) ($variante['provider'] ?? '')) as $modell)
                        <flux:autocomplete.item wire:key="modell-{{ $variante['_uid'] }}-{{ $modell }}">{{ $modell }}</flux:autocomplete.item>
                    @endforeach
                </flux:autocomplete>

                <flux:switch wire:model="varianten.{{ $index }}.ohneCache" label="Zwischenspeicher ignorieren" />
            </flux:card>
        @endforeach
    </div>

    <div class="flex flex-wrap items-center gap-3">
        <flux:button type="button" variant="primary" wire:click="testen" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="testen">
                {{ count($varianten) > 1 ? count($varianten).' Varianten vergleichen' : 'Test starten' }}
            </span>
            <span wire:loading wire:target="testen">Test wird gestartet…</span>
        </flux:button>
    </div>

    @if ($fehler)
        <flux:callout variant="danger">{{ $fehler }}</flux:callout>
    @endif

    @if ($laeufe !== [])
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            @foreach ($varianten as $variante)
                @php($lauf = $laeufe[$variante['_uid']] ?? null)
                @if (is_array($lauf) && ($lauf['token'] ?? '') !== '')
                    <livewire:intranet-app-bewerbungen::apps.bewerbungen.pipeline.lauf-live
                        lazy
                        :test-token="$lauf['token']"
                        :kompakt="true"
                        :parse-hinweis="\Hwkdo\IntranetAppBewerbungen\Support\PipelineFormat::parseZeile(['engine' => $variante['engine'] ?? null, 'tier' => $variante['tier'] ?? null])"
                        :text-hinweis="\Hwkdo\IntranetAppBewerbungen\Support\PipelineFormat::textZeile(['provider' => $variante['provider'] ?? null, 'modell' => $variante['modell'] ?? null])"
                        :wire:key="'live-'.$variante['_uid'].'-'.$lauf['token']"
                    />
                @endif
            @endforeach
        </div>
    @endif

    @if ($this->zeigeServer())
        <div class="grid gap-4 lg:grid-cols-2">
            <livewire:intranet-app-bewerbungen::apps.bewerbungen.pipeline.llm-server lazy :kompakt="true" :wire:key="'werkbank-llm'" />
            <livewire:intranet-app-bewerbungen::apps.bewerbungen.pipeline.dgx-host lazy :kompakt="true" :wire:key="'werkbank-dgx'" />
        </div>
    @endif

    @php($zeilen = $this->vergleichZeilen())
    @if ($zeilen !== [])
        @php($badges = $this->auszeichnungen())
        <div class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <flux:heading size="lg">Vergleich</flux:heading>
                <flux:switch wire:model.live="nurUnterschiede" label="Nur Unterschiede" />
            </div>

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                @foreach ($varianten as $variante)
                    @php($protokoll = $this->kopf((string) $variante['_uid']))
                    @if (is_array($protokoll))
                        <div wire:key="kopf-{{ $variante['_uid'] }}" class="space-y-2">
                            <div class="font-medium">{{ $variante['name'] ?? '' }}</div>
                            <div class="flex flex-wrap gap-1">
                                @foreach ($badges[$variante['_uid']] ?? [] as $badge)
                                    <flux:badge size="sm" color="emerald">{{ $badge }}</flux:badge>
                                @endforeach
                            </div>
                            <x-intranet-app-bewerbungen::pipeline.lauf-kennzahlen :lauf="$protokoll" />
                            @php($anteile = \Hwkdo\IntranetAppBewerbungen\Support\AnalyseLaufProtokoll::liveAnteile($protokoll))
                            <x-intranet-app-bewerbungen::pipeline.phasen-balken :ms="$anteile['ms']" :anteile="$anteile['anteile']" />
                        </div>
                    @endif
                @endforeach
            </div>

            <div class="overflow-x-auto">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column class="sticky left-0 bg-white dark:bg-zinc-900">Feld</flux:table.column>
                        @foreach ($varianten as $variante)
                            <flux:table.column>{{ $variante['name'] ?? '' }}</flux:table.column>
                        @endforeach
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($zeilen as $zeile)
                            @if (! $nurUnterschiede || $zeile['unterschiedlich'])
                                <flux:table.row wire:key="vergleich-{{ $zeile['key'] }}" @class(['bg-amber-500/10' => $zeile['unterschiedlich']])>
                                    <flux:table.cell class="sticky left-0 bg-white font-medium dark:bg-zinc-900">{{ $zeile['label'] }}</flux:table.cell>
                                    @foreach ($zeile['werte'] as $wertIndex => $wert)
                                        <flux:table.cell @class([
                                            'whitespace-pre-wrap',
                                            'text-red-600 dark:text-red-400' => $zeile['pflicht'] && ($zeile['leer'][$wertIndex] ?? false),
                                        ])>
                                            {{ $wert }}
                                        </flux:table.cell>
                                    @endforeach
                                </flux:table.row>
                            @endif
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>

            <flux:accordion>
                @foreach ($varianten as $variante)
                    @php($lauf = $laeufe[$variante['_uid']] ?? null)
                    @php($protokoll = $this->kopf((string) $variante['_uid']))
                    @if (is_array($lauf) && is_array($lauf['ergebnis'] ?? null))
                        <flux:accordion.item wire:key="json-{{ $variante['_uid'] }}">
                            <flux:accordion.heading>Variante {{ $variante['name'] ?? '' }}: Dokumente und JSON</flux:accordion.heading>
                            <flux:accordion.content class="space-y-3">
                                <x-intranet-app-bewerbungen::pipeline.dokumente-liste :dokumente="is_array($protokoll) ? ($protokoll['dokumente'] ?? []) : []" />
                                <pre class="max-h-80 overflow-auto rounded-lg border border-zinc-200 p-3 text-xs dark:border-zinc-700">{{ json_encode($lauf['ergebnis'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                            </flux:accordion.content>
                        </flux:accordion.item>
                    @endif
                @endforeach
            </flux:accordion>
        </div>
    @endif
</div>
