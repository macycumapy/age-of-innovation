<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GameEventType;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ChooseInnovationRewardAction
{
    public function __construct(private ApplyInnovationRewardDistributionAction $applyInnovationRewardDistribution)
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

            if ($interaction?->type !== PendingInteractionType::ChooseInnovationReward
                || ! $player instanceof GamePlayer
                || ! $playerState instanceof GamePlayerStateData) {
                throw ValidationException::withMessages(['game' => 'Нельзя распределить награду инновации.']);
            }

            $result = $this->applyInnovationRewardDistribution->execute(
                $state,
                $playerState,
                $bookCounts,
                $knowledgeCounts,
            );
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
            $payload['reward_knowledge_victory_points'] = $result->victoryPoints;
            $payload['gained_power'] = (int) ($payload['gained_power'] ?? 0) + $result->gainedPower;
            $events = $sourceAction->events ?? [];
            $events[] = [
                'type' => GameEventType::InnovationRewardDistributed->value,
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
