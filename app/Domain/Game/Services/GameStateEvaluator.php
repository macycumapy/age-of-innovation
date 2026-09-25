<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\IncomeReceiptData;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Enums\RoundBonus;
use InvalidArgumentException;

final class GameStateEvaluator
{
    private const int VICTORY_POINT_WEIGHT = 1000;

    private const int COIN_WEIGHT = 10;

    private const int TOOL_WEIGHT = 30;

    private const int SCHOLAR_WEIGHT = 40;

    private const int BOOK_WEIGHT = 25;

    private const int BOWL_ONE_POWER_WEIGHT = 1;

    private const int BOWL_TWO_POWER_WEIGHT = 4;

    private const int BOWL_THREE_POWER_WEIGHT = 8;

    private const int KNOWLEDGE_STEP_WEIGHT = 30;

    private const int KNOWLEDGE_PROGRESS_WEIGHT = 4;

    private const int DEVELOPMENT_STEP_WEIGHT = 50;

    private const int SPADE_WEIGHT = 30;

    private const int AVAILABLE_ANNEX_WEIGHT = 10;

    private const int TOWN_WEIGHT = 80;

    private const int COMPETENCY_WEIGHT = 50;

    private const int INVENTION_WEIGHT = 70;

    private const int PLACED_SCHOLAR_WEIGHT = 40;

    private const int BUILDING_POWER_WEIGHT = 40;

    private const int PLACED_ANNEX_WEIGHT = 25;

    public function execute(GameStateData $state, int $playerId): int
    {
        $player = collect($state->players)->firstWhere('playerId', $playerId);

        if (! $player instanceof GamePlayerStateData) {
            throw new InvalidArgumentException('Не найдено состояние игрока для оценки.');
        }

        $playerScore = $this->playerScore($state, $player);
        $strongestOpponentScore = collect($state->players)
            ->reject(static fn (GamePlayerStateData $candidate): bool => $candidate->playerId === $playerId)
            ->map(fn (GamePlayerStateData $candidate): int => $this->playerScore($state, $candidate))
            ->max();

        return is_int($strongestOpponentScore)
            ? $playerScore - $strongestOpponentScore
            : $playerScore;
    }

    private function playerScore(GameStateData $state, GamePlayerStateData $player): int
    {
        $books = $player->resources->books;
        $power = $player->resources->power;
        $score = $player->victoryPoints * self::VICTORY_POINT_WEIGHT;
        $score += $player->resources->coins * self::COIN_WEIGHT;
        $score += $player->resources->tools * self::TOOL_WEIGHT;
        $score += $player->resources->scholars * self::SCHOLAR_WEIGHT;
        $score += ($books->banking + $books->law + $books->engineering + $books->medicine + $books->unassigned)
            * self::BOOK_WEIGHT;
        $score += ($power->bowlOne * self::BOWL_ONE_POWER_WEIGHT)
            + ($power->bowlTwo * self::BOWL_TWO_POWER_WEIGHT)
            + ($power->bowlThree * self::BOWL_THREE_POWER_WEIGHT);
        $score += $player->knowledge->unassignedSteps * self::KNOWLEDGE_STEP_WEIGHT;

        foreach (KnowledgeDiscipline::cases() as $discipline) {
            $level = $player->knowledge->{$discipline->value};
            $score += ($level * self::KNOWLEDGE_STEP_WEIGHT)
                + ($level ** 2 * self::KNOWLEDGE_PROGRESS_WEIGHT);
        }

        $score += ($player->shippingLevel + $player->terraformingLevel) * self::DEVELOPMENT_STEP_WEIGHT;
        $score += $player->unassignedSpades * self::SPADE_WEIGHT;
        $score += $player->availableAnnexes * self::AVAILABLE_ANNEX_WEIGHT;
        $score += count($player->townTileIds) * self::TOWN_WEIGHT;
        $score += count($player->competencyIds) * self::COMPETENCY_WEIGHT;
        $score += count($player->inventionIds) * self::INVENTION_WEIGHT;
        $score += count($player->scholarDisciplineIds) * self::PLACED_SCHOLAR_WEIGHT;

        foreach ($state->board->hexes as $hex) {
            if ($hex->building?->ownerPlayerId !== $player->playerId) {
                continue;
            }

            $score += $hex->building->type->powerValue() * self::BUILDING_POWER_WEIGHT;
            $score += $hex->building->hasAnnex ? self::PLACED_ANNEX_WEIGHT : 0;
        }

        $score += $this->futureIncomeScore($state, $player);

        return $score;
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
