<?php

declare(strict_types=1);

namespace App\Domain\Automation\Evaluation\Services;

use App\Domain\Automation\Evaluation\Data\GameStateScoreData;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Data\IncomeReceiptData;
use App\Domain\GameEngine\Economy\Services\PlayerIncomeCalculator;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Research\Enums\KnowledgeDiscipline;
use App\Domain\GameEngine\Research\Services\InnovationPurchaseCostCalculator;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use InvalidArgumentException;

final class GameStateEvaluator
{
    private const int VICTORY_POINT_WEIGHT = 10;

    private const int COIN_WEIGHT = 10;

    private const int TOOL_WEIGHT = 30;

    private const int SCHOLAR_WEIGHT = 40;

    private const int BOOK_WEIGHT = 25;

    private const int BOWL_ONE_POWER_WEIGHT = 1;

    private const int BOWL_TWO_POWER_WEIGHT = 7;

    private const int BOWL_THREE_POWER_WEIGHT = self::COIN_WEIGHT + self::BOWL_ONE_POWER_WEIGHT + 1;

    private const int KNOWLEDGE_STEP_WEIGHT = 30;

    private const int DEVELOPMENT_STEP_WEIGHT = 50;

    private const int SPADE_WEIGHT = 30;

    private const int AVAILABLE_ANNEX_WEIGHT = 10;

    private const int TOWN_WEIGHT = 80;

    private const int COMPETENCY_WEIGHT = 50;

    private const int INVENTION_WEIGHT = 300;

    private const int INNOVATION_READINESS_WEIGHT = 40;

    private const int PLACED_SCHOLAR_WEIGHT = 40;

    private const int BUILDING_POWER_WEIGHT = 40;

    private const int PLACED_ANNEX_WEIGHT = 25;

    public function __construct(
        private InnovationPurchaseCostCalculator $innovationPurchaseCostCalculator,
        private PalaceAbilityEvaluator $palaceAbilityEvaluator,
        private CompetencyEvaluator $competencyEvaluator,
        private FinalScoringProgressEvaluator $finalScoringProgressEvaluator,
    ) {
    }

    public function execute(GameStateData $state, int $playerId): int
    {
        return $this->evaluateWithBreakdown($state, $playerId)->total();
    }

    public function evaluateWithBreakdown(GameStateData $state, int $playerId): GameStateScoreData
    {
        $player = collect($state->players)->firstWhere('playerId', $playerId);

        if (! $player instanceof GamePlayerStateData) {
            throw new InvalidArgumentException('Не найдено состояние игрока для оценки.');
        }

        $playerScore = $this->playerScore($state, $player);
        $strongestOpponentScore = collect($state->players)
            ->reject(static fn (GamePlayerStateData $candidate): bool => $candidate->playerId === $playerId)
            ->map(fn (GamePlayerStateData $candidate): int => $this->playerScore($state, $candidate)->total())
            ->max();

        return new GameStateScoreData(
            victoryPoints: $playerScore->victoryPoints,
            resources: $playerScore->resources,
            development: $playerScore->development,
            buildings: $playerScore->buildings,
            futureIncome: $playerScore->futureIncome,
            projectedFinalScoring: $playerScore->projectedFinalScoring,
            opponent: is_int($strongestOpponentScore) ? $strongestOpponentScore : 0,
        );
    }

    private function playerScore(GameStateData $state, GamePlayerStateData $player): GameStateScoreData
    {
        $books = $player->resources->books;
        $power = $player->resources->power;
        $score = $player->resources->coins * $this->resourceWeight($state, self::COIN_WEIGHT, 2);
        $score += $player->resources->tools * $this->resourceWeight($state, self::TOOL_WEIGHT, 2);
        $score += $player->resources->scholars * $this->resourceWeight($state, self::SCHOLAR_WEIGHT, 2);
        $score += ($books->banking + $books->law + $books->engineering + $books->medicine + $books->unassigned)
            * $this->resourceWeight($state, self::BOOK_WEIGHT, 2);
        $score += ($power->bowlOne * $this->resourceWeight($state, self::BOWL_ONE_POWER_WEIGHT, 0))
            + ($power->bowlTwo * $this->resourceWeight($state, self::BOWL_TWO_POWER_WEIGHT, 1))
            + ($power->bowlThree * $this->resourceWeight($state, self::BOWL_THREE_POWER_WEIGHT, 2));
        $score += $player->unassignedSpades * self::SPADE_WEIGHT;
        $score += $player->availableAnnexes * self::AVAILABLE_ANNEX_WEIGHT;
        $resourceScore = $score;
        $score = $player->knowledge->unassignedSteps * $this->resourceWeight($state, self::KNOWLEDGE_STEP_WEIGHT, 0);

        foreach (KnowledgeDiscipline::cases() as $discipline) {
            $level = $player->knowledge->{$discipline->value};
            $score += $level * $this->resourceWeight($state, self::KNOWLEDGE_STEP_WEIGHT, 0);
        }

        $score += ($player->shippingLevel + $player->terraformingLevel) * self::DEVELOPMENT_STEP_WEIGHT;
        $score += count($player->townTileIds) * $this->resourceWeight($state, self::TOWN_WEIGHT, 0);
        $score += count($player->competencyIds) * $this->resourceWeight($state, self::COMPETENCY_WEIGHT, 0);
        $score += count($player->inventionIds) * $this->resourceWeight($state, self::INVENTION_WEIGHT, 0);
        $score += $this->innovationReadinessScore($state, $player);
        $score += $this->palaceAbilityEvaluator->execute($state, $player);
        $score += $this->competencyEvaluator->execute($state, $player);
        $score += count($player->scholarDisciplineIds) * self::PLACED_SCHOLAR_WEIGHT;
        $developmentScore = $score;
        $score = 0;

        foreach ($state->board->hexes as $hex) {
            if ($hex->building?->ownerPlayerId !== $player->playerId) {
                continue;
            }

            $score += $hex->building->type->powerValue() * self::BUILDING_POWER_WEIGHT;
            $score += $hex->building->hasAnnex ? self::PLACED_ANNEX_WEIGHT : 0;
        }

        return new GameStateScoreData(
            victoryPoints: $player->victoryPoints * self::VICTORY_POINT_WEIGHT,
            resources: $resourceScore,
            development: $developmentScore,
            buildings: $score,
            futureIncome: $this->futureIncomeScore($state, $player),
            projectedFinalScoring: $state->round->phase === GamePhase::Finished
                ? 0
                : $this->finalScoringProgressEvaluator->projectedScore($state, $player->playerId) * self::VICTORY_POINT_WEIGHT,
        );
    }

