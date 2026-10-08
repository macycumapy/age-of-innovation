<?php

declare(strict_types=1);

namespace App\Domain\Automation\Evaluation\Services;

use App\Domain\GameEngine\Board\Actions\FindEligibleTerraformHexesAction;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Research\Enums\Innovation;
use App\Domain\GameEngine\Research\Services\InnovationVictoryPointCalculator;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;

final class InnovationEvaluator
{
    private const int VICTORY_POINT_WEIGHT = 10;

    private const float FUTURE_ACTION_DISCOUNT = 0.5;

    public function __construct(
        private FindEligibleTerraformHexesAction $findEligibleTerraformHexes,
        private InnovationVictoryPointCalculator $innovationVictoryPointCalculator,
    ) {
    }

    public function execute(GameStateData $state, GamePlayerStateData $player): int
    {
        if ($state->round->phase === GamePhase::Finished) {
            return 0;
        }

        $remainingRounds = $state->round->phase === GamePhase::Setup ? 6 : max(0, 6 - $state->round->number);
        $currentRoundAvailable = $state->round->phase !== GamePhase::Setup
            && $state->round->phase !== GamePhase::ScienceBonus
            && ! in_array($player->playerId, $state->passedPlayerIds, true);
        $guildCount = count(array_filter(
            $state->board->hexes,
            static fn (BoardHexStateData $hex): bool => $hex->building?->ownerPlayerId === $player->playerId
                && $hex->building->type === BuildingType::Guild,
        ));
        $score = 0;

        foreach ($player->inventionIds as $innovationId) {
            $innovation = Innovation::from($innovationId);
            $score += $innovation->passVictoryPoints($guildCount)
                * ($remainingRounds + (int) $currentRoundAvailable) * self::VICTORY_POINT_WEIGHT;
            $hasUnusedAction = $currentRoundAvailable && $innovation->hasSpecialAction()
                && ! in_array($innovation->specialActionId(), $player->usedSpecialActionIds, true);
            $expectedActionUses = (int) $hasUnusedAction + $remainingRounds * self::FUTURE_ACTION_DISCOUNT;

            if ($innovation === Innovation::Professor) {
                if ($hasUnusedAction) {
                    $score += 3 * self::VICTORY_POINT_WEIGHT
                        + ($player->resources->scholars < $player->scholarPoolSize
                            ? $this->resourceWeight(40, 2, $state->round->number) : 0);
                }
                for ($actionRound = 7 - $remainingRounds; $actionRound <= 6; $actionRound++) {
                    $score += 3 * self::VICTORY_POINT_WEIGHT + $this->resourceWeight(40, 2, $actionRound);
                }
            } elseif ($innovation === Innovation::DeusExMachina
                && $this->findEligibleTerraformHexes->execute($state, $player, $player->homeland) !== []) {
                $score += (int) round($expectedActionUses * 60);
            }
        }

        return $score;
    }

    public function purchasePotential(GameStateData $state, GamePlayerStateData $player, Innovation $innovation): int
    {
        $potentialPlayer = clone $player;
        $potentialPlayer->inventionIds = [$innovation->value];
        $remainingRounds = $state->round->phase === GamePhase::Setup ? 6 : max(0, 6 - $state->round->number);
        $score = $this->innovationVictoryPointCalculator->execute($state, $player, $innovation) * self::VICTORY_POINT_WEIGHT
            + $this->execute($state, $potentialPlayer);

        return $score + match ($innovation) {
            Innovation::Workshop => $this->futureResourceIncome(20, 2, $remainingRounds) * 3,
            Innovation::Guild => $this->futureResourceIncome(20, 2, $remainingRounds) * 5,
            Innovation::University => $remainingRounds * 2 * self::VICTORY_POINT_WEIGHT,
            Innovation::Palace => 24 + $this->futureResourceIncome(7, 1, $remainingRounds) * 4,
            Innovation::School => $state->availableCompetencyIds !== [] ? 100 : 0,
            Innovation::DeusExMachina => 25 + 4 * 10,
            Innovation::SteamEngine => 40 + ($player->shippingLevel < 3 ? 50 : 0)
                + ($player->terraformingLevel < 2 ? 50 : 0),
            default => 0,
        };
    }

    private function futureResourceIncome(int $earlyWeight, int $finalWeight, int $remainingRounds): int
    {
        $score = 0;
        for ($incomeRound = 7 - $remainingRounds; $incomeRound <= 6; $incomeRound++) {
            $score += $this->resourceWeight($earlyWeight, $finalWeight, $incomeRound);
        }

        return $score;
    }

    private function resourceWeight(int $earlyWeight, int $finalWeight, int $roundNumber): int
    {
        $remainingRounds = max(0, min(2, 6 - $roundNumber));

        return intdiv($earlyWeight * $remainingRounds + $finalWeight * (2 - $remainingRounds), 2);
    }
}
