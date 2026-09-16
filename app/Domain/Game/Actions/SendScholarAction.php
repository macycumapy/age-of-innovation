<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\Competency;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Enums\RoundBonus;
use App\Domain\Game\Enums\RoundScoringGoal;
use App\Domain\Game\Enums\RoundScoringTile;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SendScholarAction
{
    public function __construct(
        private AdvanceKnowledgeAction $advanceKnowledge,
        private AssignScholarSlotsAction $assignScholarSlots,
        private AppendGameHistoryAction $appendGameHistory,
    ) {
    }

    public function execute(Game $game, User $user, KnowledgeDiscipline $discipline, bool $place): Game
    {
        return DB::transaction(function () use ($game, $user, $discipline, $place): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $player = $lockedGame->players()->whereBelongsTo($user)->first();
            $playerState = $player instanceof GamePlayer
                ? collect($state->players)->firstWhere('playerId', $player->id)
                : null;
            $placedScholarCount = collect($state->players)->sum(
                static fn (GamePlayerStateData $candidate): int => count(array_filter(
                    $candidate->scholarDisciplineIds,
                    static fn (string $disciplineId): bool => $disciplineId === $discipline->value,
                )),
            );
            $scholarSlotIndex = $place
                ? $this->assignScholarSlots->nextAvailable($state, $discipline)
                : null;

            if ($lockedGame->phase !== GamePhase::Actions
                || $lockedGame->active_player_id !== $user->id
                || $state->pendingInteraction !== null
                || $state->round->hasTakenMainAction
                || ! $player instanceof GamePlayer
                || ! $playerState instanceof GamePlayerStateData
                || $playerState->resources->scholars < 1
                || ($place && $playerState->scholarPoolSize < 1)
                || ($place && $scholarSlotIndex === null)) {
                throw ValidationException::withMessages(['scholar' => 'Сейчас нельзя отправить учёного в эту дисциплину.']);
            }

            $stateVersionBefore = $lockedGame->version;

            if ($state->turnStartSnapshot === null) {
                $state->turnStartSnapshot = $state->toArray();
                $state->round->turnStartVersion = $stateVersionBefore;
            }

            $steps = $place ? ($placedScholarCount === 0 ? 3 : 2) : 1;

            $playerState->resources->scholars--;

            if ($place) {
                $playerState->scholarPoolSize--;
                $playerState->scholarDisciplineIds[] = $discipline->value;
                $playerState->scholarSlotIndexes[] = $scholarSlotIndex;
            }

            $knowledgeLevelBefore = $playerState->knowledge->{$discipline->value};
            $gainedPower = $this->advanceKnowledge->execute($state, $playerState, $discipline, $steps);
            $advancedSteps = $playerState->knowledge->{$discipline->value} - $knowledgeLevelBefore;
            $roundScoringTile = RoundScoringTile::tryFrom((string) $state->round->scoringTileId);
            $victoryPoints = ($playerState->roundBonus === RoundBonus::SendScholar ? 2 : 0)
                + (in_array(Competency::Competency09->value, $playerState->competencyIds, true) ? 2 : 0)
                + ($roundScoringTile?->goal() === RoundScoringGoal::Knowledge ? $advancedSteps : 0);
            $playerState->victoryPoints += $victoryPoints;
            $state->round->hasTakenMainAction = true;
            $lockedGame->update([
                'state' => $state,
                'version' => $lockedGame->version + 1,
            ]);
            $this->appendGameHistory->execute(
                $lockedGame,
                $user,
                GameActionType::SendScholar,
                [
                    'discipline' => $discipline->value,
                    'placed' => $place,
                    'slot_index' => $scholarSlotIndex,
                    'steps' => $advancedSteps,
                    'victory_points' => $victoryPoints,
                    'gained_power' => $gainedPower,
                ],
                [[
                    'type' => 'scholar_sent',
                    'player_id' => $player->id,
                    'discipline' => $discipline->value,
                    'placed' => $place,
                    'steps' => $advancedSteps,
                ]],
                $stateVersionBefore,
                $lockedGame->version,
            );

            return $lockedGame->refresh();
        });
    }
}
