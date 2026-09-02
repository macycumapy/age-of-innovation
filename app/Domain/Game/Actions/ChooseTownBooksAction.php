<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\KnowledgeDiscipline;
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
    ) {
    }

    /** @param list<KnowledgeDiscipline> $disciplines */
    public function execute(Game $game, User $user, array $disciplines): Game
    {
        return DB::transaction(function () use ($game, $user, $disciplines): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $interaction = $state->pendingInteraction;
            $player = $lockedGame->players()->whereKey($interaction?->playerId)->whereBelongsTo($user)->first();
            $playerState = collect($state->players)->firstWhere('playerId', $player?->id);

            if ($interaction?->type !== PendingInteractionType::ChooseTownBooks
                || ! $player instanceof GamePlayer
                || ! $playerState instanceof GamePlayerStateData
                || count($disciplines) !== 2
                || $playerState->resources->books->unassigned < 2) {
                throw ValidationException::withMessages(['book_counts' => 'Нельзя распределить книги города.']);
            }

            $stateVersionBefore = $lockedGame->version;

            foreach ($disciplines as $discipline) {
                $playerState->resources->books->{$discipline->value}++;
            }

            $playerState->resources->books->unassigned -= 2;
            $state->pendingInteraction = null;
            $lockedGame->update([
                'active_player_id' => $player->user_id,
                'state' => $state,
                'version' => $lockedGame->version + 1,
            ]);
            $this->appendGameHistory->execute(
                $lockedGame,
                $user,
                GameActionType::ChooseTownBooks,
                ['disciplines' => array_column($disciplines, 'value')],
                [['type' => 'town_books_chosen', 'player_id' => $player->id]],
                $stateVersionBefore,
                $lockedGame->version,
            );

            return $lockedGame->refresh();
        });
    }
}
