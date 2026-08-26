<?php

declare(strict_types=1);

use App\Http\Controllers\GameController;
use App\Http\Controllers\GamePlayerController;
use App\Http\Controllers\GamePlayerReadinessController;
use App\Http\Controllers\GameStartController;
use App\Http\Controllers\PlanningBundleController;
use App\Http\Controllers\StartingResourcesController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/games')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('games', GameController::class)->only(['index', 'store', 'show']);
    Route::post('games/{game}/players', [GamePlayerController::class, 'store'])->name('games.players.store');
    Route::patch('games/{game}/players/{gamePlayer}/readiness', [GamePlayerReadinessController::class, 'update'])
        ->name('games.players.readiness.update');
    Route::post('games/{game}/start', GameStartController::class)->name('games.start');
    Route::post('games/{game}/planning-bundle', [PlanningBundleController::class, 'store'])
        ->name('games.planning-bundle.store');
    Route::post('games/{game}/starting-resources', [StartingResourcesController::class, 'store'])
        ->name('games.starting-resources.store');
});

require __DIR__.'/settings.php';
