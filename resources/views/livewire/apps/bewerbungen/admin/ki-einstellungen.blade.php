<?php

use Flux\Flux;
use Hwkdo\IntranetAppBase\Contracts\AiConfigResolverInterface;
use Hwkdo\IntranetAppBase\Enums\AiCapability;
use Hwkdo\IntranetAppBase\Enums\AiProvider;
use Hwkdo\IntranetAppBewerbungen\Data\AppSettings;
use Hwkdo\IntranetAppBewerbungen\Models\IntranetAppBewerbungenSettings;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public string $provider = '';

    public string $model = '';

    public function mount(): void
    {
        $settings = IntranetAppBewerbungenSettings::resolvedAppSettings();
        $provider = $settings->aiTextProviderOverride
            ?? $settings->textProviderOverride()
            ?? AiProvider::Bifrost;

        $this->provider = $provider->value;
        $this->model = is_string($settings->aiTextModelOverride) ? trim($settings->aiTextModelOverride) : '';
    }

    public function save(): void
    {
        $this->validate([
            'provider' => ['required', 'string', Rule::enum(AiProvider::class)],
            'model' => 'nullable|string|max:150',
        ]);

        $current = IntranetAppBewerbungenSettings::resolvedAppSettings();
        $model = trim($this->model);

        $settings = AppSettings::from(array_merge($current->toArray(), [
            'aiTextProviderOverride' => AiProvider::from($this->provider),
            'aiTextModelOverride' => $model === '' ? null : $model,
        ]));

        IntranetAppBewerbungenSettings::persistAppSettings($settings);

        unset($this->effectiveSummary, $this->providerDefaultModel);

        Flux::toast(
            heading: 'Gespeichert',
            text: 'KI-Einstellungen wurden gespeichert.',
            variant: 'success',
        );
    }

    /**
     * @return list<AiProvider>
     */
    #[Computed]
    public function providers(): array
    {
        return array_values(array_filter(
            AiProvider::cases(),
            fn (AiProvider $provider): bool => $provider !== AiProvider::ComfyUi,
        ));
    }

    #[Computed]
    public function providerDefaultModel(): string
    {
        $provider = AiProvider::tryFrom($this->provider);
        if ($provider === null) {
            return '';
        }

        return trim((string) config('ai.providers.'.$provider->value.'.models.text.default', ''));
    }

    #[Computed]
    public function effectiveSummary(): string
    {
        $resolved = app(AiConfigResolverInterface::class)->resolve('bewerbungen', AiCapability::Agent);
        $model = is_string($resolved->model) && trim($resolved->model) !== ''
            ? trim($resolved->model)
            : 'kein Standardmodell konfiguriert';

        return $resolved->provider->label().' / '.$model;
    }
};
?>

<flux:card class="glass-card">
    <flux:heading size="lg" class="mb-2">KI</flux:heading>
    <flux:text class="mb-6 text-sm text-zinc-500">
        Ein Provider und ein Modell. Die Auswertung verwendet genau diese beiden Werte.
    </flux:text>

    <flux:callout class="mb-6" icon="information-circle">
        <flux:callout.heading>Aktuell wirksam</flux:callout.heading>
        <flux:callout.text>
            {{ $this->effectiveSummary }}
        </flux:callout.text>
    </flux:callout>

    <form wire:submit="save" class="space-y-4">
        <flux:select wire:model.live="provider" label="Provider" variant="listbox">
            @foreach ($this->providers as $providerOption)
                <flux:select.option value="{{ $providerOption->value }}" wire:key="bewerbungen-ki-provider-{{ $providerOption->value }}">
                    {{ $providerOption->label() }}
                </flux:select.option>
            @endforeach
        </flux:select>

        <flux:input
            wire:model="model"
            label="Modell"
            :placeholder="$this->providerDefaultModel !== '' ? $this->providerDefaultModel : 'Standardmodell des Providers'"
            description="Leer lassen, dann gilt das Standardmodell des gewählten Providers. Bei Bifrost ist das der Modellname, den das Gateway kennt."
        />

        <div class="flex justify-end">
            <flux:button type="submit" variant="primary">Speichern</flux:button>
        </div>
    </form>
</flux:card>
