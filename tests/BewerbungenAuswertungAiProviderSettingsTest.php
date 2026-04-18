<?php

declare(strict_types=1);

use Hwkdo\IntranetAppBewerbungen\Data\AppSettings;
use Hwkdo\IntranetAppBewerbungen\Enums\BewerbungenAuswertungAiProvider;
use Hwkdo\IntranetAppBewerbungen\Models\IntranetAppBewerbungenSettings;
use Illuminate\Support\Facades\Schema;

it('defaults app settings to openwebui provider', function () {
    $settings = new AppSettings;

    expect($settings->bewerbungenAuswertungAiProvider)->toBe(BewerbungenAuswertungAiProvider::OpenWebUi);
});

it('defaults auswertung model fields to empty strings', function () {
    $settings = new AppSettings;

    expect($settings->bewerbungenAuswertungModelOpenWebUi)->toBe('')
        ->and($settings->bewerbungenAuswertungModelLangdock)->toBe('');
});

it('hydrates auswertung model fields from stored values', function () {
    $settings = AppSettings::from([
        'bewerbungenAuswertungModelOpenWebUi' => 'gpt-oss:20b',
        'bewerbungenAuswertungModelLangdock' => 'gpt-4o-mini',
    ]);

    expect($settings->bewerbungenAuswertungModelOpenWebUi)->toBe('gpt-oss:20b')
        ->and($settings->bewerbungenAuswertungModelLangdock)->toBe('gpt-4o-mini');
});

it('maps ai provider enum values to laravel ai provider keys', function () {
    expect(BewerbungenAuswertungAiProvider::OpenWebUi->value)->toBe('openwebui')
        ->and(BewerbungenAuswertungAiProvider::Langdock->value)->toBe('langdock');
});

it('exposes admin options for BewerbungenAuswertungAiProvider', function () {
    $options = BewerbungenAuswertungAiProvider::options();

    expect($options['openwebui'])->toBeString()
        ->and($options['langdock'])->toBeString();
});

it('hydrates bewerbungenAuswertungAiProvider from stored value', function () {
    $settings = AppSettings::from(['bewerbungenAuswertungAiProvider' => 'langdock']);

    expect($settings->bewerbungenAuswertungAiProvider)->toBe(BewerbungenAuswertungAiProvider::Langdock);
});

it('resolvedAppSettings returns defaults when settings table is missing', function () {
    Schema::dropIfExists('intranet_app_bewerbungen_settings');

    expect(Schema::hasTable('intranet_app_bewerbungen_settings'))->toBeFalse();

    $resolved = IntranetAppBewerbungenSettings::resolvedAppSettings();

    expect($resolved)->toBeInstanceOf(AppSettings::class)
        ->and($resolved->bewerbungenAuswertungAiProvider)->toBe(BewerbungenAuswertungAiProvider::OpenWebUi);
});
