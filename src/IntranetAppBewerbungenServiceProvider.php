<?php

namespace Hwkdo\IntranetAppBewerbungen;

use Hwkdo\IntranetAppBewerbungen\Commands\IntranetAppBewerbungenCommand;
use Hwkdo\IntranetAppBewerbungen\Console\Commands\BewerbungenAuswertenAiCommand;
use Hwkdo\IntranetAppBewerbungen\Console\Commands\BewerbungenAuswertenCommand;
use Hwkdo\IntranetAppBewerbungen\Console\Commands\BewerbungenLightRagIndexCommand;
use Livewire\Volt\Volt;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

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
                BewerbungenLightRagIndexCommand::class,
            ])
            ->discoversMigrations();
    }

    public function boot(): void
    {
        parent::boot();

        $instances = config('lightrag.instances', []);
        $apiKey = config('intranet-app-bewerbungen.lightrag.api_key');
        foreach (config('intranet-app-bewerbungen.lightrag.instances', []) as $key => $instance) {
            if (is_string($key) && is_array($instance)) {
                if (is_string($apiKey) && $apiKey !== '') {
                    $instance['api_key'] = $apiKey;
                }
                $instances[$key] = $instance;
            }
        }
        config(['lightrag.instances' => $instances]);
        // Gate::policy(Raum::class, RaumPolicy::class);
        $this->app->booted(function () {
            Volt::mount(__DIR__.'/../resources/views/livewire');
        });
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');

    }
}
