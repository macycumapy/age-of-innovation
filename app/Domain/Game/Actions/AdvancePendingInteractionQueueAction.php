<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PendingInteractionType;

final class AdvancePendingInteractionQueueAction
{
    public function __construct(
        private FindEligibleTerraformHexesAction $findEligibleTerraformHexes,
        private FindEligibleBridgePairsAction $findEligibleBridgePairs,
        private CreateTownChoiceAfterBuildingAction $createTownChoiceAfterBuilding,
    ) {
    }

    public function execute(GameStateData $state, GamePlayerStateData $player, string $builtHexId): int
    {
        $state->pendingInteraction = null;

        while ($state->pendingInteractionQueue !== []) {
            $step = array_shift($state->pendingInteractionQueue);

            if ($step->playerId !== $player->playerId) {
                continue;
            }

            if ($step->type === PendingInteractionType::SpendSpades) {
                $step->optionIds = $this->findEligibleTerraformHexes->execute($state, $player, $player->homeland);
                $step->context = [
                    ...$step->context,
                    'phase' => GamePhase::Actions->value,
                    'spadeCount' => 2,
                    'remainingSpades' => 2,
                    'targetTerrain' => $player->homeland->value,
                ];
            } elseif ($step->type === PendingInteractionType::PlaceBridge) {
                $pairs = $this->findEligibleBridgePairs->execute($state, $player->playerId);
                $step->optionIds = array_values(array_unique(array_merge(
                    array_column($pairs, 'fromHexId'),
                    array_column($pairs, 'toHexId'),
                )));
                $step->context = [...$step->context, 'pairs' => $pairs, 'source' => 'palace_15'];
            }

            if ($step->type === PendingInteractionType::ChoosePalaceBooks || $step->optionIds !== []) {
                $state->pendingInteraction = $step;

                return $player->playerId;
            }
        }

        return $this->createTownChoiceAfterBuilding->execute($state, $player, $builtHexId);
    }
}
