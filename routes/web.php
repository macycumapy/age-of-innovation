<?php

declare(strict_types=1);

use App\Domain\Game\Factories\BoardStateFactory;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', function (BoardStateFactory $boardStateFactory): Response {
        return Inertia::render('Dashboard', [
            'board' => $boardStateFactory->create(),
        ]);
    })->name('dashboard');
});

require __DIR__.'/settings.php';
