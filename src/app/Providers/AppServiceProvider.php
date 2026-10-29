<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
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
        if ($this->app->isProduction()) {
            $driver = (string) config('hashing.driver');
            $memory = (int) config('hashing.argon.memory', 0);
            $time = (int) config('hashing.argon.time', 0);

            if ($driver !== 'argon2id' || $memory < 65536 || $time < 4) {
                throw new \RuntimeException(
                    "Konfigurasi hashing di lingkungan production tidak aman: driver={$driver}, memory={$memory}, time={$time}. Wajib argon2id dengan memory >= 65536 dan time >= 4."
                );
            }
        }

        Model::shouldBeStrict(! $this->app->isProduction());
        DB::prohibitDestructiveCommands($this->app->isProduction());
        URL::forceHttps($this->app->isProduction());
        Date::use(CarbonImmutable::class);
        Vite::useCspNonce();

        Gate::before(function ($user, string $ability): ?true {
            if ($user instanceof User && $user->hasPermission($ability)) {
                return true;
            }

            return null;
        });
    }
}
