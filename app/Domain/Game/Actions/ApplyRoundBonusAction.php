<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Enums\RoundBonus;
use Illuminate\Validation\ValidationException;

final class ApplyRoundBonusAction
{
    public function __construct(
        private AdvanceKnowledgeAction $advanceKnowledge,
        private CreateBridgeInteractionAction $createBridgeInteraction,
        private FindEligibleTerraformHexesAction $findEligibleTerraformHexes,
    ) {
    }

    public function execute(
        GameStateData $state,
        GamePlayerStateData $playerState,
        ?KnowledgeDiscipline $discipline,
    ): int {
        $roundBonus = $playerState->roundBonus;
        $gainedPower = 0;

        if (! $roundBonus->hasAvailableSpecialAction()
            || in_array($roundBonus->value, $playerState->usedSpecialActionIds, true)) {
            throw ValidationException::withMessages(['round_bonus' => 'Действие этого бонуса раунда недоступно.']);
        }

        if ($roundBonus === RoundBonus::Knowledge) {
            if ($discipline === null) {
                throw ValidationException::withMessages(['discipline' => 'Выберите дисциплину знаний.']);
            }

            $gainedPower = $this->advanceKnowledge->execute($state, $playerState, $discipline, 1);
        }

        if ($roundBonus === RoundBonus::Spade) {
            $playerState->unassignedSpades++;
            $eligibleHexIds = $this->findEligibleTerraformHexes->execute(
                $state,
                $playerState,
                $playerState->homeland,
            );

            if ($eligibleHexIds !== []) {
                $state->pendingInteraction = new PendingInteractionData(
                    PendingInteractionType::SpendSpades,
                    $playerState->playerId,
                    $eligibleHexIds,
                    [
                        'phase' => GamePhase::Actions->value,
                        'spadeCount' => 1,
                        'remainingSpades' => 1,
                        'targetTerrain' => $playerState->homeland->value,
                    ],
                );
            }
        }

        if ($roundBonus === RoundBonus::Bridge) {
            $this->createBridgeInteraction->execute($state, $playerState);
        }

        $playerState->usedSpecialActionIds[] = $roundBonus->value;
        $state->round->hasTakenMainAction = true;

        return $gainedPower;
    }
}
