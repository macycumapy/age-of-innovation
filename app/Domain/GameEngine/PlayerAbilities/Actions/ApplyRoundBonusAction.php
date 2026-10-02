<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\PlayerAbilities\Actions;

use App\Domain\GameEngine\Board\Actions\CreateBridgeInteractionAction;
use App\Domain\GameEngine\Board\Actions\FindEligibleTerraformHexesAction;
use App\Domain\GameEngine\Interactions\Data\PendingInteractionData;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\PlayerAbilities\Data\RoundBonusActionResultData;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Research\Actions\AdvanceKnowledgeAction;
use App\Domain\GameEngine\Research\Enums\KnowledgeDiscipline;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
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
    ): RoundBonusActionResultData {
        $roundBonus = $playerState->roundBonus;
        $gainedPower = 0;
        $victoryPoints = 0;

        if (! $roundBonus->hasAvailableSpecialAction()
            || in_array($roundBonus->value, $playerState->usedSpecialActionIds, true)) {
            throw ValidationException::withMessages(['round_bonus' => 'Действие этого бонуса раунда недоступно.']);
        }

        if ($roundBonus === RoundBonus::Knowledge) {
            if ($discipline === null) {
                throw ValidationException::withMessages(['discipline' => 'Выберите дисциплину знаний.']);
            }

            $knowledgeAdvance = $this->advanceKnowledge->execute($state, $playerState, $discipline, 1);
            $gainedPower = $knowledgeAdvance->gainedPower;
            $victoryPoints = $knowledgeAdvance->victoryPoints;
            $playerState->victoryPoints += $victoryPoints;
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

        return new RoundBonusActionResultData($gainedPower, $victoryPoints);
    }
}
