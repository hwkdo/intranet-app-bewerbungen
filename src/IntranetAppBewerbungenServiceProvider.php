<?php

namespace Hwkdo\IntranetAppBewerbungen;

use Hwkdo\IntranetAppBewerbungen\Commands\IntranetAppBewerbungenCommand;
use Hwkdo\IntranetAppBewerbungen\Console\Commands\BewerbungenAuswertenCommand;
use Hwkdo\IntranetAppBewerbungen\Console\Commands\BewerbungenAuswertenAiCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Livewire\Volt\Volt;

class IntranetAppBewerbungenServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('intranet-app-bewerbungen')
            ->hasConfigFile()
            ->hasViews()
            ->hasCommands([
                IntranetAppBewerbungenCommand::class,
                BewerbungenAuswertenAiCommand::class,
                BewerbungenAuswertenCommand::class,
            ])
            ->discoversMigrations();
    }

    public function boot(): void
    {
        parent::boot();
        // Gate::policy(Raum::class, RaumPolicy::class);
        $this->app->booted( function() {
            Volt::mount(__DIR__.'/../resources/views/livewire');                        
        });
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');

    }
}
