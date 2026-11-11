<?php

use Flux\Flux;
use Hwkdo\IntranetAppBase\Contracts\AiConfigResolverInterface;
use Hwkdo\IntranetAppBase\Contracts\IntranetBaseAiConfigSourceInterface;
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

    public string $classificationProvider = '';

    public string $classificationModel = '';

    public string $imageProvider = '';

    public string $imageModel = '';

    public string $imageEditProvider = '';

    public string $imageEditModel = '';

    public function mount(): void
    {
        $settings = IntranetAppBewerbungenSettings::resolvedAppSettings();

        $this->provider = $settings->aiTextProviderOverride?->value ?? '';
        $this->model = is_string($settings->aiTextModelOverride) ? trim($settings->aiTextModelOverride) : '';
        $this->classificationProvider = $settings->aiClassificationProviderOverride?->value ?? '';
        $this->classificationModel = $settings->classificationModelOverride() ?? '';
        $this->imageProvider = $settings->aiImageProviderOverride?->value ?? '';
        $this->imageModel = $settings->imageModelOverride() ?? '';
        $this->imageEditProvider = $settings->aiImageEditProviderOverride?->value ?? '';
        $this->imageEditModel = $settings->imageEditModelOverride() ?? '';
    }

    public function updatedImageProvider(): void
    {
        $this->imageModel = '';
    }

    public function updatedImageEditProvider(): void
    {
        $this->imageEditModel = '';
    }

    public function save(): void
    {
        $this->validate([
            'provider' => ['nullable', 'string', Rule::enum(AiProvider::class)],
            'model' => 'nullable|string|max:150',
            'classificationProvider' => ['nullable', 'string', Rule::enum(AiProvider::class)],
            'classificationModel' => 'nullable|string|max:150',
            'imageProvider' => ['nullable', 'string', Rule::enum(AiProvider::class)],
            'imageModel' => 'nullable|string|max:150',
            'imageEditProvider' => ['nullable', 'string', Rule::enum(AiProvider::class)],
            'imageEditModel' => 'nullable|string|max:150',
        ]);

        $current = IntranetAppBewerbungenSettings::resolvedAppSettings();
        $provider = trim((string) $this->provider);
        $model = trim($this->model);
        $classificationProvider = trim($this->classificationProvider);
        $classificationModel = trim($this->classificationModel);
        $imageProvider = trim($this->imageProvider);
        $imageModel = trim($this->imageModel);
        $imageEditProvider = trim($this->imageEditProvider);
        $imageEditModel = trim($this->imageEditModel);

        $settings = AppSettings::from(array_merge($current->toArray(), [
            'aiTextProviderOverride' => $provider === '' ? null : AiProvider::from($provider),
            'aiTextModelOverride' => $model === '' ? null : $model,
            'aiClassificationProviderOverride' => $classificationProvider === '' ? null : AiProvider::from($classificationProvider),
            'aiClassificationModelOverride' => $classificationModel === '' ? null : $classificationModel,
            'aiImageProviderOverride' => $imageProvider === '' ? null : AiProvider::from($imageProvider),
            'aiImageModelOverride' => $imageModel === '' ? null : $imageModel,
            'aiImageEditProviderOverride' => $imageEditProvider === '' ? null : AiProvider::from($imageEditProvider),
            'aiImageEditModelOverride' => $imageEditModel === '' ? null : $imageEditModel,
        ]));

        IntranetAppBewerbungenSettings::persistAppSettings($settings);

        unset($this->effectiveSummary, $this->baseSummary, $this->modelHint);

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
    public function baseSummary(): string
    {
        $base = app(IntranetBaseAiConfigSourceInterface::class);
        $model = $base->textModel();

        return $base->textProvider()->label().' / '.($model !== null && trim($model) !== '' ? trim($model) : 'Provider-Standard');
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

    #[Computed]
    public function modelHint(): string
    {
        if ($this->provider === '') {
            return 'Leer lassen, dann gelten Provider und Modell aus den Base Settings.';
        }

        $default = trim((string) config('ai.providers.'.$this->provider.'.models.text.default', ''));
        $shown = $default !== '' ? $default : 'dem technischen Provider-Standard';

        return 'Leer lassen, dann gilt '.$shown.'. Das ist der technische Standard dieses Providers, nicht das Modell aus den Base Settings.';
    }
};
?>

<flux:card class="glass-card">
    <flux:heading size="lg" class="mb-2">KI</flux:heading>
    <flux:text class="mb-6 text-sm text-zinc-500">
        Leere Felder übernehmen die globalen Einstellungen unter Manager → Base Settings.
    </flux:text>

    <flux:callout class="mb-4" icon="information-circle">
        <flux:callout.heading>Base Settings</flux:callout.heading>
        <flux:callout.text>
            {{ $this->baseSummary }}
        </flux:callout.text>
    </flux:callout>

    <flux:callout class="mb-6" icon="check-circle">
        <flux:callout.heading>Aktuell wirksam</flux:callout.heading>
        <flux:callout.text>
            {{ $this->effectiveSummary }}
        </flux:callout.text>
    </flux:callout>

    <form wire:submit="save" class="space-y-4">
        <flux:select wire:model.live="provider" label="Provider" variant="listbox" placeholder="Base-Default">
            <flux:select.option value="">Base-Default</flux:select.option>
            @foreach ($this->providers as $providerOption)
                <flux:select.option value="{{ $providerOption->value }}" wire:key="bewerbungen-ki-provider-{{ $providerOption->value }}">
                    {{ $providerOption->label() }}
                </flux:select.option>
            @endforeach
        </flux:select>

        <flux:input
            wire:model="model"
            label="Modell"
            placeholder="Leer = Base-Default"
            :description="$this->modelHint"
        />

        <flux:select wire:model="classificationProvider" label="Klassifikation-Provider" variant="listbox" placeholder="Base-Default">
            <flux:select.option value="">Base-Default</flux:select.option>
            @foreach ($this->providers as $providerOption)
                <flux:select.option value="{{ $providerOption->value }}" wire:key="bewerbungen-ki-classification-{{ $providerOption->value }}">
                    {{ $providerOption->label() }}
                </flux:select.option>
            @endforeach
        </flux:select>

        <flux:input
            wire:model="classificationModel"
            label="Klassifikation-Modell"
            placeholder="Leer = Base-Default"
        />

        <flux:select wire:model.live="imageProvider" label="Generate-Provider" variant="listbox" placeholder="Base-Default">
            <flux:select.option value="">Base-Default</flux:select.option>
            @foreach (AiProvider::cases() as $providerOption)
                <flux:select.option value="{{ $providerOption->value }}" wire:key="bewerbungen-ki-image-{{ $providerOption->value }}">
                    {{ $providerOption->label() }}
                </flux:select.option>
            @endforeach
        </flux:select>

        <flux:input wire:model="imageModel" label="Generate-Modell" placeholder="Leer = Base-Default" />

        <flux:select wire:model.live="imageEditProvider" label="Edit-Provider" variant="listbox" placeholder="Base-Default">
            <flux:select.option value="">Base-Default</flux:select.option>
            @foreach (AiProvider::cases() as $providerOption)
                <flux:select.option value="{{ $providerOption->value }}" wire:key="bewerbungen-ki-image-edit-{{ $providerOption->value }}">
                    {{ $providerOption->label() }}
                </flux:select.option>
            @endforeach
        </flux:select>

        <flux:input wire:model="imageEditModel" label="Edit-Modell" placeholder="Leer = Base-Default" />

        <div class="flex justify-end">
            <flux:button type="submit" variant="primary">Speichern</flux:button>
        </div>
    </form>
</flux:card>
