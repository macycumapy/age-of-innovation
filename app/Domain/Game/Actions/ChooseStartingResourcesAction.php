<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\GameStatus;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ChooseStartingResourcesAction
{
    public function __construct(
        private DetermineNextPlanningPlayerAction $determineNextPlanningPlayer,
    ) {
    }

    /** @param list<KnowledgeDiscipline> $knowledgeDisciplines */
    public function execute(
        Game $game,
        User $user,
        ?KnowledgeDiscipline $bookDiscipline,
        array $knowledgeDisciplines,
    ): Game {
        return DB::transaction(function () use ($game, $user, $bookDiscipline, $knowledgeDisciplines): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $interaction = $lockedGame->state->pendingInteraction;

            if ($lockedGame->status !== GameStatus::Active
                || $lockedGame->phase !== GamePhase::Setup
                || $interaction?->type !== PendingInteractionType::ChooseStartingResources) {
                throw ValidationException::withMessages([
                    'game' => 'Выбор стартовых ресурсов сейчас недоступен.',
                ]);
            }

            $player = $lockedGame->players()
                ->whereKey($interaction->playerId)
                ->whereBelongsTo($user)
                ->first();

            if (! $player instanceof GamePlayer || $lockedGame->active_player_id !== $user->id) {
                throw ValidationException::withMessages([
                    'game' => 'Стартовые ресурсы должен выбрать текущий игрок.',
                ]);
            }

            $state = $lockedGame->state;
            $playerStateIndex = null;

            foreach ($state->players as $index => $candidatePlayerState) {
                if ($candidatePlayerState->playerId === $player->id) {
                    $playerStateIndex = $index;

                    break;
                }
            }

            if ($playerStateIndex === null) {
                throw ValidationException::withMessages([
                    'game' => 'Не найдено игровое состояние участника.',
                ]);
            }

            $playerState = $state->players[$playerStateIndex];
            $this->assignBook($playerState, $bookDiscipline);
            $this->assignKnowledge($playerState, $knowledgeDisciplines);

            $state->players[$playerStateIndex] = $playerState;
            $state->pendingInteraction = null;
            $nextPlayer = $this->determineNextPlanningPlayer->execute($lockedGame, $player);

            $lockedGame->update([
                'active_player_id' => $nextPlayer->user_id,
                'version' => $lockedGame->version + 1,
                'state' => $state,
            ]);

            return $lockedGame->refresh();
        });
    }

    private function assignBook(GamePlayerStateData $playerState, ?KnowledgeDiscipline $discipline): void
    {
        $bookCount = $playerState->resources->books->unassigned;

        if (($bookCount > 0) !== ($discipline instanceof KnowledgeDiscipline)) {
            throw ValidationException::withMessages([
                'book_discipline' => 'Выберите дисциплину стартовой книги.',
            ]);
        }

        if ($discipline instanceof KnowledgeDiscipline) {
            $playerState->resources->books->{$discipline->value} += $bookCount;
            $playerState->resources->books->unassigned = 0;
        }
    }

    /** @param list<KnowledgeDiscipline> $disciplines */
    private function assignKnowledge(GamePlayerStateData $playerState, array $disciplines): void
    {
        if (count($disciplines) !== $playerState->knowledge->unassignedSteps) {
            throw ValidationException::withMessages([
                'knowledge_disciplines' => 'Распределите все стартовые шаги знаний.',
            ]);
        }

        foreach ($disciplines as $discipline) {
            $playerState->knowledge->{$discipline->value}++;
        }

        $playerState->knowledge->unassignedSteps = 0;
    }
}
