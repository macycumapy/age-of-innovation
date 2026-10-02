<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Board\Actions;

use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\Enums\GameEventType;
use App\Domain\GameEngine\History\Actions\AppendGameHistoryAction;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ConfirmAnnexPlacementAction
{
    public function __construct(
        private ApplyPlaceAnnexAction $applyPlaceAnnex,
        private AppendGameHistoryAction $appendGameHistory,
    ) {
    }

    public function execute(Game $game, GamePlayer $player, string $hexId): Game
    {
        return DB::transaction(function () use ($game, $player, $hexId): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $playerState = collect($state->players)->firstWhere('playerId', $player->id);
            if (! $lockedGame->phase->isActionPhase()
                || ! $lockedGame->isActivePlayer($player)
                || ! $playerState instanceof GamePlayerStateData) {
                throw ValidationException::withMessages(['annex' => 'Сначала выберите доступное здание.']);
            }

            $stateVersionBefore = $lockedGame->version;

            if ($state->turnStartSnapshot === null) {
                $state->turnStartSnapshot = $state->toArray();
                $state->round->turnStartVersion = $stateVersionBefore;
            }

            $result = $this->applyPlaceAnnex->execute($state, $playerState, $hexId);
            $lockedGame->active_game_player_id = $result->nextActivePlayerId;
            $lockedGame->state = $state;
            $lockedGame->version++;
            $lockedGame->save();

            $this->appendGameHistory->execute(
                $lockedGame,
                $player,
                GameActionType::PlaceAnnex,
                ['hex_id' => $hexId],
                [[
                    'type' => GameEventType::AnnexPlaced->value,
                    'player_id' => $player->id,
                    'hex_id' => $hexId,
                ]],
                $stateVersionBefore,
                $lockedGame->version,
            );

            return $lockedGame->refresh();
        });
    }
}
