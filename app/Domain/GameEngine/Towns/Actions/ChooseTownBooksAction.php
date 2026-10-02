<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Towns\Actions;

use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\Enums\GameEventType;
use App\Domain\GameEngine\History\Actions\AppendGameHistoryAction;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Models\Game;
use App\Models\GamePlayer;
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
    public function execute(Game $game, GamePlayer $player, array $bookCounts, array $knowledgeCounts): Game
    {
        return DB::transaction(function () use ($game, $player, $bookCounts, $knowledgeCounts): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $interaction = $state->pendingInteraction;
            $playerState = collect($state->players)->firstWhere('playerId', $player->id);

            if ($player->game_id !== $lockedGame->id
                || ! $lockedGame->isActivePlayer($player)
                || $interaction?->type !== PendingInteractionType::ChooseTownBooks
                || $interaction->playerId !== $player->id
                || ! $playerState instanceof GamePlayerStateData
                || array_sum($knowledgeCounts) !== 0) {
                throw ValidationException::withMessages(['book_counts' => 'Нельзя распределить книги города.']);
            }

            $stateVersionBefore = $lockedGame->version;
            $nextActivePlayerId = $this->applyChooseTownBooks->execute($state, $playerState, $bookCounts);
            $lockedGame->update([
                'active_game_player_id' => $nextActivePlayerId,
                'state' => $state,
                'version' => $lockedGame->version + 1,
            ]);
            $this->appendGameHistory->execute(
                $lockedGame,
                $player,
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
