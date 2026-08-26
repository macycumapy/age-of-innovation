<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\Competency;
use App\Domain\Game\Enums\Faction;
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

    /**
     * @param list<KnowledgeDiscipline> $bookDisciplines
     * @param list<KnowledgeDiscipline> $knowledgeDisciplines
     */
    public function execute(
        Game $game,
        User $user,
        array $bookDisciplines,
        array $knowledgeDisciplines,
        ?Competency $competency,
    ): Game {
        return DB::transaction(function () use ($game, $user, $bookDisciplines, $knowledgeDisciplines, $competency): Game {
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
            $this->assignBooks($playerState, $bookDisciplines);
            $this->assignKnowledge($playerState, $knowledgeDisciplines);
            $this->assignCompetency($playerState, $competency);

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

    /** @param list<KnowledgeDiscipline> $disciplines */
    private function assignBooks(GamePlayerStateData $playerState, array $disciplines): void
    {
        $bookCount = $playerState->resources->books->unassigned;

        if (count($disciplines) !== $bookCount) {
            throw ValidationException::withMessages([
                'book_counts' => 'Распределите все стартовые книги.',
            ]);
        }

        foreach ($disciplines as $discipline) {
            $playerState->resources->books->{$discipline->value}++;
        }

        $playerState->resources->books->unassigned = 0;
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

    private function assignCompetency(GamePlayerStateData $playerState, ?Competency $competency): void
    {
        $requiresCompetency = $playerState->faction === Faction::Inventors;

        if ($requiresCompetency !== ($competency instanceof Competency)) {
            throw ValidationException::withMessages([
                'competency_id' => 'Выберите стартовую компетенцию.',
            ]);
        }

        if ($competency instanceof Competency) {
            $playerState->competencyIds[] = $competency->value;
        }
    }
}
