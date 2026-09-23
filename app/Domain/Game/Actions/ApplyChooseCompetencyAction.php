<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\ChooseCompetencyResultData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\Competency;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PendingInteractionType;
use Illuminate\Validation\ValidationException;

final class ApplyChooseCompetencyAction
{
    public function __construct(
        private CreateNeutralBuildingInteractionAction $createNeutralBuildingInteraction,
        private CreateTownChoiceAfterBuildingAction $createTownChoiceAfterBuilding,
        private FindEligibleTerraformHexesAction $findEligibleTerraformHexes,
        private GrantCompetencyAction $grantCompetency,
    ) {
    }

    public function execute(GameStateData $state, GamePlayerStateData $player, Competency $competency): ChooseCompetencyResultData
    {
        $interaction = $state->pendingInteraction;
        $reason = $interaction?->context['reason'] ?? null;
        if ($interaction?->type !== PendingInteractionType::ChooseCompetency
            || $interaction->playerId !== $player->playerId
            || ! in_array($reason, ['building', 'innovation'], true)
            || ! in_array($competency->value, $interaction->optionIds, true)) {
            throw ValidationException::withMessages(['competency_id' => 'Эта компетенция недоступна.']);
        }

        $knowledgeAdvance = $this->grantCompetency->execute(
            $state,
            $player,
            $competency,
            $state->setupPool === null ? $state->availableCompetencyIds : $state->setupPool->competencies,
        );
        $player->victoryPoints += $knowledgeAdvance->victoryPoints;
        $state->pendingInteraction = null;
        $builtHexId = (string) ($interaction->context['builtHexId'] ?? '');
        $awaitsTerraforming = $competency === Competency::Competency05
            && $this->createTerraformingInteraction($state, $player);
        $awaitsTowerPlacement = $competency === Competency::Competency10
            && $this->createNeutralBuildingInteraction->execute(
                $state,
                $player,
                BuildingType::Tower,
                [
                    'competency' => $competency->value,
                    'source' => 'competency',
                    'queuedBuiltHexIds' => $reason === 'building' ? [$builtHexId] : [],
                ],
            );
        $nextActiveUserId = $awaitsTerraforming || $awaitsTowerPlacement
            ? $player->userId
            : ($reason === 'building'
                ? $this->createTownChoiceAfterBuilding->execute($state, $player, $builtHexId)
                : $player->userId);

        return new ChooseCompetencyResultData(
            $nextActiveUserId,
            $reason,
            $builtHexId,
            $knowledgeAdvance->gainedPower,
            $knowledgeAdvance->victoryPoints,
        );
    }

    private function createTerraformingInteraction(GameStateData $state, GamePlayerStateData $player): bool
    {
        $eligibleHexIds = $this->findEligibleTerraformHexes->execute($state, $player, $player->homeland);
        if ($eligibleHexIds === []) {
            return false;
        }

        $state->pendingInteraction = new PendingInteractionData(
            PendingInteractionType::SpendSpades,
            $player->playerId,
            $eligibleHexIds,
            [
                'phase' => GamePhase::Actions->value,
                'spadeCount' => 2,
                'remainingSpades' => 2,
                'targetTerrain' => $player->homeland->value,
            ],
        );

        return true;
    }
}
