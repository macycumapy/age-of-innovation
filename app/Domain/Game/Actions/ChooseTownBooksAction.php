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

final class ChooseTownBooksAction
{
    public function __construct(
        private AppendGameHistoryAction $appendGameHistory,
        private ApplyChooseTownBooksAction $applyChooseTownBooks,
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

            if ($interaction?->type !== PendingInteractionType::ChooseTownBooks
                || ! $player instanceof GamePlayer
                || ! $playerState instanceof GamePlayerStateData
                || array_sum($knowledgeCounts) !== 0) {
                throw ValidationException::withMessages(['book_counts' => 'Нельзя распределить книги города.']);
            }

            $stateVersionBefore = $lockedGame->version;
            $nextActiveUserId = $this->applyChooseTownBooks->execute($state, $playerState, $bookCounts);
            $lockedGame->update([
                'active_player_id' => $nextActiveUserId,
                'state' => $state,
                'version' => $lockedGame->version + 1,
            ]);
            $this->appendGameHistory->execute(
                $lockedGame,
                $user,
                GameActionType::ChooseTownBooks,
                [
                    'disciplines' => array_keys(array_filter($bookCounts)),
                    'book_counts' => $bookCounts,
                    'knowledge_counts' => $knowledgeCounts,
                ],
                [[
                    'type' => GameEventType::TownBooksChosen->value,
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
