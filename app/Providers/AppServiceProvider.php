<?php

namespace App\Providers;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Laravel's default pagination views are Tailwind markup; on this
        // Bootstrap app their unstyled SVG arrows rendered huge.
        Paginator::useBootstrapFive();
    }
}
