<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Research\Actions;

use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\Enums\GameEventType;
use App\Domain\GameEngine\History\Actions\AppendGameHistoryAction;
use App\Domain\GameEngine\Research\Enums\Innovation;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MakeInnovationAction
{
    public function __construct(
        private ApplyMakeInnovationAction $applyMakeInnovation,
        private AppendGameHistoryAction $appendGameHistory,
    ) {
    }

    /** @param array<string, int> $bookCounts */
    public function execute(Game $game, GamePlayer $player, Innovation $innovation, array $bookCounts): Game
    {
        return DB::transaction(function () use ($game, $player, $innovation, $bookCounts): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $playerState = collect($state->players)->firstWhere('playerId', $player->id);

            if (! $lockedGame->phase->isActionPhase()
                || ! $lockedGame->isActivePlayer($player)
                || $state->pendingInteraction !== null
                || $state->round->hasTakenMainAction
                || ! $playerState instanceof GamePlayerStateData) {
                throw ValidationException::withMessages(['innovation' => 'Сейчас нельзя создать инновацию.']);
            }

            $stateVersionBefore = $lockedGame->version;

            if ($state->turnStartSnapshot === null) {
                $state->turnStartSnapshot = $state->toArray();
                $state->round->turnStartVersion = $stateVersionBefore;
            }

            $result = $this->applyMakeInnovation->execute($state, $playerState, $innovation, $bookCounts);
            $lockedGame->update([
                'state' => $state,
                'version' => $lockedGame->version + 1,
            ]);
            $this->appendGameHistory->execute(
                $lockedGame,
                $player,
                GameActionType::MakeInnovation,
                [
                    'innovation' => $innovation->value,
                    'book_counts' => $bookCounts,
                    'coins' => $result->coins,
                    'victory_points' => $result->victoryPoints,
                    'reward' => $result->reward->toArray(),
                    'gained_power' => $result->reward->gainedPower,
                ],
                [[
                    'type' => GameEventType::InnovationCreated->value,
                    'player_id' => $player->id,
                    'innovation' => $innovation->value,
                ]],
                $stateVersionBefore,
                $lockedGame->version,
            );

            return $lockedGame->refresh();
        });
    }
}
