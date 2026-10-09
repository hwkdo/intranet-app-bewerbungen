<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::middleware(['web', 'auth', 'can:see-app-bewerbungen'])->group(function () {
    Volt::route('apps/bewerbungen', 'apps.bewerbungen.index')->name('apps.bewerbungen.index');

    Volt::route('apps/bewerbungen/example', 'apps.bewerbungen.example')->name('apps.bewerbungen.example');
    Volt::route('apps/bewerbungen/info', 'apps.bewerbungen.info')->name('apps.bewerbungen.info');
});

Route::middleware(['web', 'auth', 'can:manage-app-bewerbungen'])->group(function () {
    Volt::route('apps/bewerbungen/admin', 'apps.bewerbungen.admin.index')->name('apps.bewerbungen.admin.index');
    Route::livewire('apps/bewerbungen/pipeline', 'intranet-app-bewerbungen::apps.bewerbungen.pipeline')
        ->name('apps.bewerbungen.pipeline');
});

Route::middleware(['web', 'auth', 'can:manage-app-bewerbungen-definitionen'])->group(function () {
    Route::livewire('apps/bewerbungen/definitionen', 'intranet-app-bewerbungen::apps.bewerbungen.definitionen.index')
        ->name('apps.bewerbungen.definitionen.index');
    Route::livewire('apps/bewerbungen/definitionen/neu', 'intranet-app-bewerbungen::apps.bewerbungen.definitionen.editor')
        ->name('apps.bewerbungen.definitionen.create');
    Route::livewire('apps/bewerbungen/definitionen/{definition}', 'intranet-app-bewerbungen::apps.bewerbungen.definitionen.editor')
        ->name('apps.bewerbungen.definitionen.edit');
});
