<?php

namespace Modules\Properties\Providers;

use App\Providers\ModuleServiceProvider;
use Modules\Properties\Console\Commands\ImportDataCommand;
use Modules\Properties\Imports\ListingImportService;
use Modules\Properties\Imports\ListingProviderRegistry;

class PropertiesServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();

        $this->app->singleton(ListingProviderRegistry::class, fn (): ListingProviderRegistry => ListingProviderRegistry::make());
        $this->app->singleton(ListingImportService::class);
    }

    public function boot(): void
    {
        parent::boot();

        $this->commands([ImportDataCommand::class]);
    }
}
