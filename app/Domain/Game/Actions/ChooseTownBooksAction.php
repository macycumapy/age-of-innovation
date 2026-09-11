<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\GameActionType;
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
        private StartLizardTownBonusAction $startLizardTownBonus,
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

            if ($interaction?->type !== PendingInteractionType::ChooseTownBooks
                || ! $player instanceof GamePlayer
                || ! $playerState instanceof GamePlayerStateData
                || array_sum($bookCounts) !== $bookCount
                || array_sum($knowledgeCounts) !== 0
                || $playerState->resources->books->unassigned < $bookCount) {
                throw ValidationException::withMessages(['book_counts' => 'Нельзя распределить книги города.']);
            }

            $stateVersionBefore = $lockedGame->version;

            foreach ($bookCounts as $discipline => $count) {
                $playerState->resources->books->{$discipline} += $count;
            }

            $playerState->resources->books->unassigned -= $bookCount;

            $state->pendingInteraction = null;

            if (($interaction->context['felineBonusPending'] ?? false) === true) {
                $playerState->resources->books->unassigned++;
                $state->pendingInteraction = new PendingInteractionData(
                    PendingInteractionType::ChooseFelineTownBonus,
                    $player->id,
                    [],
                    [
                        'bookCount' => 1,
                        'knowledgeStepCount' => 3,
                        'builtHexId' => (string) ($interaction->context['builtHexId'] ?? ''),
                        'queuedBuiltHexIds' => $interaction->context['queuedBuiltHexIds'] ?? [],
                    ],
                );
            } elseif (($interaction->context['lizardBonusPending'] ?? false) === true
                && $playerState->faction === Faction::Lizards) {
                $this->startLizardTownBonus->execute($state, $playerState);
            }
            $lockedGame->update([
                'active_player_id' => $player->user_id,
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
                    'type' => 'town_books_chosen',
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
