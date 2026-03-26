<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::middleware(['web','auth','can:see-app-bewerbungen'])->group(function () {        
    Volt::route('apps/bewerbungen', 'apps.bewerbungen.index')->name('apps.bewerbungen.index');
    Volt::route('apps/bewerbungen/example', 'apps.bewerbungen.example')->name('apps.bewerbungen.example');
    Volt::route('apps/bewerbungen/settings/user', 'apps.bewerbungen.settings.user')->name('apps.bewerbungen.settings.user');
});

Route::middleware(['web','auth','can:manage-app-bewerbungen'])->group(function () {
    Volt::route('apps/bewerbungen/admin', 'apps.bewerbungen.admin.index')->name('apps.bewerbungen.admin.index');
});
