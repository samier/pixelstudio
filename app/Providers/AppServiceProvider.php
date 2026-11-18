<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PixelforgeClient::class, fn () => new PixelforgeClient(
            baseUrl: config('services.pixelforge.base_url'),
            apiKey: config('services.pixelforge.api_key'),
        ));
    }

    public function boot(): void
    {
        Gate::define('view-admin-report', fn (User $user) => $user->isAdmin());
    }
}
