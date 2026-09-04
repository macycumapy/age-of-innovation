<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GamePhase;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ConfirmAnnexPlacementAction
{
    public function __construct(
        private FindEligibleAnnexHexesAction $findEligibleAnnexHexes,
        private CreateTownChoiceAfterBuildingAction $createTownChoiceAfterBuilding,
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
            $hex = collect($state->board->hexes)->firstWhere('id', $hexId);

            if ($lockedGame->phase !== GamePhase::Actions
                || $lockedGame->active_player_id !== $user->id
                || $state->pendingInteraction !== null
                || $state->round->hasTakenMainAction
                || ! $player instanceof GamePlayer
                || ! $playerState instanceof GamePlayerStateData
                || ! $hex instanceof BoardHexStateData
                || $playerState->availableAnnexes < 1
                || ! in_array($hexId, $this->findEligibleAnnexHexes->execute($state, $player->id), true)) {
                throw ValidationException::withMessages(['annex' => 'Сначала выберите доступное здание.']);
            }

            $stateVersionBefore = $lockedGame->version;

            if ($state->turnStartSnapshot === null) {
                $state->turnStartSnapshot = $state->toArray();
                $state->round->turnStartVersion = $stateVersionBefore;
            }

            $playerState->availableAnnexes--;
            $hex->building->hasAnnex = true;
            $state->round->hasTakenMainAction = true;
            $lockedGame->active_player_id = $this->createTownChoiceAfterBuilding->execute(
                $state,
                $playerState,
                $hexId,
                powerOffersResolved: true,
            );
            $lockedGame->state = $state;
            $lockedGame->version++;
            $lockedGame->save();

            $this->appendGameHistory->execute(
                $lockedGame,
                $user,
                GameActionType::PlaceAnnex,
                ['hex_id' => $hexId],
                [[
                    'type' => 'annex_placed',
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
