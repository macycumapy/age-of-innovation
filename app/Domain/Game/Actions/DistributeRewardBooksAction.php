<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GameEventType;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Models\Game;
use App\Models\GamePlayer;
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
        GamePlayer $player,
        array $bookCounts,
        PendingInteractionType $interactionType,
        GameActionType $sourceActionType,
        GameEventType $historyEventType,
        string $rewardName,
    ): Game {
        return DB::transaction(function () use (
            $game,
            $player,
            $bookCounts,
            $interactionType,
            $sourceActionType,
            $historyEventType,
            $rewardName,
        ): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $interaction = $state->pendingInteraction;
            $playerState = collect($state->players)->firstWhere('playerId', $player->id);

            if (! $lockedGame->phase->isActionPhase()
                || $player->game_id !== $lockedGame->id
                || ! $lockedGame->isActivePlayer($player)
                || $interaction?->type !== $interactionType
                || $interaction->playerId !== $player->id
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
                ->where('game_player_id', $player->id)
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
