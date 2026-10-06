<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\AdminSettingsController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AdminUserPasswordController;
use App\Http\Controllers\Game\AnnexPlacementController;
use App\Http\Controllers\Game\BookActionController;
use App\Http\Controllers\Game\BridgeConfirmationController;
use App\Http\Controllers\Game\BridgeController;
use App\Http\Controllers\Game\BridgeSkipController;
use App\Http\Controllers\Game\BuildingUpgradeController;
use App\Http\Controllers\Game\CompetencyActionController;
use App\Http\Controllers\Game\CurrentTurnFinishController;
use App\Http\Controllers\Game\CurrentTurnRestartController;
use App\Http\Controllers\Game\FactionActionController;
use App\Http\Controllers\Game\GameBotController;
use App\Http\Controllers\Game\GameController;
use App\Http\Controllers\Game\GameHistoryController;
use App\Http\Controllers\Game\GameHistoryRollbackController;
use App\Http\Controllers\Game\GameHistoryUndoController;
use App\Http\Controllers\Game\GamePlayerController;
use App\Http\Controllers\Game\GamePlayerReadinessController;
use App\Http\Controllers\Game\GamePlayerRemovalController;
use App\Http\Controllers\Game\GameStartController;
use App\Http\Controllers\Game\InnovationActionController;
use App\Http\Controllers\Game\InnovationController;
use App\Http\Controllers\Game\NeutralInnovationBuildingController;
use App\Http\Controllers\Game\PaidTerraformingController;
use App\Http\Controllers\Game\PalaceActionController;
use App\Http\Controllers\Game\PalaceChoiceController;
use App\Http\Controllers\Game\PalaceGuildConfirmationController;
use App\Http\Controllers\Game\PalaceGuildController;
use App\Http\Controllers\Game\PalaceRewardOrderController;
use App\Http\Controllers\Game\PalaceWaterTownController;
use App\Http\Controllers\Game\PassController;
use App\Http\Controllers\Game\PlanningBundleController;
use App\Http\Controllers\Game\PowerActionController;
use App\Http\Controllers\Game\PowerOfferController;
use App\Http\Controllers\Game\PowerSacrificeController;
use App\Http\Controllers\Game\ResourceExchangeController;
use App\Http\Controllers\Game\RewardDistributionController;
use App\Http\Controllers\Game\RoundBonusActionController;
use App\Http\Controllers\Game\RoundBonusChoiceController;
use App\Http\Controllers\Game\ScholarController;
use App\Http\Controllers\Game\ShippingAdvancementController;
use App\Http\Controllers\Game\StartingBuildingController;
use App\Http\Controllers\Game\StartingBuildingTurnController;
use App\Http\Controllers\Game\StartingSpadeController;
use App\Http\Controllers\Game\StartingSpadeTurnController;
use App\Http\Controllers\Game\TerraformingAdvancementController;
use App\Http\Controllers\Game\TerraformWorkshopController;
use App\Http\Controllers\Game\TownController;
use App\Http\Controllers\Game\WorkshopController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/games')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::prefix('admin')->name('admin.')->middleware('can:access-admin')->group(function () {
        Route::redirect('/', '/admin/settings')->name('index');
        Route::get('/settings', AdminController::class)->name('settings.edit');
        Route::get('/users', AdminUserController::class)->name('users.index');
        Route::put('/users/{user}/password', AdminUserPasswordController::class)->name('users.password.update');
        Route::put('/settings', AdminSettingsController::class)->name('settings.update');
    });

    Route::resource('games', GameController::class)->only(['index', 'store', 'show']);
    Route::get('games/{game}/history', GameHistoryController::class)->name('games.history');
    Route::delete('games/{game}/history/latest', GameHistoryUndoController::class)
        ->name('games.history.latest.destroy');
    Route::delete('games/{game}/history/{action}', GameHistoryRollbackController::class)
        ->scopeBindings()
        ->name('games.history.destroy');
    Route::post('games/{game}/players', [GamePlayerController::class, 'store'])->name('games.players.store');
    Route::post('games/{game}/bots', GameBotController::class)->name('games.bots.store');
    Route::delete('games/{game}/players/{gamePlayer}', GamePlayerRemovalController::class)
        ->name('games.players.destroy');
    Route::patch('games/{game}/players/{gamePlayer}/readiness', [GamePlayerReadinessController::class, 'update'])
        ->name('games.players.readiness.update');
    Route::post('games/{game}/start', GameStartController::class)->name('games.start');
    Route::post('games/{game}/planning-bundle', [PlanningBundleController::class, 'store'])
        ->name('games.planning-bundle.store');
    Route::post('games/{game}/starting-building', [StartingBuildingController::class, 'store'])
        ->name('games.starting-building.store');
    Route::delete('games/{game}/starting-building', [StartingBuildingController::class, 'destroy'])
        ->name('games.starting-building.destroy');
    Route::post('games/{game}/starting-building/finish', StartingBuildingTurnController::class)
        ->name('games.starting-building.finish');
    Route::post('games/{game}/starting-spade', [StartingSpadeController::class, 'store'])
        ->name('games.starting-spade.store');
    Route::delete('games/{game}/starting-spade', [StartingSpadeController::class, 'destroy'])
        ->name('games.starting-spade.destroy');
    Route::post('games/{game}/starting-spade/finish', StartingSpadeTurnController::class)
        ->name('games.starting-spade.finish');
    Route::post('games/{game}/terraform-workshop', TerraformWorkshopController::class)
        ->name('games.terraform-workshop');
    Route::post('games/{game}/workshop', WorkshopController::class)
        ->name('games.workshop');
    Route::post('games/{game}/town', TownController::class)
        ->name('games.town');
    Route::post('games/{game}/rewards', RewardDistributionController::class)
        ->name('games.rewards');
    Route::post('games/{game}/innovation-action', InnovationActionController::class)
        ->name('games.innovation-action');
    Route::post('games/{game}/town/palace-water', PalaceWaterTownController::class)
        ->name('games.town.palace-water');
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
    Route::post('games/{game}/shipping', ShippingAdvancementController::class)
        ->name('games.shipping');
    Route::post('games/{game}/terraforming', TerraformingAdvancementController::class)
        ->name('games.terraforming');
    Route::post('games/{game}/competency-action', CompetencyActionController::class)
        ->name('games.competency-action');
    Route::post('games/{game}/palace-action', PalaceActionController::class)
        ->name('games.palace-action');
    Route::post('games/{game}/palace-reward-order', PalaceRewardOrderController::class)
        ->name('games.palace-reward-order');
    Route::post('games/{game}/pass', PassController::class)
        ->name('games.pass');
    Route::post('games/{game}/round-bonus-choice', RoundBonusChoiceController::class)
        ->name('games.round-bonus-choice');
    Route::post('games/{game}/bridge', [BridgeController::class, 'store'])
        ->name('games.bridge.store');
    Route::delete('games/{game}/bridge', [BridgeController::class, 'destroy'])
        ->name('games.bridge.destroy');
    Route::post('games/{game}/bridge/confirm', BridgeConfirmationController::class)
        ->name('games.bridge.confirm');
    Route::post('games/{game}/bridge/skip', BridgeSkipController::class)
        ->name('games.bridge.skip');
    Route::post('games/{game}/annex/start', [AnnexPlacementController::class, 'create'])
        ->name('games.annex.start');
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
    Route::post('games/{game}/innovation', InnovationController::class)
        ->name('games.innovation');
    Route::post('games/{game}/innovation/neutral-building', NeutralInnovationBuildingController::class)
        ->name('games.innovation.neutral-building');
});

require __DIR__.'/settings.php';
