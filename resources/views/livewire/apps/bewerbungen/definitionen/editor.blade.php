<?php

use Hwkdo\IntranetAppBewerbungen\Data\KiFeld;
use Hwkdo\IntranetAppBewerbungen\Enums\KiFeldTyp;
use Hwkdo\IntranetAppBewerbungen\Models\KiDefinition;
use Hwkdo\IntranetAppBewerbungen\Support\KiErgebnisEnvelope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('KI-Definition')] class extends Component
{
    public ?int $definitionId = null;

    public string $name = '';

    public string $beschreibung = '';

    public string $instruktionen = '';

    public bool $isDefault = false;

    /** @var list<array<string, mixed>> */
    public array $felder = [];

    #[Url(as: 'tab')]
    public string $tab = 'einstellungen';

    public bool $testsLaufen = false;

    public function mount(): void
    {
        $parameter = request()->route('definition');
        $definition = $parameter instanceof KiDefinition
            ? $parameter
            : (is_numeric($parameter) ? KiDefinition::query()->find($parameter) : null);

        if (! $definition instanceof KiDefinition || ! $definition->exists) {
            $this->felder = [$this->leeresFeld()];
            $this->isDefault = ! KiDefinition::query()->where('is_default', true)->exists();

            return;
        }

        $this->definitionId = $definition->id;
        $this->name = $definition->name;
        $this->beschreibung = (string) $definition->beschreibung;
        $this->instruktionen = $definition->instruktionen;
        $this->isDefault = $definition->is_default;
        $this->felder = array_map(function (KiFeld $feld): array {
            $zeile = $feld->toArray();
            $zeile['_uid'] = (string) Str::uuid();
            $zeile['optionenText'] = implode(', ', $feld->optionen);

            return $zeile;
        }, $definition->kiFelder());

        if ($this->felder === []) {
            $this->felder = [$this->leeresFeld()];
        }
    }

    public function feldHinzufuegen(): void
    {
        $this->felder[] = $this->leeresFeld();
    }

    #[On('test-laeuft')]
    public function testLaeuft(bool $aktiv = false): void
    {
        $this->testsLaufen = $aktiv;
    }

    public function feldDuplizieren(string $uid): void
    {
        foreach ($this->felder as $index => $feld) {
            if (($feld['_uid'] ?? '') !== $uid) {
                continue;
            }

            $kopie = $feld;
            $kopie['_uid'] = (string) Str::uuid();
            $basis = trim((string) ($feld['key'] ?? ''));
            if ($basis !== '') {
                $kopie['key'] = $this->eindeutigerSchluessel($basis.'_kopie');
            }
            array_splice($this->felder, $index + 1, 0, [$kopie]);
            $this->felder = array_values($this->felder);

            return;
        }
    }

    public function schluesselVorschlagen(int $index): void
    {
        if (! isset($this->felder[$index])) {
            return;
        }

        if (trim((string) ($this->felder[$index]['key'] ?? '')) !== '') {
            return;
        }

        $vorschlag = Str::slug(Str::ascii((string) ($this->felder[$index]['label'] ?? '')), '_');
        $vorschlag = preg_replace('/[^a-z0-9_]/', '', $vorschlag) ?? '';
        if ($vorschlag === '' || preg_match('/^[a-z]/', $vorschlag) !== 1) {
            return;
        }

        $this->felder[$index]['key'] = $this->eindeutigerSchluessel($vorschlag, $index);
    }

    public function feldEntfernen(string $uid): void
    {
        $this->felder = array_values(array_filter(
            $this->felder,
            fn (array $feld): bool => ($feld['_uid'] ?? '') !== $uid,
        ));
    }

    public function sortiereFeld(string $uid, int $position): void
    {
        $index = array_search($uid, array_column($this->felder, '_uid'), true);
        if ($index === false) {
            return;
        }

        $feld = $this->felder[$index];
        array_splice($this->felder, $index, 1);
        array_splice($this->felder, max(0, $position), 0, [$feld]);
        $this->felder = array_values($this->felder);
    }

    public function save(): void
    {
        $bereinigt = array_map(
            fn (array $feld): array => KiFeld::fromArray($feld)->toArray(),
            $this->felder,
        );

        $this->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('intranet_app_bewerbungen_ki_definitionen', 'name')
                    ->ignore($this->definitionId)
                    ->whereNull('deleted_at'),
            ],
            'beschreibung' => ['nullable', 'string'],
            'instruktionen' => ['required', 'string'],
            'felder' => ['required', 'array', 'min:1'],
            'felder.*.key' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/', Rule::notIn(KiErgebnisEnvelope::RESERVED_KEYS)],
            'felder.*.label' => ['required', 'string', 'max:255'],
            'felder.*.typ' => ['required', Rule::enum(KiFeldTyp::class)],
        ], [
            'felder.*.key.not_in' => 'Dieser Schlüssel ist für Systemfelder reserviert.',
            'felder.*.key.regex' => 'Schlüssel: Kleinbuchstaben, Ziffern und Unterstriche, am Anfang ein Buchstabe.',
            'felder.min' => 'Mindestens ein Feld ist erforderlich.',
        ]);

        $keys = array_column($bereinigt, 'key');
        if (count($keys) !== count(array_unique($keys))) {
            $this->addError('felder', 'Feldschlüssel müssen eindeutig sein.');

            return;
        }

        foreach ($bereinigt as $index => $feld) {
            if ($feld['typ'] === KiFeldTyp::Auswahl->value && $feld['optionen'] === []) {
                $this->addError('felder.'.$index.'.optionenText', 'Eine Auswahl braucht mindestens eine Option.');

                return;
            }
        }

        if ($this->definitionId !== null && ! $this->isDefault) {
            $aktuell = KiDefinition::query()->find($this->definitionId);
            if ($aktuell?->is_default && ! KiDefinition::query()->where('is_default', true)->whereKeyNot($this->definitionId)->exists()) {
                $this->addError('isDefault', 'Es muss eine Standard-Definition geben.');

                return;
            }
        }

        $definition = DB::transaction(function () use ($bereinigt): KiDefinition {
            $definition = $this->definitionId !== null
                ? KiDefinition::query()->findOrFail($this->definitionId)
                : new KiDefinition;

            $definition->fill([
                'name' => $this->name,
                'beschreibung' => $this->beschreibung !== '' ? $this->beschreibung : null,
                'instruktionen' => $this->instruktionen,
                'felder' => $bereinigt,
            ]);
            $definition->save();

            if ($this->isDefault || ! KiDefinition::query()->where('is_default', true)->exists()) {
                $definition->alsStandardSetzen();
            }

            return $definition;
        });

        $this->redirect(route('apps.bewerbungen.definitionen.edit', $definition), navigate: true);
    }

    private function eindeutigerSchluessel(string $basis, ?int $ausser = null): string
    {
        $vorhanden = [];
        foreach ($this->felder as $index => $feld) {
            if ($index === $ausser) {
                continue;
            }
            $vorhanden[] = (string) ($feld['key'] ?? '');
        }

        $vorschlag = $basis;
        $nummer = 2;
        while (in_array($vorschlag, $vorhanden, true)) {
            $vorschlag = $basis.'_'.$nummer;
            $nummer++;
        }

        return $vorschlag;
    }

    /**
     * @return array<string, mixed>
     */
    private function leeresFeld(): array
    {
        return [
            '_uid' => (string) Str::uuid(),
            'key' => '',
            'label' => '',
            'typ' => KiFeldTyp::Text->value,
            'hinweis' => '',
            'pflicht' => false,
            'optionenText' => '',
        ];
    }
};
?>

