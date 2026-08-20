<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

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
        // personal_access_tokens is a central-only table (see
        // App\Models\PersonalAccessToken for the full reasoning) —
        // point Sanctum at the connection-pinned model.
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
    }
}
