<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Board\Services;

use App\Domain\GameEngine\Board\Actions\FindEligibleMoleTunnelHexesAction;
use App\Domain\GameEngine\Board\Actions\FindEligiblePalaceFlightHexesAction;
use App\Domain\GameEngine\Board\Actions\FindEligibleTerraformHexesAction;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\PaidTerraformingOptionData;
use App\Domain\GameEngine\Interactions\Enums\GameActionAvailabilityReason;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;

final class PaidTerraformingOptionFinder
{
    public function __construct(
        private FindEligibleTerraformHexesAction $findEligibleTerraformHexes,
        private FindEligibleMoleTunnelHexesAction $findEligibleMoleTunnelHexes,
        private FindEligiblePalaceFlightHexesAction $findEligiblePalaceFlightHexes,
    ) {
    }

    /**
     * @param list<GameActionAvailabilityReason> $reasons
     * @return list<PaidTerraformingOptionData>
     */
    public function execute(GameStateData $state, GamePlayerStateData $player, array &$reasons = []): array
    {
        return $this->findOptions($state, $player, $reasons);
    }

    public function findMatching(GameStateData $state, GamePlayerStateData $player, PaidTerraformingOptionData $option): ?PaidTerraformingOptionData
    {
        $reasons = [];

        return $this->findOptions($state, $player, $reasons, $option)[0] ?? null;
    }

    /**
     * @param list<GameActionAvailabilityReason> $reasons
     * @return list<PaidTerraformingOptionData>
     */
    private function findOptions(GameStateData $state, GamePlayerStateData $player, array &$reasons, ?PaidTerraformingOptionData $selected = null): array
    {
        $reasons = [];
        $interaction = $state->pendingInteraction;
        $isExistingSpadeInteraction = $interaction?->type === PendingInteractionType::SpendSpades
            && $interaction->playerId === $player->playerId
            && ! isset($interaction->context['selectedHexId']);
        $isAllowedPhase = $state->round->phase->isActionPhase()
            || ($isExistingSpadeInteraction && in_array(
                $state->round->phase,
                [GamePhase::Setup, GamePhase::ScienceBonus],
                true,
            ));

        if (! $isAllowedPhase
            || ($interaction !== null && ! $isExistingSpadeInteraction)
            || ($interaction === null && $state->round->hasTakenMainAction)) {
            $reasons[] = GameActionAvailabilityReason::ActionUnavailable;
            return [];
        }

        $regularHexIds = $this->findEligibleTerraformHexes->execute($state, $player, $player->homeland);
        if ($isExistingSpadeInteraction) {
            $regularHexIds = array_values(array_intersect($interaction->optionIds, $regularHexIds));
        }

        $modes = [
            [false, false, $regularHexIds],
            [true, false, $this->findEligibleMoleTunnelHexes->execute($state, $player)],
            [false, true, $isExistingSpadeInteraction && ($interaction?->context['flightUsed'] ?? false)
                ? []
                : $this->findEligiblePalaceFlightHexes->execute($state, $player)],
        ];
        $options = [];
        $toolCostPerSpade = max(1, 3 - $player->terraformingLevel);
        $hexesById = [];
        foreach ($state->board->hexes as $hex) {
            $hexesById[$hex->id] ??= $hex;
        }

        foreach ($modes as [$useTunnel, $useFlight, $hexIds]) {
            if ($selected !== null && ($selected->useTunnel !== $useTunnel || $selected->useFlight !== $useFlight)) {
                continue;
            }
            foreach ($hexIds as $hexId) {
                if (! is_string($hexId)) {
                    continue;
                }
                if ($selected !== null && $selected->hexId !== $hexId) {
                    continue;
                }

                $hex = $hexesById[$hexId] ?? null;

                if (! $hex instanceof BoardHexStateData) {
                    continue;
                }

                $spadeCount = $hex->terrain->spadesTo($player->homeland);
                $purchasedSpades = max(0, $spadeCount - $player->unassignedSpades);
                $toolCost = ($purchasedSpades * $toolCostPerSpade) + ($useTunnel ? 1 : 0);
                $scholarCost = $useFlight ? 1 : 0;
                if ($spadeCount > 0 && $toolCost > $player->resources->tools) {
                    $reasons[] = GameActionAvailabilityReason::InsufficientTools;
                }
                if ($spadeCount > 0 && $scholarCost > $player->resources->scholars) {
                    $reasons[] = GameActionAvailabilityReason::InsufficientScholars;
                }

                if (($selected === null || ! $selected->useAvailable)
                    && $spadeCount > 0
                    && $toolCost <= $player->resources->tools
                    && $scholarCost <= $player->resources->scholars) {
                    $options[] = new PaidTerraformingOptionData(
                        $hexId,
                        false,
                        $useTunnel,
                        $useFlight,
                        $toolCost,
                        $scholarCost,
                        $spadeCount,
                    );
                }

                $availableToolCost = $useTunnel ? 1 : 0;
                if (($selected === null || $selected->useAvailable)
                    && $isExistingSpadeInteraction
                    && $player->unassignedSpades >= 1
                    && $player->unassignedSpades < $spadeCount
                    && $availableToolCost <= $player->resources->tools
                    && $scholarCost <= $player->resources->scholars) {
                    $options[] = new PaidTerraformingOptionData(
                        $hexId,
                        true,
                        $useTunnel,
                        $useFlight,
                        $availableToolCost,
                        $scholarCost,
                        $spadeCount,
                    );
                }
            }
        }

        $reasons = $options !== [] ? [] : array_values(array_unique($reasons, SORT_REGULAR));
        if ($options === [] && $reasons === []) {
            $reasons[] = GameActionAvailabilityReason::NoReachableTarget;
        }

        return $options;
    }
}
