<?php

declare(strict_types=1);

namespace App\Domain\Automation\Evaluation\Services;

use App\Domain\GameEngine\Board\Actions\FindEligibleTerraformHexesAction;
use App\Domain\GameEngine\Economy\Services\PlayerIncomeCalculator;
use App\Domain\GameEngine\Scoring\Enums\RoundScoringTile;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;

final class ScienceBonusEvaluator
{
    public function __construct(
        private FindEligibleTerraformHexesAction $findEligibleTerraformHexes,
        private IncomeValueEvaluator $incomeValueEvaluator,
    ) {
    }

    public function execute(GameStateData $state, GamePlayerStateData $player): int
    {
        if ($state->setupPool === null || $state->round->phase === GamePhase::Finished
            || $state->round->number >= 6) {
            return 0;
        }

        $currentBonusApplied = $state->round->phase === GamePhase::ScienceBonus
            && collect($state->round->scienceBonusReceipts)->contains(
                static fn (array $receipt): bool => ($receipt['round'] ?? null) === $state->round->number
                    && ($receipt['player_id'] ?? null) === $player->playerId,
            );
        $hasTerraformingTarget = null;
        $baseIncome = PlayerIncomeCalculator::calculate($player, $state->board, includeRoundBonus: false);
        $score = 0;

        for ($roundNumber = max(1, $state->round->number); $roundNumber < 6; $roundNumber++) {
            if ($roundNumber === $state->round->number && $currentBonusApplied) {
                continue;
            }

            $tile = $this->roundTile($state, $roundNumber);
            if (! $tile instanceof RoundScoringTile) {
                continue;
            }

            $knowledgeLevel = $player->faction->scienceBonusKnowledgeLevel(
                $player->knowledge->{$tile->knowledgeDiscipline()->value},
            );
            $reward = $tile->scienceBonus($knowledgeLevel);
            $scholarCount = $this->scholarReward($state, $player, $roundNumber);
            $income = clone $baseIncome;
            $income->scholars = min($income->scholars, max(0, $player->scholarPoolSize - $scholarCount));
            $income->power = min($income->power, $this->powerIncomeCapacity($state, $player, $roundNumber + 1));
            $combinedIncome = clone $income;
            $combinedIncome->coins += $reward['coins'];
            $combinedIncome->tools += $reward['tools'];
            $combinedIncome->scholars += $scholarCount;
            $combinedIncome->books += $reward['books'];
            $combinedIncome->power += $this->powerReward($state, $player, $roundNumber);
            $score += $this->incomeValueEvaluator->execute($combinedIncome, $roundNumber + 1)
                - $this->incomeValueEvaluator->execute($income, $roundNumber + 1);

            if ($reward['spades'] > 0) {
                $hasTerraformingTarget ??= $this->findEligibleTerraformHexes->execute($state, $player, $player->homeland) !== [];
                $score += $hasTerraformingTarget ? $reward['spades'] * 30 : 0;
            }
        }

        return $score;
    }

    public function scholarReward(GameStateData $state, GamePlayerStateData $player, int $roundNumber): int
    {
        if ($roundNumber < 1 || $roundNumber >= 6 || $state->setupPool === null || $state->round->phase === GamePhase::Finished) {
            return 0;
        }
        if ($roundNumber === $state->round->number && $state->round->phase === GamePhase::ScienceBonus
            && collect($state->round->scienceBonusReceipts)->contains(
                static fn (array $receipt): bool => ($receipt['round'] ?? null) === $roundNumber
                    && ($receipt['player_id'] ?? null) === $player->playerId,
            )) {
            return 0;
        }
        $tile = $this->roundTile($state, $roundNumber);
        if ($tile === null) {
            return 0;
        }
        $knowledgeLevel = $player->faction->scienceBonusKnowledgeLevel($player->knowledge->{$tile->knowledgeDiscipline()->value});
        $capacity = $roundNumber === $state->round->number
            ? max(0, $player->scholarPoolSize - $player->resources->scholars)
            : $player->scholarPoolSize;

        return min($tile->scienceBonus($knowledgeLevel)['scholars'], $capacity);
    }

    public function powerReward(GameStateData $state, GamePlayerStateData $player, int $roundNumber): int
    {
        if ($roundNumber < 1 || $roundNumber >= 6 || $state->setupPool === null || $state->round->phase === GamePhase::Finished) {
            return 0;
        }
        if ($roundNumber === $state->round->number && $state->round->phase === GamePhase::ScienceBonus
            && collect($state->round->scienceBonusReceipts)->contains(
                static fn (array $receipt): bool => ($receipt['round'] ?? null) === $roundNumber
                    && ($receipt['player_id'] ?? null) === $player->playerId,
            )) {
            return 0;
        }
        $tile = $this->roundTile($state, $roundNumber);
        if ($tile === null) {
            return 0;
        }
        $knowledgeLevel = $player->faction->scienceBonusKnowledgeLevel($player->knowledge->{$tile->knowledgeDiscipline()->value});
        $capacity = $this->powerCapacity($player, $roundNumber === $state->round->number
            && ($state->round->phase === GamePhase::ScienceBonus || in_array($player->playerId, $state->passedPlayerIds, true)));

        return min($tile->scienceBonus($knowledgeLevel)['power'], $capacity);
    }

    public function powerIncomeCapacity(GameStateData $state, GamePlayerStateData $player, int $incomeRound): int
    {
        $immediateIncomeRound = $state->round->phase === GamePhase::Setup ? 1 : $state->round->number + 1;
        $capacity = $this->powerCapacity($player, $incomeRound === $immediateIncomeRound
            && (in_array($state->round->phase, [GamePhase::Setup, GamePhase::ScienceBonus], true)
                || in_array($player->playerId, $state->passedPlayerIds, true)));

        return max(0, $capacity - $this->powerReward($state, $player, $incomeRound - 1));
    }

    private function powerCapacity(GamePlayerStateData $player, bool $useCurrentBowls): int
    {
        $power = $player->resources->power;

        return $useCurrentBowls ? $power->bowlOne * 2 + $power->bowlTwo
            : ($power->bowlOne + $power->bowlTwo + $power->bowlThree) * 2;
    }

    private function roundTile(GameStateData $state, int $roundNumber): ?RoundScoringTile
    {
        $tile = $roundNumber === $state->round->number && $state->round->scoringTileId !== null
            ? RoundScoringTile::tryFrom($state->round->scoringTileId)
            : $state->setupPool?->roundScoringTiles[$roundNumber - 1] ?? null;

        return is_string($tile) ? RoundScoringTile::tryFrom($tile) : $tile;
    }

}
