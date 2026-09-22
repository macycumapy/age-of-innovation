<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Enums\TownTile;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ChooseTownAction
{
    public function __construct(
        private AppendGameHistoryAction $appendGameHistory,
        private ApplyChooseTownAction $applyChooseTown,
    ) {
    }

    public function execute(Game $game, User $user, TownTile $townTile): Game
    {
        return DB::transaction(function () use ($game, $user, $townTile): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $interaction = $state->pendingInteraction;
            $player = $lockedGame->players()->whereKey($interaction?->playerId)->whereBelongsTo($user)->first();

            if ($lockedGame->phase !== GamePhase::Actions
                || $lockedGame->active_player_id !== $user->id
                || $interaction?->type !== PendingInteractionType::ChooseTown
                || ! $player instanceof GamePlayer) {
                throw ValidationException::withMessages(['town_tile' => 'Этот жетон города недоступен.']);
            }

            $playerState = collect($state->players)->firstWhere('playerId', $player->id);

            if (! $playerState instanceof GamePlayerStateData) {
                throw ValidationException::withMessages(['town_tile' => 'Не найдено состояние игрока.']);
            }

            $stateVersionBefore = $lockedGame->version;

            if ($state->turnStartSnapshot === null) {
                $state->turnStartSnapshot = $state->toArray();
                $state->round->turnStartVersion = $stateVersionBefore;
            }

            $result = $this->applyChooseTown->execute($state, $playerState, $townTile);

            $lockedGame->update([
                'active_player_id' => $result->nextActiveUserId,
                'state' => $state,
                'version' => $lockedGame->version + 1,
            ]);
            $this->appendGameHistory->execute(
                $lockedGame,
                $user,
                GameActionType::ChooseTown,
                [
                    'town_tile' => $townTile->value,
                    'town_id' => $result->townId,
                    'town_hex_ids' => $result->townHexIds,
                    'marker_hex_id' => $result->markerHexId,
                    'queued_built_hex_ids' => $interaction->context['queuedBuiltHexIds'] ?? [],
                    'victory_points' => $result->victoryPoints,
                    'gained_power' => $result->gainedPower,
                ],
                [['type' => 'town_founded', 'player_id' => $player->id, 'town_tile' => $townTile->value]],
                $stateVersionBefore,
                $lockedGame->version,
            );

            return $lockedGame->refresh();
        });
    }
}
