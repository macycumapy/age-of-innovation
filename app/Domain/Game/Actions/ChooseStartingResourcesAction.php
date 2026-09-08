<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Enums\Competency;
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

final class ChooseStartingResourcesAction
{
    public function __construct(
        private DetermineNextPlanningPlayerAction $determineNextPlanningPlayer,
        private AppendGameHistoryAction $appendGameHistory,
        private AdvanceKnowledgeAction $advanceKnowledge,
        private ResolveIncomePhaseAction $resolveIncomePhase,
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
            $stateVersionBefore = $lockedGame->version;
            $interaction = $lockedGame->state->pendingInteraction;
            $interactionPhase = $lockedGame->phase;

            if ($lockedGame->status !== GameStatus::Active
                || ! in_array($interactionPhase, [GamePhase::Setup, GamePhase::Income], true)
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
            $this->assignKnowledge($state, $playerState, $knowledgeDisciplines);
            if ($interactionPhase === GamePhase::Setup && $competency instanceof Competency) {
                throw ValidationException::withMessages([
                    'competency_id' => 'Стартовая компетенция выбирается после расстановки зданий.',
                ]);
            }

            $state->players[$playerStateIndex] = $playerState;
            $state->pendingInteraction = null;
            $nextPhase = $interactionPhase;
            $incomeReceipts = [];

            if ($interactionPhase === GamePhase::Income) {
                [$nextPlayer, $nextPhase, $incomeReceipts] = $this->resolveIncomePhase->execute(
                    $state,
                    $lockedGame->players()->get(),
                );
            } else {
                $nextPlayer = $this->determineNextPlanningPlayer->execute($lockedGame, $player);
            }

            $lockedGame->update([
                'active_player_id' => $nextPlayer->user_id,
                'phase' => $nextPhase,
                'version' => $lockedGame->version + 1,
                'state' => $state,
            ]);
            $this->appendGameHistory->execute(
                $lockedGame,
                $user,
                $interactionPhase === GamePhase::Income
                    ? GameActionType::ChooseIncomeResources
                    : GameActionType::ChooseStartingResources,
                [
                    'book_disciplines' => array_map(
                        static fn (KnowledgeDiscipline $discipline): string => $discipline->value,
                        $bookDisciplines,
                    ),
                    'knowledge_disciplines' => array_map(
                        static fn (KnowledgeDiscipline $discipline): string => $discipline->value,
                        $knowledgeDisciplines,
                    ),
                    'competency' => $competency?->value,
                    'phase' => $interactionPhase->value,
                    'income_receipts' => $incomeReceipts,
                ],
                [[
                    'type' => $interactionPhase === GamePhase::Income
                        ? 'income_resources_chosen'
                        : 'starting_resources_chosen',
                    'player_id' => $player->id,
                ]],
                $stateVersionBefore,
                $lockedGame->version,
                $nextPhase !== $interactionPhase,
            );

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
    private function assignKnowledge(
        GameStateData $state,
        GamePlayerStateData $playerState,
        array $disciplines,
    ): void {
        if (count($disciplines) !== $playerState->knowledge->unassignedSteps) {
            throw ValidationException::withMessages([
                'knowledge_counts' => 'Распределите все стартовые шаги знаний.',
            ]);
        }

        foreach ($disciplines as $discipline) {
            $this->advanceKnowledge->execute($state, $playerState, $discipline, 1);
        }

        $playerState->knowledge->unassignedSteps = 0;
    }

}
