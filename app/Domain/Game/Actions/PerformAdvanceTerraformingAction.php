<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Enums\PlayerColor;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PerformAdvanceTerraformingAction
{
    public function __construct(
        private AdvanceDevelopmentTrackAction $advanceDevelopmentTrack,
        private ApplyDevelopmentTrackRoundScoringAction $applyDevelopmentTrackRoundScoring,
        private AppendGameHistoryAction $appendGameHistory,
    ) {
    }

    public function execute(Game $game, User $user): Game
    {
        return DB::transaction(function () use ($game, $user): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $player = $lockedGame->players()->whereBelongsTo($user)->first();
            $playerState = $player instanceof GamePlayer
                ? collect($state->players)->firstWhere('playerId', $player->id)
                : null;
            $coinCost = $playerState?->color === PlayerColor::Brown ? 1 : 5;

            if ($lockedGame->phase !== GamePhase::Actions
                || $lockedGame->active_player_id !== $user->id
                || $state->pendingInteraction !== null
                || $state->round->hasTakenMainAction
                || ! $playerState instanceof GamePlayerStateData
                || $playerState->terraformingLevel >= 2
                || $playerState->resources->tools < 1
                || $playerState->resources->coins < $coinCost
                || $playerState->resources->scholars < 1) {
                throw ValidationException::withMessages(['terraforming' => 'Сейчас нельзя повысить уровень преобразования.']);
            }

            $stateVersionBefore = $lockedGame->version;

            if ($state->turnStartSnapshot === null) {
                $state->turnStartSnapshot = $state->toArray();
                $state->round->turnStartVersion = $stateVersionBefore;
            }

            $playerState->resources->tools--;
            $playerState->resources->coins -= $coinCost;
            $playerState->resources->scholars--;
            $reward = $this->advanceDevelopmentTrack->advanceTerraforming($playerState);
            $reward['victoryPoints'] += $this->applyDevelopmentTrackRoundScoring->execute(
                $state,
                $playerState,
                $reward['steps'],
            );
            $state->round->hasTakenMainAction = true;

            if ($reward['books'] > 0) {
                $state->pendingInteraction = new PendingInteractionData(
                    PendingInteractionType::ChooseInnovationBooks,
                    $player->id,
                    context: ['bookCount' => $reward['books'], 'source' => 'terraforming'],
                );
            }

            $lockedGame->update(['state' => $state, 'version' => $lockedGame->version + 1]);
            $this->appendGameHistory->execute(
                $lockedGame,
                $user,
                GameActionType::AdvanceTerraforming,
                ['tools' => 1, 'coins' => $coinCost, 'scholars' => 1, 'reward' => $reward],
                [[
                    'type' => 'terraforming_advanced',
                    'player_id' => $player->id,
                    'level' => $playerState->terraformingLevel,
                ]],
                $stateVersionBefore,
                $lockedGame->version,
            );

            return $lockedGame->refresh();
        });
    }
}
