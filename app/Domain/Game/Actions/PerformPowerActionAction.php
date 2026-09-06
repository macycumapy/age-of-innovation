<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PowerAction;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PerformPowerActionAction
{
    public function __construct(
        private ApplyPowerActionAction $applyPowerAction,
        private AppendGameHistoryAction $appendGameHistory,
    ) {
    }

    public function execute(Game $game, User $user, PowerAction $action, int $sacrificeAmount): Game
    {
        return DB::transaction(function () use ($game, $user, $action, $sacrificeAmount): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $player = $lockedGame->players()->whereBelongsTo($user)->first();

            if ($lockedGame->phase !== GamePhase::Actions
                || $lockedGame->active_player_id !== $user->id
                || $state->pendingInteraction !== null
                || $state->round->hasTakenMainAction
                || ! $player instanceof GamePlayer) {
                throw ValidationException::withMessages(['game' => 'Сейчас нельзя выполнять действие Силы.']);
            }

            $playerState = collect($state->players)->firstWhere('playerId', $player->id);

            if (! $playerState instanceof GamePlayerStateData) {
                throw ValidationException::withMessages(['game' => 'Не найдено состояние игрока.']);
            }

            $stateVersionBefore = $lockedGame->version;

            if ($state->turnStartSnapshot === null) {
                $state->turnStartSnapshot = $state->toArray();
                $state->round->turnStartVersion = $stateVersionBefore;
            }

            $this->applyPowerAction->execute($state, $playerState, $action, $sacrificeAmount);
            if ($action === PowerAction::BuildBridge && $state->pendingInteraction !== null) {
                $state->pendingInteraction->context['source'] = 'power';
                $state->pendingInteraction->context['sacrificeAmount'] = $sacrificeAmount;
            }
            $lockedGame->update(['state' => $state, 'version' => $lockedGame->version + 1]);

            if ($action !== PowerAction::BuildBridge) {
                $this->appendGameHistory->execute(
                    $lockedGame,
                    $user,
                    GameActionType::PowerAction,
                    ['action' => $action->value, 'sacrifice_amount' => $sacrificeAmount],
                    [[
                        'type' => 'power_action_used',
                        'player_id' => $player->id,
                        'action' => $action->value,
                        'sacrifice_amount' => $sacrificeAmount,
                    ]],
                    $stateVersionBefore,
                    $lockedGame->version,
                );
            }

            return $lockedGame->refresh();
        });
    }
}
