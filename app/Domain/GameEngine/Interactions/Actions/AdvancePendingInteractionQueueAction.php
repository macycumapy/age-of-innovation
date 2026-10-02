<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Interactions\Actions;

use App\Domain\GameEngine\Board\Actions\FindEligibleBridgePairsAction;
use App\Domain\GameEngine\Board\Actions\FindEligibleTerraformHexesAction;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Towns\Actions\CreateTownChoiceAfterBuildingAction;
use App\Domain\GameEngine\Turns\Enums\GamePhase;

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