    private function innovationReadinessScore(GameStateData $state, GamePlayerStateData $player): int
    {
        if ($state->setupPool === null
            || count($player->inventionIds) >= InnovationPurchaseCostCalculator::MAX_INVENTIONS) {
            return 0;
        }

        $books = $player->resources->books;
        $assignedBookCount = $books->banking + $books->law + $books->engineering + $books->medicine;
        $bestProgress = 0;

        foreach ($state->setupPool->innovations as $slotIndex => $innovation) {
            $innovationId = is_string($innovation) ? $innovation : $innovation->value;
            if (! in_array($innovationId, $state->availableInventionIds, true)) {
                continue;
            }

            $cost = $this->innovationPurchaseCostCalculator->cost(
                $state->setupPool->playerCount,
                $slotIndex,
                count($player->inventionIds),
                $player->homeland === TerrainType::Wasteland,
                $player->palaceId !== null,
            );
            $fulfilledRequiredBooks = min($books->banking, $cost['requiredBooks']['banking'])
                + min($books->law, $cost['requiredBooks']['law'])
                + min($books->engineering, $cost['requiredBooks']['engineering'])
                + min($books->medicine, $cost['requiredBooks']['medicine']);
            $missingRequiredBooks = array_sum($cost['requiredBooks']) - $fulfilledRequiredBooks;
            $assignedGenericBooks = max(0, $assignedBookCount - $fulfilledRequiredBooks);
            $unassignedRequiredBooks = min($books->unassigned, $missingRequiredBooks);
            $remainingUnassignedBooks = $books->unassigned - $unassignedRequiredBooks;
            $genericBookSlots = $cost['totalBooks'] - array_sum($cost['requiredBooks']);
            $genericBooks = min(
                $genericBookSlots,
                $assignedGenericBooks + $remainingUnassignedBooks,
            );
            $bestProgress = max(
                $bestProgress,
                $fulfilledRequiredBooks + $unassignedRequiredBooks + $genericBooks,
            );
        }

        return $bestProgress * $this->resourceWeight($state, self::INNOVATION_READINESS_WEIGHT, 0);
    }

    private function resourceWeight(GameStateData $state, int $earlyWeight, int $finalWeight): int
    {
        if ($state->round->phase === GamePhase::Setup) {
            return $earlyWeight;
        }

        $remainingRounds = max(0, min(2, 6 - $state->round->number));

        return intdiv($earlyWeight * $remainingRounds + $finalWeight * (2 - $remainingRounds), 2);
    }

    private function futureIncomeScore(GameStateData $state, GamePlayerStateData $player): int
    {
        $remainingIncomePhases = $state->round->phase === GamePhase::Setup
            ? 6
            : max(0, 6 - $state->round->number);

        if ($remainingIncomePhases === 0) {
            return 0;
        }

        $incomePlayer = clone $player;
        $incomePlayer->roundBonus = RoundBonus::RiverWorkshop;
        $income = PlayerIncomeCalculator::calculate($incomePlayer, $state->board);

        return $this->incomeScore($income) * $remainingIncomePhases;
    }

    private function incomeScore(IncomeReceiptData $income): int
    {
        return ($income->victoryPoints * self::VICTORY_POINT_WEIGHT)
            + ($income->coins * self::COIN_WEIGHT)
            + ($income->tools * self::TOOL_WEIGHT)
            + ($income->scholars * self::SCHOLAR_WEIGHT)
            + ($income->books * self::BOOK_WEIGHT)
            + ($income->power * self::BOWL_TWO_POWER_WEIGHT)
            + ($income->knowledgeSteps * self::KNOWLEDGE_STEP_WEIGHT);
    }
}
