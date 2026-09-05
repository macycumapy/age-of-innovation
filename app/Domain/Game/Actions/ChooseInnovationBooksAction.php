<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ChooseInnovationBooksAction
{
    /** @param array<string, int> $bookCounts */
    public function execute(Game $game, User $user, array $bookCounts): Game
    {
        return DB::transaction(function () use ($game, $user, $bookCounts): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $interaction = $state->pendingInteraction;
            $bookCount = (int) ($interaction?->context['bookCount'] ?? 0);
            $player = $lockedGame->players()->whereKey($interaction?->playerId)->whereBelongsTo($user)->first();
            $playerState = $player instanceof GamePlayer
                ? collect($state->players)->firstWhere('playerId', $player->id)
                : null;

            if ($lockedGame->phase !== GamePhase::Actions
                || $lockedGame->active_player_id !== $user->id
                || $interaction?->type !== PendingInteractionType::ChooseInnovationBooks
                || ! $playerState instanceof GamePlayerStateData
                || array_sum($bookCounts) !== $bookCount
                || $playerState->resources->books->unassigned < $bookCount) {
                throw ValidationException::withMessages(['book_counts' => 'Сейчас нельзя распределить эти книги.']);
            }

            foreach ($bookCounts as $discipline => $count) {
                $playerState->resources->books->{$discipline} += $count;
            }

            $playerState->resources->books->unassigned -= $bookCount;
            $state->pendingInteraction = null;
            $lockedGame->update([
                'state' => $state,
                'version' => $lockedGame->version + 1,
            ]);

            $sourceActionType = match ($interaction->context['source'] ?? null) {
                'shipping' => GameActionType::AdvanceShipping,
                'terraforming' => GameActionType::AdvanceTerraforming,
                default => GameActionType::MakeInnovation,
            };
            $sourceAction = $lockedGame->actions()
                ->where('type', $sourceActionType)
                ->where('player_id', $user->id)
                ->latest('sequence')
                ->first();

            if ($sourceAction === null) {
                throw ValidationException::withMessages(['game' => 'Не найдено действие, выдавшее книги.']);
            }

            $payload = $sourceAction->payload;
            $payload['reward_book_counts'] = $bookCounts;
            $events = $sourceAction->events ?? [];
            $events[] = [
                'type' => match ($sourceActionType) {
                    GameActionType::AdvanceShipping => 'shipping_books_chosen',
                    GameActionType::AdvanceTerraforming => 'terraforming_books_chosen',
                    default => 'innovation_books_chosen',
                },
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
