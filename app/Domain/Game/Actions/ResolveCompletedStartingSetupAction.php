<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Enums\Competency;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Models\GamePlayer;
use Illuminate\Database\Eloquent\Collection;

final class ResolveCompletedStartingSetupAction
{
    public function __construct(
        private CreateDesertStartingSpadeInteractionAction $createDesertStartingSpadeInteraction,
        private FindEligibleTerraformHexesAction $findEligibleTerraformHexes,
        private ResolveIncomePhaseAction $resolveIncomePhase,
    ) {
    }

    /**
     * @param Collection<int, GamePlayer> $players
     * @return array{GamePlayer, GamePhase, list<array{player_id: int, tools: int, coins: int, scholars: int, power: int, books: int, knowledge_steps: int}>}
     */
    public function execute(GameStateData $state, Collection $players): array
    {
        foreach ($players as $competencyPlayer) {
            $competencyPlayerState = collect($state->players)->firstWhere('playerId', $competencyPlayer->id);

            if ($competencyPlayerState?->unassignedSpades >= 2
                && in_array(Competency::Competency05->value, $competencyPlayerState->competencyIds, true)) {
                $eligibleHexIds = $this->findEligibleTerraformHexes->execute(
                    $state,
                    $competencyPlayerState,
                    $competencyPlayerState->homeland,
                );

                if ($eligibleHexIds !== []) {
                    $state->pendingInteraction = new PendingInteractionData(
                        PendingInteractionType::SpendSpades,
                        $competencyPlayer->id,
                        $eligibleHexIds,
                        [
                            'spadeCount' => 2,
                            'remainingSpades' => 2,
                            'targetTerrain' => $competencyPlayerState->homeland->value,
                        ],
                    );

                    return [$competencyPlayer, GamePhase::Setup, []];
                }
            }
        }

        foreach ($players as $desertPlayer) {
            $desertPlayerState = collect($state->players)->firstWhere('playerId', $desertPlayer->id);

            if ($desertPlayerState !== null
                && $this->createDesertStartingSpadeInteraction->execute($state, $desertPlayerState)) {
                return [$desertPlayer, GamePhase::Setup, []];
            }
        }

        $state->round->phase = GamePhase::Income;
        $state->round->incomeTurnIndex = 0;
        $state->round->incomeOrder = [];
        $state->round->incomeReceipts = [];

        return $this->resolveIncomePhase->execute($state, $players);
    }
}
