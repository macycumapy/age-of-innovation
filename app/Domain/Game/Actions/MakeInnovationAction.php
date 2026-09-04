<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\Innovation;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
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
    public function execute(Game $game, User $user, Innovation $innovation, array $bookCounts): Game
    {
        return DB::transaction(function () use ($game, $user, $innovation, $bookCounts): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $player = $lockedGame->players()->whereBelongsTo($user)->first();
            $playerState = $player instanceof GamePlayer
                ? collect($state->players)->firstWhere('playerId', $player->id)
                : null;

            if ($lockedGame->phase !== GamePhase::Actions
                || $lockedGame->active_player_id !== $user->id
                || $state->pendingInteraction !== null
                || $state->round->hasTakenMainAction
                || ! $player instanceof GamePlayer
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
                $user,
                GameActionType::MakeInnovation,
                [
                    'innovation' => $innovation->value,
                    'book_counts' => $bookCounts,
                    'coins' => $result['coins'],
                    'victory_points' => $result['victoryPoints'],
                    'reward' => $result['reward'],
                ],
                [[
                    'type' => 'innovation_created',
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
