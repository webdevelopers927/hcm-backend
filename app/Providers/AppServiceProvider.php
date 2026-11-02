<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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
        // Surface lazy loading (N+1), missing selected columns and silently dropped attributes outside production.
        Model::shouldBeStrict(! $this->app->isProduction());

        Route::patterns(array_fill_keys(
            ['employee', 'document', 'workExperience', 'country', 'state', 'division', 'degree_program', 'assignment', 'employment_status'],
            '[0-9]+',
        ));

        RateLimiter::for('login', function (Request $request) {
            $email = Str::lower((string) $request->input('email'));

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });

        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(60)->by((string) $request->user()?->getAuthIdentifier()));
    }
}
