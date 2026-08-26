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
            $this->assignCompetency(
                $playerState,
                $competency,
                $state->setupPool?->competencies ?? [],
            );

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
                'knowledge_counts' => 'Распределите все стартовые шаги знаний.',
            ]);
        }

        foreach ($disciplines as $discipline) {
            $playerState->knowledge->{$discipline->value}++;
        }

        $playerState->knowledge->unassignedSteps = 0;
    }

    /** @param list<Competency|string> $availableCompetencies */
    private function assignCompetency(
        GamePlayerStateData $playerState,
        ?Competency $competency,
        array $availableCompetencies,
    ): void {
        $requiresCompetency = $playerState->faction === Faction::Inventors;

        if ($requiresCompetency !== ($competency instanceof Competency)) {
            throw ValidationException::withMessages([
                'competency_id' => 'Выберите стартовую компетенцию.',
            ]);
        }

        if ($competency instanceof Competency) {
            $competencyIndex = $this->competencyIndex($competency, $availableCompetencies);
            $disciplines = KnowledgeDiscipline::cases();
            $discipline = $disciplines[$competencyIndex % count($disciplines)];
            $competencyRow = intdiv($competencyIndex, count($disciplines));

            $playerState->competencyIds[] = $competency->value;
            $this->advanceKnowledge($playerState, $discipline, 3 - $competencyRow);
            $playerState->resources->books->{$discipline->value} += $competencyRow;
            $this->applyImmediateCompetencyEffect($playerState, $competency);
        }
    }

    private function applyImmediateCompetencyEffect(
        GamePlayerStateData $playerState,
        Competency $competency,
    ): void {
        match ($competency) {
            Competency::Competency04 => $this->grantCompetency04Resources($playerState),
            Competency::Competency05 => $playerState->unassignedSpades += 2,
            default => null,
        };
    }

    private function grantCompetency04Resources(GamePlayerStateData $playerState): void
    {
        $playerState->resources->tools++;
        $playerState->resources->coins += 2;
        $playerState->victoryPoints += 5;
    }

    /** @param list<Competency|string> $availableCompetencies */
    private function competencyIndex(Competency $competency, array $availableCompetencies): int
    {
        foreach ($availableCompetencies as $index => $availableCompetency) {
            $availableCompetencyValue = $availableCompetency instanceof Competency
                ? $availableCompetency->value
                : $availableCompetency;

            if ($availableCompetencyValue === $competency->value) {
                return $index;
            }
        }

        throw ValidationException::withMessages([
            'competency_id' => 'Выбранная компетенция недоступна.',
        ]);
    }

    private function advanceKnowledge(
        GamePlayerStateData $playerState,
        KnowledgeDiscipline $discipline,
        int $steps,
    ): void {
        $currentLevel = $playerState->knowledge->{$discipline->value};
        $newLevel = $currentLevel + $steps;
        $powerRewards = [3 => 1, 5 => 2, 7 => 2, 12 => 3];

        foreach ($powerRewards as $level => $power) {
            if ($currentLevel < $level && $newLevel >= $level) {
                $this->gainPower($playerState, $power);
            }
        }

        $playerState->knowledge->{$discipline->value} = $newLevel;
    }

    private function gainPower(GamePlayerStateData $playerState, int $power): void
    {
        for ($step = 0; $step < $power; $step++) {
            if ($playerState->resources->power->bowlOne > 0) {
                $playerState->resources->power->bowlOne--;
                $playerState->resources->power->bowlTwo++;

                continue;
            }

            if ($playerState->resources->power->bowlTwo > 0) {
                $playerState->resources->power->bowlTwo--;
                $playerState->resources->power->bowlThree++;
            }
        }
    }
}
