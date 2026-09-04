<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\GameStatus;
use App\Domain\Game\Enums\RoundBonus;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PassAction
{
    public function __construct(
        private ApplyPassAction $applyPass,
        private AppendGameHistoryAction $appendGameHistory,
    ) {
    }

    public function execute(Game $game, User $user, RoundBonus $roundBonus): Game
    {
        return DB::transaction(function () use ($game, $user, $roundBonus): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $player = $lockedGame->players()->whereBelongsTo($user)->first();

            if ($lockedGame->phase !== GamePhase::Actions
                || $lockedGame->active_player_id !== $user->id
                || $state->pendingInteraction !== null
                || $state->round->hasTakenMainAction
                || ! $player instanceof GamePlayer) {
                throw ValidationException::withMessages(['game' => 'Сейчас нельзя спасовать.']);
            }

            $playerState = collect($state->players)->firstWhere('playerId', $player->id);

            if (! $playerState instanceof GamePlayerStateData) {
                throw ValidationException::withMessages(['game' => 'Не найдено состояние игрока.']);
            }

            $stateVersionBefore = $lockedGame->version;
            $phaseBefore = $lockedGame->phase;
            $oldRoundBonus = $playerState->roundBonus;
            $roundNumber = $state->round->number;
            $result = $this->applyPass->execute($state, $playerState, $roundBonus, $lockedGame->players);
            $lockedGame->phase = $result['phase'];
            $lockedGame->active_player_id = $result['nextActiveUserId'];
            $lockedGame->status = $result['phase'] === GamePhase::Finished ? GameStatus::Finished : GameStatus::Active;
            $lockedGame->state = $state;
            $lockedGame->version++;
            $lockedGame->save();
            $this->appendGameHistory->execute($lockedGame, $user, GameActionType::Pass, [
                'old_round_bonus' => $oldRoundBonus->value,
                'round_bonus' => $roundBonus->value,
                'pass_order' => $result['passOrder'],
                'bonus_coins' => $result['bonusCoins'],
                'victory_points' => $result['victoryPoints'],
                'scoring_sources' => $result['scoringSources'],
                'next_round_started' => $result['nextRoundStarted'],
                'science_bonus_started' => $result['passOrder'] === count($state->turnOrder),
                'round' => $roundNumber,
                'income_receipts' => $result['incomeReceipts'],
            ], [[
                'type' => 'player_passed',
                'player_id' => $player->id,
                'pass_order' => $result['passOrder'],
            ]], $stateVersionBefore, $lockedGame->version, $result['phase'] !== $phaseBefore);

            return $lockedGame->refresh();
        });
    }
}
