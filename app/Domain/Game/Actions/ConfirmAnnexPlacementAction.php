<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GameEventType;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ConfirmAnnexPlacementAction
{
    public function __construct(
        private ApplyPlaceAnnexAction $applyPlaceAnnex,
        private AppendGameHistoryAction $appendGameHistory,
    ) {
    }

    public function execute(Game $game, User $user, string $hexId): Game
    {
        return DB::transaction(function () use ($game, $user, $hexId): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $player = $lockedGame->players()->whereBelongsTo($user)->first();
            $playerState = $player instanceof GamePlayer
                ? collect($state->players)->firstWhere('playerId', $player->id)
                : null;
            if (! $lockedGame->phase->isActionPhase()
                || $lockedGame->active_player_id !== $user->id
                || ! $player instanceof GamePlayer
                || ! $playerState instanceof GamePlayerStateData) {
                throw ValidationException::withMessages(['annex' => 'Сначала выберите доступное здание.']);
            }

            $stateVersionBefore = $lockedGame->version;

            if ($state->turnStartSnapshot === null) {
                $state->turnStartSnapshot = $state->toArray();
                $state->round->turnStartVersion = $stateVersionBefore;
            }

            $result = $this->applyPlaceAnnex->execute($state, $playerState, $hexId);
            $lockedGame->active_player_id = $result->nextActiveUserId;
            $lockedGame->state = $state;
            $lockedGame->version++;
            $lockedGame->save();

            $this->appendGameHistory->execute(
                $lockedGame,
                $user,
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
