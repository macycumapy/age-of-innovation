<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GameEventType;
use App\Models\Game;
use App\Models\GamePlayer;
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
        GamePlayer $player,
        array $exchanges,
    ): Game {
        return DB::transaction(function () use ($game, $player, $exchanges): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;

            if (! $lockedGame->phase->isActionPhase()
                || ! $lockedGame->isActivePlayer($player)
            ) {
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
                $player,
                GameActionType::ExchangeResources,
                ['exchanges' => $exchanges],
                [['type' => GameEventType::ResourcesExchanged->value, 'player_id' => $player->id, 'exchanges' => $exchanges]],
                $stateVersionBefore,
                $lockedGame->version,
            );

            return $lockedGame->refresh();
        });
    }
}
