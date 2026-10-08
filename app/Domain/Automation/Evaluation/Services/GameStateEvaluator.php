<?php

declare(strict_types=1);

namespace App\Domain\Automation\Evaluation\Services;

use App\Domain\Automation\Evaluation\Data\GameStateScoreData;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Board\Services\BuildingAdjacencyChecker;
use App\Domain\GameEngine\Economy\Data\IncomeReceiptData;
use App\Domain\GameEngine\Economy\Enums\PowerAction;
use App\Domain\GameEngine\Economy\Services\PlayerIncomeCalculator;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Research\Enums\Innovation;
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

    private const int COIN_WORKING_RESERVE = 12;

    private const int TOOL_WEIGHT = 30;

    private const int SCHOLAR_WEIGHT = 40;

    private const int BOOK_WEIGHT = 25;

    private const int BOWL_ONE_POWER_WEIGHT = 1;

    private const int BOWL_TWO_POWER_WEIGHT = 7;

    private const int POWER_CAPACITY_PER_ROUND_WEIGHT = 10;

    private const int BOWL_THREE_POWER_WEIGHT = self::COIN_WEIGHT + self::BOWL_ONE_POWER_WEIGHT + 1;

    private const int KNOWLEDGE_STEP_WEIGHT = 10;

    private const int DEVELOPMENT_STEP_WEIGHT = 50;

    private const int SPADE_WEIGHT = 30;

    private const int AVAILABLE_ANNEX_WEIGHT = 10;

    private const int TOWN_WEIGHT = 80;

    private const int COMPETENCY_WEIGHT = 50;

    private const int BUILDING_POWER_WEIGHT = 40;

    public function __construct(
        private InnovationPurchaseCostCalculator $innovationPurchaseCostCalculator,
        private PalaceAbilityEvaluator $palaceAbilityEvaluator,
        private CompetencyEvaluator $competencyEvaluator,
        private InnovationEvaluator $innovationEvaluator,
        private ScienceBonusEvaluator $scienceBonusEvaluator,
        private FinalScoringProgressEvaluator $finalScoringProgressEvaluator,
        private IncomeValueEvaluator $incomeValueEvaluator,
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
        $workingCoins = min($player->resources->coins, self::COIN_WORKING_RESERVE);
        $score = $workingCoins * $this->resourceWeight($state, self::COIN_WEIGHT, 2)
            + max(0, $player->resources->coins - $workingCoins) * 2;
        $score += $player->resources->tools * $this->resourceWeight($state, self::TOOL_WEIGHT, 2);
        $score += $player->resources->scholars * $this->resourceWeight($state, self::SCHOLAR_WEIGHT, 2);
        $score += ($books->banking + $books->law + $books->engineering + $books->medicine + $books->unassigned)
            * $this->resourceWeight($state, self::BOOK_WEIGHT, 2);
        $score += ($power->bowlOne * $this->resourceWeight($state, self::BOWL_ONE_POWER_WEIGHT, 0))
            + ($power->bowlTwo * $this->resourceWeight($state, self::BOWL_TWO_POWER_WEIGHT, 1))
            + ($power->bowlThree * $this->resourceWeight($state, self::BOWL_THREE_POWER_WEIGHT, 2));
        $remainingRounds = $state->round->phase === GamePhase::Setup ? 6 : max(0, 6 - $state->round->number);
        $score += min(
            $power->bowlOne + $power->bowlTwo + $power->bowlThree,
            PowerAction::TerraformTwoSpades->cost($player->faction),
        ) * $remainingRounds * self::POWER_CAPACITY_PER_ROUND_WEIGHT;
        $score += $player->unassignedSpades * $this->resourceWeight($state, self::SPADE_WEIGHT, 0);
        $score += $player->availableAnnexes * $this->resourceWeight($state, self::AVAILABLE_ANNEX_WEIGHT, 0);
        $resourceScore = $score;
        $score = $player->knowledge->unassignedSteps * $this->resourceWeight($state, self::KNOWLEDGE_STEP_WEIGHT, 0);

        foreach (KnowledgeDiscipline::cases() as $discipline) {
            $level = $player->knowledge->{$discipline->value};
            $score += $level * $this->resourceWeight($state, self::KNOWLEDGE_STEP_WEIGHT, 0);
        }

        $score += ($player->shippingLevel + $player->terraformingLevel) * $this->resourceWeight($state, self::DEVELOPMENT_STEP_WEIGHT, 0);
        $score += count($player->townTileIds) * $this->resourceWeight($state, self::TOWN_WEIGHT, 0);
        $score += count($player->competencyIds) * $this->resourceWeight($state, self::COMPETENCY_WEIGHT, 0);
        $score += $this->innovationReadinessScore($state, $player);
        $score += $this->palaceAbilityEvaluator->execute($state, $player);
        $score += $this->competencyEvaluator->execute($state, $player);
        $score += $this->roundBonusOpportunityScore($state, $player);
        $score += $this->innovationEvaluator->execute($state, $player);
        $developmentScore = $score;
        $score = 0;

        foreach ($state->board->hexes as $hex) {
            if ($hex->building?->ownerPlayerId !== $player->playerId) {
                continue;
            }

            if (BuildingAdjacencyChecker::hasOpponent($state->board, $hex, $player->playerId)) {
                $score += $hex->building->type->powerValue() * $this->resourceWeight($state, self::BUILDING_POWER_WEIGHT, 0);
            }
        }

        return new GameStateScoreData(
            victoryPoints: $player->victoryPoints * self::VICTORY_POINT_WEIGHT,
            resources: $resourceScore,
            development: $developmentScore,
            buildings: $score,
            futureIncome: $this->futureIncomeScore($state, $player),
            projectedFinalScoring: $state->round->phase === GamePhase::Finished
                ? 0
                : $this->finalScoringProgressEvaluator->projectedScore($state, $player->playerId)
                    * ($state->round->number < 5 ? intdiv(self::VICTORY_POINT_WEIGHT, 2) : self::VICTORY_POINT_WEIGHT),
        );
    }

    private function innovationReadinessScore(GameStateData $state, GamePlayerStateData $player): int
    {
        if ($state->round->phase === GamePhase::Finished || $state->setupPool === null
            || count($player->inventionIds) >= InnovationPurchaseCostCalculator::MAX_INVENTIONS) {
            return 0;
        }

        $books = $player->resources->books;
        $assignedBookCount = $books->banking + $books->law + $books->engineering + $books->medicine;
        $bestProgress = 0;

        foreach ($state->setupPool->innovations as $slotIndex => $innovation) {
            $innovation = $this->innovationType($innovation);
            if (! in_array($innovation->value, $state->availableInventionIds, true)) {
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
            $fulfilledBookSlots = $fulfilledRequiredBooks + $unassignedRequiredBooks + $genericBooks;
            $missingBookSlots = $cost['totalBooks'] - $fulfilledBookSlots;
            $purchaseState = clone $state;
            $purchaseState->round = clone $state->round;
            $bookIncome = max(1, PlayerIncomeCalculator::calculate($player, $state->board, includeRoundBonus: false)->books);
            $purchaseDelay = max(
                (int) ceil($missingBookSlots / $bookIncome),
                (int) in_array($player->playerId, $state->passedPlayerIds, true),
            );
            $purchaseState->round->number += $purchaseDelay;
            if ($purchaseDelay > 0) {
                $purchaseState->passedPlayerIds = [];
            }
            $purchaseState->round->phase = GamePhase::Actions;
            if ($purchaseState->round->number > 6) {
                continue;
            }
            $purchasePotential = $this->innovationEvaluator->purchasePotential(
                $purchaseState,
                $player,
                $innovation,
            );
            $purchaseSurplus = max(0, $purchasePotential
                - $cost['totalBooks'] * $this->resourceWeight($state, self::BOOK_WEIGHT, 2)
                - $cost['coins'] * $this->resourceWeight($state, self::COIN_WEIGHT, 2));
            $progressScore = intdiv(
                $fulfilledBookSlots * $purchaseSurplus,
                $cost['totalBooks'],
            );
            $bestProgress = max($bestProgress, $progressScore);
        }

        return $bestProgress;
    }

    private function innovationType(Innovation|string $innovation): Innovation
    {
        return $innovation instanceof Innovation ? $innovation : Innovation::from($innovation);
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

        $income = PlayerIncomeCalculator::calculate($player, $state->board, includeRoundBonus: false);
        $income->scholars = min($income->scholars, $player->scholarPoolSize);
        $incomeState = clone $state;
        $incomeState->round = clone $state->round;
        $incomeState->round->phase = GamePhase::Income;
        $firstIncome = clone $income;
        $firstIncome->scholars = min($income->scholars, $this->scholarIncomeCapacity($state, $player));
        $firstIncomeRound = 7 - $remainingIncomePhases;
        $score = 0;
        for ($incomeRound = $firstIncomeRound; $incomeRound <= 6; $incomeRound++) {
            $incomeState->round->number = $incomeRound;
            $score += $this->incomeScore($incomeRound === $firstIncomeRound ? $firstIncome : $income, $incomeState);
        }

        $isChoosingNextBonus = $state->pendingInteraction?->type === PendingInteractionType::ChooseRoundBonus
            && $state->pendingInteraction->playerId === $player->playerId;
        $hasSelectedNextBonus = in_array($player->playerId, $state->passedPlayerIds, true)
            && ! $isChoosingNextBonus;

        if ($state->round->phase === GamePhase::Setup
            || $state->round->phase === GamePhase::ScienceBonus
            || ($state->round->phase === GamePhase::Actions && $hasSelectedNextBonus)) {
            $incomeWithBonus = PlayerIncomeCalculator::calculate($player, $state->board);
            $incomeWithBonus->scholars = min($incomeWithBonus->scholars, $player->scholarPoolSize);
            $score += $this->roundBonusIncomeScore($incomeWithBonus, $state, $player) - $this->roundBonusIncomeScore($income, $state, $player);
        } elseif (in_array($state->round->phase, [GamePhase::Actions, GamePhase::Income], true)) {
            $score += $this->nextRoundBonusPotential($state, $player, $income);
        }

        return $score + $this->scienceBonusEvaluator->execute($state, $player);
    }

    private function nextRoundBonusPotential(GameStateData $state, GamePlayerStateData $player, IncomeReceiptData $income): int
    {
        $forecastState = clone $state;
        $forecastState->round = clone $state->round;
        $forecastState->round->phase = GamePhase::ScienceBonus;
        $bestScore = 0;
        $baseIncomeScore = $this->roundBonusIncomeScore($income, $state, $player);

        foreach ($state->setupPool->availableRoundBonuses ?? [] as $offer) {
            $forecastPlayer = clone $player;
            $forecastPlayer->roundBonus = $offer->roundBonus;
            $bonusIncome = PlayerIncomeCalculator::calculate($forecastPlayer, $state->board);
            $bonusIncome->scholars = min($bonusIncome->scholars, $player->scholarPoolSize);
            $bestScore = max($bestScore, $this->roundBonusIncomeScore($bonusIncome, $state, $player) - $baseIncomeScore
                + $this->roundBonusOpportunityScore($forecastState, $forecastPlayer));
        }

        return $bestScore;
    }

    private function roundBonusOpportunityScore(GameStateData $state, GamePlayerStateData $player): int
    {
        if (! in_array($player->roundBonus, [RoundBonus::PassPalaceUniversity, RoundBonus::SendScholar], true)
            || $state->round->phase === GamePhase::Finished) {
            return 0;
        }

        $hasPassed = in_array($player->playerId, $state->passedPlayerIds, true);
        $isChoosingNextBonus = $state->pendingInteraction?->type === PendingInteractionType::ChooseRoundBonus
            && $state->pendingInteraction->playerId === $player->playerId;
        $currentBonusAvailable = ! $hasPassed
            && ! in_array($state->round->phase, [GamePhase::Setup, GamePhase::ScienceBonus], true);
        $nextBonusAvailable = $state->round->phase === GamePhase::Setup
            || ($state->round->number < 6
                && ($state->round->phase === GamePhase::ScienceBonus || ($hasPassed && ! $isChoosingNextBonus)));

        if (! $currentBonusAvailable && ! $nextBonusAvailable) {
            return 0;
        }

        if ($player->roundBonus === RoundBonus::SendScholar) {
            $scholars = $currentBonusAvailable ? $player->resources->scholars : min(
                $player->scholarPoolSize,
                $player->resources->scholars + PlayerIncomeCalculator::calculate($player, $state->board)->scholars,
            );

            return $scholars * 2 * self::VICTORY_POINT_WEIGHT;
        }

        $buildingCount = count(array_filter(
            $state->board->hexes,
            static fn (BoardHexStateData $hex): bool => $hex->building?->ownerPlayerId === $player->playerId
                && in_array($hex->building->type, [BuildingType::Palace, BuildingType::University], true),
        ));

        return $player->roundBonus->passVictoryPoints($buildingCount) * self::VICTORY_POINT_WEIGHT;
    }

    private function roundBonusIncomeScore(IncomeReceiptData $income, GameStateData $state, GamePlayerStateData $player): int
    {
        $incomeState = clone $state;
        $incomeState->round = clone $state->round;
        $incomeState->round->number = $state->round->phase === GamePhase::Setup ? 1 : $state->round->number + 1;
        $incomeState->round->phase = GamePhase::Income;
        $workingCoins = min($income->coins, max(0, self::COIN_WORKING_RESERVE - $player->resources->coins));
        $scholars = min($income->scholars, $this->scholarIncomeCapacity($state, $player));

        return $income->victoryPoints * self::VICTORY_POINT_WEIGHT
            + $workingCoins * $this->resourceWeight($incomeState, self::COIN_WEIGHT, 2)
            + ($income->coins - $workingCoins) * 2
            + $income->tools * $this->resourceWeight($incomeState, self::TOOL_WEIGHT, 2)
            + $scholars * $this->resourceWeight($incomeState, self::SCHOLAR_WEIGHT, 2)
            + $income->books * $this->resourceWeight($incomeState, self::BOOK_WEIGHT, 2)
            + $income->power * $this->resourceWeight($incomeState, self::BOWL_TWO_POWER_WEIGHT, 1)
            + $income->knowledgeSteps * $this->resourceWeight($incomeState, self::KNOWLEDGE_STEP_WEIGHT, 0);
    }

    private function incomeScore(IncomeReceiptData $income, GameStateData $state): int
    {
        return $this->incomeValueEvaluator->execute($income, $state->round->number);
    }

    private function scholarIncomeCapacity(GameStateData $state, GamePlayerStateData $player): int
    {
        return in_array($state->round->phase, [GamePhase::Setup, GamePhase::ScienceBonus], true)
            || in_array($player->playerId, $state->passedPlayerIds, true)
                ? max(0, $player->scholarPoolSize - $player->resources->scholars)
                : $player->scholarPoolSize;
    }
}
