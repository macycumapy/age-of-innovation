<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\GameSession\Services\GameChangeSignal;
use App\Domain\GameSession\Services\RedisGameChangeSignal;
use App\Events\GameChanged;
use App\Events\GamePlayersChanged;
use App\Models\Game;
use App\Models\User;
use App\Observers\GameObserver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(GameChangeSignal::class, RedisGameChangeSignal::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Gate::define(
            'access-admin',
            fn (User $user): bool =>
            (string) $user->id === (string) config('auth.admin_user_id')
        );

        Game::observe(GameObserver::class);

        Event::listen([GameChanged::class, GamePlayersChanged::class], function (GameChanged|GamePlayersChanged $event): void {
            DB::afterCommit(fn () => $this->app->make(GameChangeSignal::class)->publish($event->gameId));
        });

        Model::shouldBeStrict(! $this->app->isProduction());
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): Password => Password::min(8));
    }
}
