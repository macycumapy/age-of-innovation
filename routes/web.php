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
    Route::prefix('games/{game}')->name('games.')->group(function () {
        Route::prefix('history')->group(function () {
            Route::get('', GameHistoryController::class)->name('history');
            Route::delete('latest', GameHistoryUndoController::class)->name('history.latest.destroy');
            Route::delete('{action}', GameHistoryRollbackController::class)->scopeBindings()->name('history.destroy');
        });

        Route::prefix('players')->name('players.')->group(function () {
            Route::post('', [GamePlayerController::class, 'store'])->name('store');
            Route::delete('{gamePlayer}', GamePlayerRemovalController::class)->name('destroy');
            Route::patch('{gamePlayer}/readiness', [GamePlayerReadinessController::class, 'update'])->name('readiness.update');
        });

        Route::prefix('starting-building')->name('starting-building.')->group(function () {
            Route::post('', [StartingBuildingController::class, 'store'])->name('store');
            Route::delete('', [StartingBuildingController::class, 'destroy'])->name('destroy');
            Route::post('finish', StartingBuildingTurnController::class)->name('finish');
        });

        Route::prefix('starting-spade')->name('starting-spade.')->group(function () {
            Route::post('', [StartingSpadeController::class, 'store'])->name('store');
            Route::delete('', [StartingSpadeController::class, 'destroy'])->name('destroy');
            Route::post('finish', StartingSpadeTurnController::class)->name('finish');
        });

        Route::prefix('town')->group(function () {
            Route::post('', TownController::class)->name('town');
            Route::post('palace-water', PalaceWaterTownController::class)->name('town.palace-water');
        });

        Route::prefix('bridge')->name('bridge.')->group(function () {
            Route::post('', [BridgeController::class, 'store'])->name('store');
            Route::delete('', [BridgeController::class, 'destroy'])->name('destroy');
            Route::post('confirm', BridgeConfirmationController::class)->name('confirm');
            Route::post('skip', BridgeSkipController::class)->name('skip');
        });

        Route::prefix('innovation')->group(function () {
            Route::post('', InnovationController::class)->name('innovation');
            Route::post('neutral-building', NeutralInnovationBuildingController::class)->name('innovation.neutral-building');
        });

        Route::prefix('palace-guild')->name('palace-guild.')->group(function () {
            Route::post('', [PalaceGuildController::class, 'store'])->name('store');
            Route::delete('', [PalaceGuildController::class, 'destroy'])->name('destroy');
            Route::post('confirm', PalaceGuildConfirmationController::class)->name('confirm');
        });

        Route::prefix('current-turn')->name('current-turn.')->group(function () {
            Route::post('restart', CurrentTurnRestartController::class)->name('restart');
            Route::post('finish', CurrentTurnFinishController::class)->name('finish');
        });

        Route::post('bots', GameBotController::class)->name('bots.store');
        Route::post('start', GameStartController::class)->name('start');
        Route::post('planning-bundle', [PlanningBundleController::class, 'store'])->name('planning-bundle.store');
        Route::post('terraform-workshop', TerraformWorkshopController::class)->name('terraform-workshop');
        Route::post('workshop', WorkshopController::class)->name('workshop');
        Route::post('rewards', RewardDistributionController::class)->name('rewards');
        Route::post('innovation-action', InnovationActionController::class)->name('innovation-action');
        Route::post('paid-terraforming', PaidTerraformingController::class)->name('paid-terraforming');
        Route::post('power-sacrifice', [PowerSacrificeController::class, 'store'])->name('power-sacrifice.store');
        Route::post('power-action', PowerActionController::class)->name('power-action');
        Route::post('book-action', BookActionController::class)->name('book-action');
        Route::post('round-bonus-action', RoundBonusActionController::class)->name('round-bonus-action');
        Route::post('faction-action', FactionActionController::class)->name('faction-action');
        Route::post('shipping', ShippingAdvancementController::class)->name('shipping');
        Route::post('terraforming', TerraformingAdvancementController::class)->name('terraforming');
        Route::post('competency-action', CompetencyActionController::class)->name('competency-action');
        Route::post('palace-action', PalaceActionController::class)->name('palace-action');
        Route::post('palace-reward-order', PalaceRewardOrderController::class)->name('palace-reward-order');
        Route::post('pass', PassController::class)->name('pass');
        Route::post('round-bonus-choice', RoundBonusChoiceController::class)->name('round-bonus-choice');
        Route::post('annex/start', [AnnexPlacementController::class, 'create'])->name('annex.start');
        Route::post('power-offer', PowerOfferController::class)->name('power-offer');
        Route::post('building-upgrade', BuildingUpgradeController::class)->name('building-upgrade');
        Route::post('palace-choice', PalaceChoiceController::class)->name('palace-choice');
        Route::post('resource-exchange', ResourceExchangeController::class)->name('resource-exchange');
        Route::post('scholar', ScholarController::class)->name('scholar');
    });
});

require __DIR__.'/settings.php';
