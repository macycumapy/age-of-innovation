<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Economy\Actions;

use App\Domain\GameEngine\Board\Actions\CreateBridgeInteractionAction;
use App\Domain\GameEngine\Board\Actions\FindEligibleMoleTunnelHexesAction;
use App\Domain\GameEngine\Board\Actions\FindEligibleTerraformHexesAction;
use App\Domain\GameEngine\Board\Enums\BridgeSource;
use App\Domain\GameEngine\Economy\Enums\PowerAction;
use App\Domain\GameEngine\Interactions\Data\PendingInteractionData;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use Illuminate\Validation\ValidationException;

final class ApplyPowerActionAction
{
    public function __construct(
        private CreateBridgeInteractionAction $createBridgeInteraction,
        private FindEligibleTerraformHexesAction $findEligibleTerraformHexes,
        private FindEligibleMoleTunnelHexesAction $findEligibleMoleTunnelHexes,
    ) {
    }

    public function execute(
        GameStateData $state,
        GamePlayerStateData $playerState,
        PowerAction $action,
        int $sacrificeAmount,
    ): void {
        if (in_array($action->value, $state->round->usedSharedActionIds, true)) {
            throw ValidationException::withMessages(['action' => 'Это действие Силы уже использовано.']);
        }

        if ($action === PowerAction::GainScholar
            && $playerState->resources->scholars >= $playerState->scholarPoolSize) {
            throw ValidationException::withMessages(['action' => 'В пуле игрока нет доступной фигурки учёного.']);
        }

        $cost = $action->cost($playerState->faction);
        $requiredSacrifice = max(0, $cost - $playerState->resources->power->bowlThree);

        if ($sacrificeAmount !== $requiredSacrifice
            || $sacrificeAmount * 2 > $playerState->resources->power->bowlTwo) {
            throw ValidationException::withMessages([
                'sacrifice_amount' => 'Недостаточно Силы для выполнения действия.',
            ]);
        }

        $playerState->resources->power->bowlTwo -= $sacrificeAmount * 2;
        $playerState->resources->power->bowlThree += $sacrificeAmount;
        $playerState->resources->power->bowlThree -= $cost;
        $playerState->resources->power->bowlOne += $cost;
        $playerState->victoryPoints += $action->victoryPoints(
            $playerState->faction,
            $state->setupPool?->playerCount ?? count($state->players),
        );

        match ($action) {
            PowerAction::BuildBridge => $this->createBridgeInteraction->execute($state, $playerState, source: BridgeSource::Power),
            PowerAction::GainScholar => $playerState->resources->scholars++,
            PowerAction::GainTools => $playerState->resources->tools += 2,
            PowerAction::GainCoins => $playerState->resources->coins += 7,
            PowerAction::TerraformOneSpade => $playerState->unassignedSpades++,
            PowerAction::TerraformTwoSpades => $playerState->unassignedSpades += 2,
        };

        $spadeCount = match ($action) {
            PowerAction::TerraformOneSpade => 1,
            PowerAction::TerraformTwoSpades => 2,
            default => 0,
        };

        if ($spadeCount > 0) {
            $eligibleHexIds = $this->findEligibleTerraformHexes->execute(
                $state,
                $playerState,
                $playerState->homeland,
            );
            $eligibleHexIds = array_values(array_unique([
                ...$eligibleHexIds,
                ...$this->findEligibleMoleTunnelHexes->execute($state, $playerState),
            ]));

            if ($eligibleHexIds !== []) {
                $state->pendingInteraction = new PendingInteractionData(
                    PendingInteractionType::SpendSpades,
                    $playerState->playerId,
                    $eligibleHexIds,
                    [
                        'phase' => GamePhase::Actions->value,
                        'spadeCount' => $spadeCount,
                        'remainingSpades' => $spadeCount,
                        'targetTerrain' => $playerState->homeland->value,
                    ],
                );
            }
        }

        $state->round->usedSharedActionIds[] = $action->value;
        $state->round->hasTakenMainAction = true;
    }
}
