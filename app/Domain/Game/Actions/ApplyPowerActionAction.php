<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Enums\PowerAction;
use Illuminate\Validation\ValidationException;

final class ApplyPowerActionAction
{
    public function __construct(private FindEligibleTerraformHexesAction $findEligibleTerraformHexes)
    {
    }

    public function execute(
        GameStateData $state,
        GamePlayerStateData $playerState,
        PowerAction $action,
        int $sacrificeAmount,
    ): void {
        if ($action === PowerAction::BuildBridge) {
            throw ValidationException::withMessages([
                'action' => 'Сначала необходимо добавить выбор позиции моста.',
            ]);
        }

        if (in_array($action->value, $state->round->usedSharedActionIds, true)) {
            throw ValidationException::withMessages(['action' => 'Это действие Силы уже использовано.']);
        }

        if ($action === PowerAction::GainScholar
            && $playerState->resources->scholars >= $playerState->scholarPoolSize) {
            throw ValidationException::withMessages(['action' => 'В пуле игрока нет доступной фигурки учёного.']);
        }

        $requiredSacrifice = max(0, $action->cost() - $playerState->resources->power->bowlThree);

        if ($sacrificeAmount !== $requiredSacrifice
            || $sacrificeAmount * 2 > $playerState->resources->power->bowlTwo) {
            throw ValidationException::withMessages([
                'sacrifice_amount' => 'Недостаточно Силы для выполнения действия.',
            ]);
        }

        $playerState->resources->power->bowlTwo -= $sacrificeAmount * 2;
        $playerState->resources->power->bowlThree += $sacrificeAmount;
        $playerState->resources->power->bowlThree -= $action->cost();
        $playerState->resources->power->bowlOne += $action->cost();

        match ($action) {
            PowerAction::GainScholar => $playerState->resources->scholars++,
            PowerAction::GainTools => $playerState->resources->tools += 2,
            PowerAction::GainCoins => $playerState->resources->coins += 7,
            PowerAction::TerraformOneSpade => $playerState->unassignedSpades++,
            PowerAction::TerraformTwoSpades => $playerState->unassignedSpades += 2,
            PowerAction::BuildBridge => null,
        };

        $spadeCount = match ($action) {
            PowerAction::TerraformOneSpade => 1,
            PowerAction::TerraformTwoSpades => 2,
            default => 0,
        };

        if ($spadeCount > 0) {
            $eligibleHexIds = $this->findEligibleTerraformHexes->execute(
                $state,
                $playerState->playerId,
                $playerState->homeland,
            );

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
