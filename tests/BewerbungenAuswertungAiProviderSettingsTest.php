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
