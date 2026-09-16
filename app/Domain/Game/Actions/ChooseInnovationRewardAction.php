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

final class ChooseInnovationRewardAction
{
    public function __construct(private AdvanceKnowledgeAction $advanceKnowledge)
    {
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

            if ($interaction?->type !== PendingInteractionType::ChooseInnovationBooks
                || ! $player instanceof GamePlayer
                || ! $playerState instanceof GamePlayerStateData
                || array_sum($bookCounts) !== $bookCount
                || array_sum($knowledgeCounts) !== $knowledgeStepCount
                || $playerState->resources->books->unassigned < $bookCount
                || $playerState->knowledge->unassignedSteps < $knowledgeStepCount) {
                throw ValidationException::withMessages(['game' => 'Нельзя распределить награду инновации.']);
            }

            foreach ($bookCounts as $discipline => $count) {
                $playerState->resources->books->{$discipline} += $count;
            }

            $playerState->resources->books->unassigned -= $bookCount;
            $advancedKnowledgeSteps = 0;
            $gainedPower = 0;

            foreach ($knowledgeCounts as $discipline => $count) {
                $levelBefore = $playerState->knowledge->{$discipline};
                $gainedPower += $this->advanceKnowledge->execute($state, $playerState, KnowledgeDiscipline::from($discipline), $count);
                $advancedKnowledgeSteps += $playerState->knowledge->{$discipline} - $levelBefore;
            }

            $playerState->knowledge->unassignedSteps -= $knowledgeStepCount;
            $roundTile = RoundScoringTile::tryFrom((string) $state->round->scoringTileId);
            $victoryPoints = $roundTile?->goal() === RoundScoringGoal::Knowledge ? $advancedKnowledgeSteps : 0;
            $playerState->victoryPoints += $victoryPoints;
            $state->pendingInteraction = null;
            $lockedGame->update([
                'state' => $state,
                'version' => $lockedGame->version + 1,
            ]);

            $sourceAction = $lockedGame->actions()
                ->where('type', GameActionType::MakeInnovation)
                ->where('player_id', $user->id)
                ->latest('sequence')
                ->first();

            if ($sourceAction === null) {
                throw ValidationException::withMessages(['game' => 'Не найдено действие, выдавшее награду инновации.']);
            }

            $payload = $sourceAction->payload;
            $payload['reward_book_counts'] = $bookCounts;
            $payload['reward_knowledge_counts'] = $knowledgeCounts;
            $payload['reward_knowledge_victory_points'] = $victoryPoints;
            $payload['gained_power'] = (int) ($payload['gained_power'] ?? 0) + $gainedPower;
            $events = $sourceAction->events ?? [];
            $events[] = [
                'type' => 'innovation_reward_distributed',
                'player_id' => $player->id,
                'book_counts' => $bookCounts,
                'knowledge_counts' => $knowledgeCounts,
            ];
            $sourceAction->update([
                'payload' => $payload,
                'events' => $events,
                'state_version_after' => $lockedGame->version,
            ]);

            return $lockedGame->refresh();
        });
    }
}
