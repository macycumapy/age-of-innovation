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

final class DistributeRewardBooksAction
{
    public function __construct(private ApplyRewardBookDistributionAction $applyRewardBookDistribution)
    {
    }

    /** @param array<string, int> $bookCounts */
    public function execute(
        Game $game,
        User $user,
        array $bookCounts,
        PendingInteractionType $interactionType,
        GameActionType $sourceActionType,
        GameEventType $historyEventType,
        string $rewardName,
    ): Game {
        return DB::transaction(function () use (
            $game,
            $user,
            $bookCounts,
            $interactionType,
            $sourceActionType,
            $historyEventType,
            $rewardName,
        ): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $interaction = $state->pendingInteraction;
            $player = $lockedGame->players()->whereKey($interaction?->playerId)->whereBelongsTo($user)->first();
            $playerState = $player instanceof GamePlayer
                ? collect($state->players)->firstWhere('playerId', $player->id)
                : null;

            if (! $lockedGame->phase->isActionPhase()
                || $lockedGame->active_player_id !== $user->id
                || $interaction?->type !== $interactionType
                || ! $playerState instanceof GamePlayerStateData) {
                throw ValidationException::withMessages([
                    'book_counts' => "Сейчас нельзя распределить книги {$rewardName}.",
                ]);
            }

            $nextActivePlayerId = $this->applyRewardBookDistribution->execute(
                $state,
                $playerState,
                $bookCounts,
                $interactionType,
            );

            $lockedGame->update([
                'active_game_player_id' => $nextActivePlayerId,
                'state' => $state,
                'version' => $lockedGame->version + 1,
            ]);
            $sourceAction = $lockedGame->actions()
                ->where('type', $sourceActionType)
                ->where('player_id', $user->id)
                ->latest('sequence')
                ->first();

            if ($sourceAction === null) {
                throw ValidationException::withMessages([
                    'game' => "Не найдено действие, выдавшее книги {$rewardName}.",
                ]);
            }

            $payload = $sourceAction->payload;
            $payload['reward_book_counts'] = $bookCounts;
            $events = $sourceAction->events ?? [];
            $events[] = [
                'type' => $historyEventType->value,
                'player_id' => $player->id,
                'book_counts' => $bookCounts,
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
