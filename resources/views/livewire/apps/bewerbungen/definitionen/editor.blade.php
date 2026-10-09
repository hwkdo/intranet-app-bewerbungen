<?php

use Hwkdo\IntranetAppBewerbungen\Data\KiFeld;
use Hwkdo\IntranetAppBewerbungen\Enums\KiFeldTyp;
use Hwkdo\IntranetAppBewerbungen\Models\KiDefinition;
use Hwkdo\IntranetAppBewerbungen\Support\KiErgebnisEnvelope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
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
        <form wire:submit="save" class="grid gap-8 lg:grid-cols-2">
            <div class="space-y-4">
                <flux:input wire:model="name" label="Name" required />
                <flux:error name="name" />
                <flux:textarea wire:model="beschreibung" label="Beschreibung" rows="2" />
                <flux:textarea wire:model="instruktionen" label="Instruktionen" rows="12" required />
                <flux:error name="instruktionen" />
                <flux:checkbox wire:model="isDefault" label="Standard-Definition" />
                <flux:error name="isDefault" />

                <div class="flex items-center justify-between">
                    <flux:heading size="lg">Felder</flux:heading>
                    <flux:button type="button" size="sm" icon="plus" wire:click="feldHinzufuegen">Feld</flux:button>
                </div>
                <flux:error name="felder" />

                <div wire:sort="sortiereFeld" class="space-y-3">
                    @foreach ($felder as $index => $feld)
                        <div wire:key="{{ $feld['_uid'] }}" wire:sort:item="{{ $feld['_uid'] }}" class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                            <div class="mb-3 flex items-center justify-between gap-2">
                                <div wire:sort:handle class="cursor-grab text-sm text-zinc-500">Ziehen</div>
                                <flux:button type="button" size="sm" variant="ghost" wire:click="feldEntfernen('{{ $feld['_uid'] }}')">Entfernen</flux:button>
                            </div>
                            <div class="grid gap-3 md:grid-cols-2">
                                <flux:input wire:model="felder.{{ $index }}.key" label="Schlüssel" />
                                <flux:input wire:model="felder.{{ $index }}.label" label="Bezeichnung" />
                                <flux:select wire:model.live="felder.{{ $index }}.typ" label="Typ">
                                    @foreach (KiFeldTyp::options() as $wert => $label)
                                        <flux:select.option :value="$wert">{{ $label }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                                <div class="flex items-end">
                                    <flux:checkbox wire:model="felder.{{ $index }}.pflicht" label="Pflichtfeld" />
                                </div>
                            </div>
                            <div class="mt-3">
                                <flux:textarea wire:model="felder.{{ $index }}.hinweis" label="Hinweis für das Modell" rows="2" />
                            </div>
                            @if (($feld['typ'] ?? '') === 'auswahl')
                                <div class="mt-3">
                                    <flux:input wire:model="felder.{{ $index }}.optionenText" label="Optionen, kommagetrennt" />
                                    <flux:error name="felder.{{ $index }}.optionenText" />
                                </div>
                            @endif
                            <flux:error name="felder.{{ $index }}.key" />
                            <flux:error name="felder.{{ $index }}.label" />
                        </div>
                    @endforeach
                </div>

                <div class="flex gap-2">
                    <flux:button type="submit" variant="primary">Speichern</flux:button>
                    <flux:button :href="route('apps.bewerbungen.definitionen.index')" wire:navigate>Zurück</flux:button>
                </div>
            </div>

            <div>
                <livewire:intranet-app-bewerbungen::apps.bewerbungen.definitionen.werkbank
                    :name="$name"
                    :instruktionen="$instruktionen"
                    :felder="$felder"
                    :key="'werkbank-'.$definitionId"
                />
            </div>
        </form>
    </x-intranet-app-bewerbungen::bewerbungen-layout>
</div>
