<?php

declare(strict_types=1);

use App\Domain\Game\Factories\BoardStateFactory;
use App\Http\Controllers\GameController;
use App\Http\Controllers\GamePlayerController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('games', GameController::class)->only(['index', 'store', 'show']);
    Route::post('games/{game}/players', [GamePlayerController::class, 'store'])->name('games.players.store');

    Route::get('dashboard', function (BoardStateFactory $boardStateFactory): Response {
        return Inertia::render('Dashboard', [
            'board' => $boardStateFactory->create(),
        ]);
    })->name('dashboard');
});

require __DIR__.'/settings.php';
