<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Board\Actions;

use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\History\Actions\AppendGameHistoryAction;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SkipBridgeAction
{
    public function __construct(
        private ApplySkipBridgeAction $applySkipBridge,
        private AppendGameHistoryAction $appendGameHistory,
    ) {
    }

    public function execute(Game $game, GamePlayer $player): Game
    {
        return DB::transaction(function () use ($game, $player): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $playerState = collect($state->players)->firstWhere('playerId', $player->id);

            if (! $lockedGame->isActivePlayer($player) || ! $playerState instanceof GamePlayerStateData) {
                throw ValidationException::withMessages(['bridge' => 'Сейчас нельзя отказаться от моста.']);
            }

            $stateVersionBefore = $lockedGame->version;
            $lockedGame->active_game_player_id = $this->applySkipBridge->execute($state, $playerState);
            $lockedGame->state = $state;
            $lockedGame->version++;
            $lockedGame->save();

            $this->appendGameHistory->execute(
                $lockedGame,
                $player,
                GameActionType::SpecialAction,
                ['palace_bridge_skipped' => 'palace_15'],
                [],
                $state->round->turnStartVersion ?? $stateVersionBefore,
                $lockedGame->version,
            );

            return $lockedGame->refresh();
        });
    }
}
