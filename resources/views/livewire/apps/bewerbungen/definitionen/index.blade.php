<?php

use Hwkdo\IntranetAppBewerbungen\Models\KiDefinition;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('KI-Definitionen')] class extends Component
{
    public function alsStandard(int $definitionId): void
    {
        KiDefinition::query()->findOrFail($definitionId)->alsStandardSetzen();
    }

    public function duplizieren(int $definitionId): void
    {
        $quelle = KiDefinition::query()->findOrFail($definitionId);
        $kopie = $quelle->replicate();
        $kopie->name = $this->kopieName($quelle->name);
        $kopie->is_default = false;
        $kopie->save();
    }

    public function loeschen(int $definitionId): void
    {
        $definition = KiDefinition::query()->findOrFail($definitionId);
        if ($definition->is_default) {
            return;
        }

        $definition->delete();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, KiDefinition>
     */
    public function definitionen(): \Illuminate\Database\Eloquent\Collection
    {
        return KiDefinition::query()->orderBy('name')->get();
    }

    private function kopieName(string $name): string
    {
        $basis = 'Kopie von '.$name;
        $kandidat = $basis;
        $zaehler = 2;
        while (KiDefinition::withTrashed()->where('name', $kandidat)->exists()) {
            $kandidat = $basis.' '.$zaehler;
            $zaehler++;
        }

        return $kandidat;
    }
};
?>

<div>
    <x-intranet-app-bewerbungen::bewerbungen-layout heading="KI-Definitionen" subheading="Auswertungen">
        <div class="mb-4 flex items-center justify-between gap-4">
            <flux:text>
                Benannte Auswertungen für Bewerbungsstellen. Eine Definition kann von mehreren Stellen verwendet werden.
            </flux:text>
            <flux:button variant="primary" icon="plus" :href="route('apps.bewerbungen.definitionen.create')">
                Neue Definition
            </flux:button>
        </div>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>Name</flux:table.column>
                <flux:table.column>Beschreibung</flux:table.column>
                <flux:table.column>Felder</flux:table.column>
                <flux:table.column>Geändert</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($this->definitionen() as $definition)
                    <flux:table.row wire:key="definition-{{ $definition->id }}">
                        <flux:table.cell>
                            <div class="font-medium">{{ $definition->name }}</div>
                            @if ($definition->is_default)
                                <flux:badge size="sm" color="zinc" class="mt-1">Standard</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>{{ $definition->beschreibung }}</flux:table.cell>
                        <flux:table.cell>{{ count($definition->kiFelder()) }}</flux:table.cell>
                        <flux:table.cell>{{ $definition->updated_at?->format('d.m.Y H:i') }}</flux:table.cell>
                        <flux:table.cell>
                            <div class="flex flex-wrap justify-end gap-2">
                                <flux:button size="sm" :href="route('apps.bewerbungen.definitionen.edit', $definition)">Bearbeiten</flux:button>
                                <flux:button size="sm" wire:click="duplizieren({{ $definition->id }})">Duplizieren</flux:button>
                                @unless ($definition->is_default)
                                    <flux:button size="sm" wire:click="alsStandard({{ $definition->id }})">Als Standard</flux:button>
                                    <flux:button size="sm" variant="danger" wire:click="loeschen({{ $definition->id }})" wire:confirm="Definition löschen? Stellen, die sie verwenden, fallen auf den Standard zurück.">Löschen</flux:button>
                                @endunless
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </x-intranet-app-bewerbungen::bewerbungen-layout>
</div>
