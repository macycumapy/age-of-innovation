<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Actions\FindEligibleMoleTunnelHexesAction;
use App\Domain\Game\Actions\FindEligiblePalaceFlightHexesAction;
use App\Domain\Game\Actions\FindEligibleTerraformHexesAction;
use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PaidTerraformingOptionData;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PendingInteractionType;

final class PaidTerraformingOptionFinder
{
    public function __construct(
        private FindEligibleTerraformHexesAction $findEligibleTerraformHexes,
        private FindEligibleMoleTunnelHexesAction $findEligibleMoleTunnelHexes,
        private FindEligiblePalaceFlightHexesAction $findEligiblePalaceFlightHexes,
    ) {
    }

    /** @return list<PaidTerraformingOptionData> */
    public function execute(GameStateData $state, GamePlayerStateData $player): array
    {
        $interaction = $state->pendingInteraction;
        $isExistingSpadeInteraction = $interaction?->type === PendingInteractionType::SpendSpades
            && $interaction->playerId === $player->playerId
            && ! isset($interaction->context['selectedHexId']);
        $isAllowedPhase = $state->round->phase === GamePhase::Actions
            || ($isExistingSpadeInteraction && in_array(
                $state->round->phase,
                [GamePhase::Setup, GamePhase::ScienceBonus],
                true,
            ));

        if (! $isAllowedPhase
            || ($interaction !== null && ! $isExistingSpadeInteraction)
            || ($interaction === null && $state->round->hasTakenMainAction)) {
            return [];
        }

        $regularHexIds = $this->findEligibleTerraformHexes->execute($state, $player, $player->homeland);
        if ($isExistingSpadeInteraction) {
            $regularHexIds = array_values(array_intersect($interaction->optionIds, $regularHexIds));
        }

        $modes = [
            [false, false, $regularHexIds],
            [true, false, $isExistingSpadeInteraction && ($interaction?->context['tunnelUsed'] ?? false)
                ? []
                : $this->findEligibleMoleTunnelHexes->execute($state, $player)],
            [false, true, $isExistingSpadeInteraction && ($interaction?->context['flightUsed'] ?? false)
                ? []
                : $this->findEligiblePalaceFlightHexes->execute($state, $player)],
        ];
        $options = [];
        $toolCostPerSpade = max(1, 3 - $player->terraformingLevel);

        foreach ($modes as [$useTunnel, $useFlight, $hexIds]) {
            foreach ($hexIds as $hexId) {
                if (! is_string($hexId)) {
                    continue;
                }

                $hex = collect($state->board->hexes)->firstWhere('id', $hexId);

                if (! $hex instanceof BoardHexStateData) {
                    continue;
                }

                $spadeCount = $hex->terrain->spadesTo($player->homeland);
                $purchasedSpades = max(0, $spadeCount - $player->unassignedSpades);
                $toolCost = ($purchasedSpades * $toolCostPerSpade) + ($useTunnel ? 1 : 0);
                $scholarCost = $useFlight ? 1 : 0;

                if ($spadeCount > 0
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
                if ($isExistingSpadeInteraction
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

        return $options;
    }
}
