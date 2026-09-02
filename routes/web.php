<?php

declare(strict_types=1);

use App\Http\Controllers\BookActionController;
use App\Http\Controllers\BridgeConfirmationController;
use App\Http\Controllers\BridgeController;
use App\Http\Controllers\BuildingUpgradeController;
use App\Http\Controllers\CurrentTurnFinishController;
use App\Http\Controllers\CurrentTurnRestartController;
use App\Http\Controllers\FactionActionController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\GameHistoryController;
use App\Http\Controllers\GameHistoryUndoController;
use App\Http\Controllers\GamePlayerController;
use App\Http\Controllers\GamePlayerReadinessController;
use App\Http\Controllers\GameStartController;
use App\Http\Controllers\PaidTerraformingController;
use App\Http\Controllers\PalaceActionController;
use App\Http\Controllers\PalaceChoiceController;
use App\Http\Controllers\PalaceGuildConfirmationController;
use App\Http\Controllers\PalaceGuildController;
use App\Http\Controllers\PassController;
use App\Http\Controllers\PlanningBundleController;
use App\Http\Controllers\PowerActionController;
use App\Http\Controllers\PowerOfferController;
use App\Http\Controllers\PowerSacrificeController;
use App\Http\Controllers\ResourceExchangeController;
use App\Http\Controllers\RoundBonusActionController;
use App\Http\Controllers\ScholarController;
use App\Http\Controllers\ScienceBonusBooksController;
use App\Http\Controllers\StartingBuildingController;
use App\Http\Controllers\StartingBuildingTurnController;
use App\Http\Controllers\StartingCompetencyController;
use App\Http\Controllers\StartingResourcesController;
use App\Http\Controllers\StartingSpadeController;
use App\Http\Controllers\StartingSpadeTurnController;
use App\Http\Controllers\TerraformWorkshopController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/games')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('games', GameController::class)->only(['index', 'store', 'show']);
    Route::get('games/{game}/history', GameHistoryController::class)->name('games.history');
    Route::delete('games/{game}/history/latest', GameHistoryUndoController::class)
        ->name('games.history.latest.destroy');
    Route::post('games/{game}/players', [GamePlayerController::class, 'store'])->name('games.players.store');
    Route::patch('games/{game}/players/{gamePlayer}/readiness', [GamePlayerReadinessController::class, 'update'])
        ->name('games.players.readiness.update');
    Route::post('games/{game}/start', GameStartController::class)->name('games.start');
    Route::post('games/{game}/planning-bundle', [PlanningBundleController::class, 'store'])
        ->name('games.planning-bundle.store');
    Route::post('games/{game}/starting-resources', [StartingResourcesController::class, 'store'])
        ->name('games.starting-resources.store');
    Route::post('games/{game}/starting-building', [StartingBuildingController::class, 'store'])
        ->name('games.starting-building.store');
    Route::delete('games/{game}/starting-building', [StartingBuildingController::class, 'destroy'])
        ->name('games.starting-building.destroy');
    Route::post('games/{game}/starting-building/finish', StartingBuildingTurnController::class)
        ->name('games.starting-building.finish');
    Route::post('games/{game}/starting-competency', [StartingCompetencyController::class, 'store'])
        ->name('games.starting-competency.store');
    Route::post('games/{game}/starting-spade', [StartingSpadeController::class, 'store'])
        ->name('games.starting-spade.store');
    Route::delete('games/{game}/starting-spade', [StartingSpadeController::class, 'destroy'])
        ->name('games.starting-spade.destroy');
    Route::post('games/{game}/starting-spade/finish', StartingSpadeTurnController::class)
        ->name('games.starting-spade.finish');
    Route::post('games/{game}/terraform-workshop', TerraformWorkshopController::class)
        ->name('games.terraform-workshop');
    Route::post('games/{game}/paid-terraforming', PaidTerraformingController::class)
        ->name('games.paid-terraforming');
    Route::post('games/{game}/power-sacrifice', [PowerSacrificeController::class, 'store'])
        ->name('games.power-sacrifice.store');
    Route::post('games/{game}/power-action', PowerActionController::class)
        ->name('games.power-action');
    Route::post('games/{game}/book-action', BookActionController::class)
        ->name('games.book-action');
    Route::post('games/{game}/round-bonus-action', RoundBonusActionController::class)
        ->name('games.round-bonus-action');
    Route::post('games/{game}/faction-action', FactionActionController::class)
        ->name('games.faction-action');
    Route::post('games/{game}/palace-action', PalaceActionController::class)
        ->name('games.palace-action');
    Route::post('games/{game}/pass', PassController::class)
        ->name('games.pass');
    Route::post('games/{game}/science-bonus/books', ScienceBonusBooksController::class)
        ->name('games.science-bonus.books');
    Route::post('games/{game}/bridge', [BridgeController::class, 'store'])
        ->name('games.bridge.store');
    Route::delete('games/{game}/bridge', [BridgeController::class, 'destroy'])
        ->name('games.bridge.destroy');
    Route::post('games/{game}/bridge/confirm', BridgeConfirmationController::class)
        ->name('games.bridge.confirm');
    Route::post('games/{game}/power-offer', PowerOfferController::class)
        ->name('games.power-offer');
    Route::post('games/{game}/building-upgrade', BuildingUpgradeController::class)
        ->name('games.building-upgrade');
    Route::post('games/{game}/palace-choice', PalaceChoiceController::class)
        ->name('games.palace-choice');
    Route::post('games/{game}/palace-guild', [PalaceGuildController::class, 'store'])
        ->name('games.palace-guild.store');
    Route::delete('games/{game}/palace-guild', [PalaceGuildController::class, 'destroy'])
        ->name('games.palace-guild.destroy');
    Route::post('games/{game}/palace-guild/confirm', PalaceGuildConfirmationController::class)
        ->name('games.palace-guild.confirm');
    Route::post('games/{game}/current-turn/restart', CurrentTurnRestartController::class)
        ->name('games.current-turn.restart');
    Route::post('games/{game}/current-turn/finish', CurrentTurnFinishController::class)
        ->name('games.current-turn.finish');
    Route::post('games/{game}/resource-exchange', ResourceExchangeController::class)
        ->name('games.resource-exchange');
    Route::post('games/{game}/scholar', ScholarController::class)
        ->name('games.scholar');
});

require __DIR__.'/settings.php';
