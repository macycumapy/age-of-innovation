<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Enums\RoundScoringGoal;
use App\Domain\Game\Enums\RoundScoringTile;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ChooseFelineTownBonusAction
{
    public function __construct(
        private AppendGameHistoryAction $appendGameHistory,
        private AdvanceKnowledgeAction $advanceKnowledge,
        private CreateTownChoiceAfterBuildingAction $createTownChoiceAfterBuilding,
    ) {
    }

    /**
     * @param array<string, int> $bookCounts
     * @param array<string, int> $knowledgeCounts
     */
    public function execute(Game $game, User $user, array $bookCounts, array $knowledgeCounts): Game
    {
        return DB::transaction(function () use ($game, $user, $bookCounts, $knowledgeCounts): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $interaction = $state->pendingInteraction;
            $player = $lockedGame->players()->whereKey($interaction?->playerId)->whereBelongsTo($user)->first();
            $playerState = collect($state->players)->firstWhere('playerId', $player?->id);
            $bookCount = (int) ($interaction?->context['bookCount'] ?? 0);
            $knowledgeStepCount = (int) ($interaction?->context['knowledgeStepCount'] ?? 0);

            if ($interaction?->type !== PendingInteractionType::ChooseFelineTownBonus
                || ! $player instanceof GamePlayer
                || ! $playerState instanceof GamePlayerStateData
                || array_sum($bookCounts) !== $bookCount
                || array_sum($knowledgeCounts) !== $knowledgeStepCount
                || $playerState->resources->books->unassigned < $bookCount) {
                throw ValidationException::withMessages(['book_counts' => 'Нельзя распределить бонус Кошачьих.']);
            }

            $stateVersionBefore = $lockedGame->version;

            if ($state->turnStartSnapshot === null) {
                $state->turnStartSnapshot = $state->toArray();
                $state->round->turnStartVersion = $stateVersionBefore;
            }

            foreach ($bookCounts as $discipline => $count) {
                $playerState->resources->books->{$discipline} += $count;
            }

            $playerState->resources->books->unassigned -= $bookCount;
            $advancedKnowledgeSteps = 0;
            $gainedPower = 0;

            foreach ($knowledgeCounts as $discipline => $count) {
                $knowledgeDiscipline = KnowledgeDiscipline::from($discipline);
                $levelBefore = $playerState->knowledge->{$discipline};
                $gainedPower += $this->advanceKnowledge->execute($state, $playerState, $knowledgeDiscipline, $count);
                $advancedKnowledgeSteps += $playerState->knowledge->{$discipline} - $levelBefore;
            }

            $roundTile = RoundScoringTile::tryFrom((string) $state->round->scoringTileId);
            $victoryPoints = $roundTile?->goal() === RoundScoringGoal::Knowledge ? $advancedKnowledgeSteps : 0;
            $playerState->victoryPoints += $victoryPoints;
            $state->pendingInteraction = null;
            $nextActiveUserId = $player->user_id;

            if (is_string($interaction->context['continueBuildingAfterPowerHexId'] ?? null)) {
                $nextActiveUserId = $this->createTownChoiceAfterBuilding->execute(
                    $state,
                    $playerState,
                    $interaction->context['continueBuildingAfterPowerHexId'],
                    powerOffersResolved: true,
                );
            }

            $lockedGame->update([
                'active_player_id' => $nextActiveUserId,
                'state' => $state,
                'version' => $lockedGame->version + 1,
            ]);
            $this->appendGameHistory->execute(
                $lockedGame,
                $user,
                GameActionType::ChooseFelineTownBonus,
                [
                    'book_counts' => $bookCounts,
                    'knowledge_counts' => $knowledgeCounts,
                    'victory_points' => $victoryPoints,
                    'continue_building_after_power_hex_id' => $interaction->context['continueBuildingAfterPowerHexId'] ?? null,
                    'gained_power' => $gainedPower,
                ],
                [[
                    'type' => 'feline_town_bonus_chosen',
                    'player_id' => $player->id,
                    'book_counts' => $bookCounts,
                    'knowledge_counts' => $knowledgeCounts,
                ]],
                $stateVersionBefore,
                $lockedGame->version,
            );

            return $lockedGame->refresh();
        });
    }
}