<div>
    <x-intranet-app-bewerbungen::bewerbungen-layout heading="{{ $definitionId ? 'Definition bearbeiten' : 'Neue Definition' }}" subheading="KI-Auswertung">
        <flux:tab.group>
            <flux:tabs wire:model="tab">
                <flux:tab name="einstellungen" icon="adjustments-horizontal">Einstellungen</flux:tab>
                <flux:tab name="testen" icon="beaker">
                    Testen
                    @if ($testsLaufen)
                        <span class="ml-2 inline-flex size-2 animate-pulse rounded-full bg-amber-500"></span>
                    @endif
                </flux:tab>
            </flux:tabs>

            <flux:tab.panel name="einstellungen">
                <form wire:submit="save" class="space-y-6">
                    <flux:card class="space-y-4">
                        <div class="grid items-end gap-4" style="grid-template-columns: minmax(0, 1fr) minmax(0, 1.4fr) auto">
                            <flux:input wire:model="name" label="Name" required />
                            <flux:input wire:model="beschreibung" label="Beschreibung" />
                            <div class="pb-2">
                                <flux:switch wire:model="isDefault" label="Standard" />
                            </div>
                        </div>
                        <flux:error name="name" />
                        <flux:error name="isDefault" />
                        <flux:textarea wire:model="instruktionen" label="Instruktionen" rows="6" required class="field-sizing-content max-h-80" />
                        <flux:error name="instruktionen" />
                    </flux:card>

                    <div x-data="{ suche: '' }" class="space-y-2">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="flex items-center gap-2">
                                <flux:heading>Felder</flux:heading>
                                <flux:badge size="sm" color="zinc">{{ count($felder) }}</flux:badge>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <flux:input x-model="suche" size="sm" placeholder="Schlüssel oder Bezeichnung" icon="magnifying-glass" class="w-56" />
                                <flux:button type="button" size="sm" icon="plus" wire:click="feldHinzufuegen">Feld</flux:button>
                            </div>
                        </div>
                        <flux:error name="felder" />

                        @php
                            $feldSpalten = '1.25rem minmax(5.75rem, 0.7fr) minmax(0, 1.05fr) 6.5rem 2.25rem minmax(0, 1.6fr) auto';
                        @endphp
                        <div class="space-y-1">
                                <div class="grid items-center gap-1.5 px-1.5 text-[11px] font-medium tracking-wide text-zinc-400 uppercase" style="grid-template-columns: {{ $feldSpalten }}">
                                    <span></span>
                                    <span>Schlüssel</span>
                                    <span>Bezeichnung</span>
                                    <span>Typ</span>
                                    <span class="text-center">Pflicht</span>
                                    <span>Hinweis</span>
                                    <span></span>
                                </div>

                                <div wire:sort="sortiereFeld" wire:replace class="flex flex-col gap-1 pb-4">
                                    @foreach ($felder as $index => $feld)
                                        @php
                                            $filter = mb_strtolower(trim(($feld['key'] ?? '').' '.($feld['label'] ?? '')));
                                            $hatFehler = $errors->has('felder.'.$index.'.key') || $errors->has('felder.'.$index.'.label') || $errors->has('felder.'.$index.'.optionenText');
                                        @endphp
                                        <div
                                            wire:key="{{ $feld['_uid'] }}"
                                            wire:sort:item="{{ $feld['_uid'] }}"
                                            data-filter="{{ $filter }}"
                                            x-show="suche === '' || ($el.dataset.filter || '').includes(suche.toLowerCase())"
                                            @class([
                                                'rounded-md border border-zinc-200 bg-white px-1.5 py-1 dark:border-zinc-700 dark:bg-zinc-900',
                                                'border-red-400 dark:border-red-500' => $hatFehler,
                                            ])
                                        >
                                            <div class="grid items-center gap-1.5" style="grid-template-columns: {{ $feldSpalten }}">
                                                <div wire:sort:handle class="cursor-grab text-zinc-400">
                                                    <flux:icon name="bars-2" class="size-4" />
                                                </div>
                                                <flux:input wire:model="felder.{{ $index }}.key" size="xs" placeholder="schluessel" aria-label="Schlüssel" class="min-w-0 font-mono" class:input="min-w-0" />
                                                <flux:input wire:model="felder.{{ $index }}.label" wire:blur="schluesselVorschlagen({{ $index }})" size="xs" placeholder="Bezeichnung" aria-label="Bezeichnung" class="min-w-0" class:input="min-w-0" />
                                                <flux:select wire:model.live="felder.{{ $index }}.typ" size="xs" aria-label="Typ" class="min-w-0">
                                                    @foreach (KiFeldTyp::options() as $wert => $label)
                                                        <flux:select.option :value="$wert">{{ $label }}</flux:select.option>
                                                    @endforeach
                                                </flux:select>
                                                <div class="flex justify-center">
                                                    <flux:switch wire:model="felder.{{ $index }}.pflicht" aria-label="Pflicht" />
                                                </div>
                                                <flux:input wire:model="felder.{{ $index }}.hinweis" size="xs" placeholder="Hinweis für das Modell" aria-label="Hinweis" class="min-w-0" class:input="min-w-0" />
                                                <div class="flex items-center justify-end">
                                                    <flux:button type="button" variant="ghost" size="xs" icon="document-duplicate" title="Duplizieren" wire:click="feldDuplizieren('{{ $feld['_uid'] }}')" />
                                                    <flux:button type="button" variant="ghost" size="xs" icon="trash" title="Entfernen" wire:click="feldEntfernen('{{ $feld['_uid'] }}')" />
                                                </div>
                                            </div>
                                            @if ($hatFehler)
                                                <div class="mt-0.5 pl-6 text-xs">
                                                    <flux:error name="felder.{{ $index }}.key" />
                                                    <flux:error name="felder.{{ $index }}.label" />
                                                </div>
                                            @endif
                                            @if (($feld['typ'] ?? '') === 'auswahl')
                                                <div class="mt-1 grid items-center gap-1.5" style="grid-template-columns: 1.25rem minmax(0, 1fr)">
                                                    <span></span>
                                                    <flux:input wire:model="felder.{{ $index }}.optionenText" size="xs" placeholder="Optionen, kommagetrennt" aria-label="Optionen" />
                                                </div>
                                                <flux:error name="felder.{{ $index }}.optionenText" />
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                    </div>

                    <div class="sticky bottom-0 z-10 -mx-2 flex flex-wrap items-center gap-2 border-t border-zinc-200 bg-white/90 px-2 py-3 backdrop-blur dark:border-zinc-700 dark:bg-zinc-900/90">
                        <flux:button type="submit" variant="primary">Speichern</flux:button>
                        <flux:button :href="route('apps.bewerbungen.definitionen.index')" wire:navigate>Zurück</flux:button>
                        <span wire:dirty class="text-sm text-amber-600 dark:text-amber-400">Ungespeicherte Änderungen</span>
                    </div>
                </form>
            </flux:tab.panel>

            <flux:tab.panel name="testen">
                <livewire:intranet-app-bewerbungen::apps.bewerbungen.definitionen.werkbank
                    lazy
                    :name="$name"
                    :instruktionen="$instruktionen"
                    :felder="$felder"
                    :key="'werkbank-'.$definitionId"
                />
            </flux:tab.panel>
        </flux:tab.group>
    </x-intranet-app-bewerbungen::bewerbungen-layout>
</div>
