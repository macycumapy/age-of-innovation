<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GamePhase;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ExchangeResourcesAction
{
    public function __construct(
        private AppendGameHistoryAction $appendGameHistory,
        private ApplyResourceExchangeAction $applyResourceExchange,
    ) {
    }

    /** @param array<string, int|array<string, int>> $exchanges */
    public function execute(
        Game $game,
        User $user,
        array $exchanges,
    ): Game {
        return DB::transaction(function () use ($game, $user, $exchanges): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $player = $lockedGame->players()->whereBelongsTo($user)->first();

            if ($lockedGame->phase !== GamePhase::Actions
                || $lockedGame->active_player_id !== $user->id
                || ! $player instanceof GamePlayer) {
                throw ValidationException::withMessages(['game' => 'Сейчас нельзя обменивать ресурсы.']);
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

            $this->applyResourceExchange->execute($playerState, $exchanges);

            $lockedGame->update(['state' => $state, 'version' => $lockedGame->version + 1]);
            $this->appendGameHistory->execute(
                $lockedGame,
                $user,
                GameActionType::ExchangeResources,
                ['exchanges' => $exchanges],
                [['type' => 'resources_exchanged', 'player_id' => $player->id, 'exchanges' => $exchanges]],
                $stateVersionBefore,
                $lockedGame->version,
            );

            return $lockedGame->refresh();
        });
    }
}
