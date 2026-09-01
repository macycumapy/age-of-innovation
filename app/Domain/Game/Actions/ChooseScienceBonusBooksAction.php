<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\GameStatus;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ChooseScienceBonusBooksAction
{
    public function __construct(
        private AppendGameHistoryAction $appendGameHistory,
        private ResolveScienceBonusPhaseAction $resolveScienceBonusPhase,
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

            if ($lockedGame->phase !== GamePhase::ScienceBonus
                || $lockedGame->active_player_id !== $user->id
                || $interaction?->type !== PendingInteractionType::ChooseScienceBonusBooks
                || ! $player instanceof GamePlayer
                || count($disciplines) !== (int) ($interaction->context['bookCount'] ?? 0)) {
                throw ValidationException::withMessages(['book_counts' => 'Сейчас нельзя выбрать эти книги.']);
            }

            $playerState = collect($state->players)->firstWhere('playerId', $player->id);

            if (! $playerState instanceof GamePlayerStateData) {
                throw ValidationException::withMessages(['game' => 'Не найдено состояние игрока.']);
            }

            foreach ($disciplines as $discipline) {
                $playerState->resources->books->{$discipline->value}++;
            }

            $stateVersionBefore = $lockedGame->version;
            $state->pendingInteraction = null;
            [$nextPlayer, $nextPhase] = $this->resolveScienceBonusPhase->execute($state, $lockedGame->players);
            $lockedGame->update([
                'status' => $nextPhase === GamePhase::Finished ? GameStatus::Finished : GameStatus::Active,
                'phase' => $nextPhase,
                'active_player_id' => $nextPlayer?->user_id,
                'state' => $state,
                'version' => $lockedGame->version + 1,
            ]);
            $this->appendGameHistory->execute($lockedGame, $user, GameActionType::ChooseScienceBonusBooks, [
                'disciplines' => array_map(static fn (KnowledgeDiscipline $discipline): string => $discipline->value, $disciplines),
                'next_phase' => $nextPhase->value,
            ], [[
                'type' => 'science_bonus_books_chosen',
                'player_id' => $player->id,
            ]], $stateVersionBefore, $lockedGame->version);

            return $lockedGame->refresh();
        });
    }
}
