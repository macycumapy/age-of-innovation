<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Research\Actions;

use App\Domain\GameEngine\Board\Actions\CreateNeutralBuildingInteractionAction;
use App\Domain\GameEngine\Board\Actions\FindEligibleTerraformHexesAction;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Interactions\Data\PendingInteractionData;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\Research\Data\ChooseCompetencyResultData;
use App\Domain\GameEngine\Research\Enums\Competency;
use App\Domain\GameEngine\Setup\Actions\ResolveCompletedStartingSetupAction;
use App\Domain\GameEngine\Setup\Services\StartingBuildingOrderFinder;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Towns\Actions\CreateTownChoiceAfterBuildingAction;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use Illuminate\Validation\ValidationException;

final class ApplyChooseCompetencyAction
{
    public function __construct(
        private CreateNeutralBuildingInteractionAction $createNeutralBuildingInteraction,
        private CreateTownChoiceAfterBuildingAction $createTownChoiceAfterBuilding,
        private FindEligibleTerraformHexesAction $findEligibleTerraformHexes,
        private GrantCompetencyAction $grantCompetency,
        private StartingBuildingOrderFinder $startingBuildingOrderFinder,
        private ResolveCompletedStartingSetupAction $resolveCompletedStartingSetup,
    ) {
    }

    public function execute(GameStateData $state, GamePlayerStateData $player, Competency $competency): ChooseCompetencyResultData
    {
        $interaction = $state->pendingInteraction;
        $reason = $interaction?->context['reason'] ?? null;
        $isStartingCompetency = $state->round->phase === GamePhase::Setup
            && $interaction?->type === PendingInteractionType::ChooseCompetency
            && $interaction->playerId === $player->playerId
            && in_array($player->faction, [Faction::Monks, Faction::Inventors], true)
            && $reason === null;
        if ($interaction?->type !== PendingInteractionType::ChooseCompetency
            || $interaction->playerId !== $player->playerId
            || (! $isStartingCompetency && ! in_array($reason, ['building', 'innovation'], true))
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
        $powerOffersResolved = ($interaction->context['powerOffersResolved'] ?? false) === true;

        if ($isStartingCompetency) {
            return $this->continueStartingSetup($state, $player, $competency, $knowledgeAdvance->gainedPower, $knowledgeAdvance->victoryPoints);
        }

        $awaitsTerraforming = $competency === Competency::Competency05
            && $this->createTerraformingInteraction($state, $player, $builtHexId, $powerOffersResolved);
        $awaitsTowerPlacement = $competency === Competency::Competency10
            && $this->createNeutralBuildingInteraction->execute(
                $state,
                $player,
                BuildingType::Tower,
                [
                    'competency' => $competency->value,
                    'source' => 'competency',
                    'queuedBuiltHexIds' => $reason === 'building' && ! $powerOffersResolved ? [$builtHexId] : [],
                    'queuedTownHexIds' => $reason === 'building' && $powerOffersResolved ? [$builtHexId] : [],
                ],
            );
        $nextActivePlayerId = $awaitsTerraforming || $awaitsTowerPlacement
            ? $player->playerId
            : ($reason === 'building'
                ? $this->createTownChoiceAfterBuilding->execute($state, $player, $builtHexId, powerOffersResolved: $powerOffersResolved)
                : $player->playerId);

        return new ChooseCompetencyResultData(
            $nextActivePlayerId,
            $state->round->phase,
            $reason,
            $builtHexId,
            $knowledgeAdvance->gainedPower,
            $knowledgeAdvance->victoryPoints,
        );
    }

    private function continueStartingSetup(
        GameStateData $state,
        GamePlayerStateData $player,
        Competency $competency,
        int $gainedPower,
        int $victoryPoints,
    ): ChooseCompetencyResultData {
        $placementOrder = $this->startingBuildingOrderFinder->execute($state);
        $hasRemainingPlacements = $state->startingBuildingTurnIndex < count($placementOrder);
        $nextPhase = GamePhase::Setup;
        $incomeReceipts = [];

        if ($competency === Competency::Competency05
            && $this->createStartingTerraformingInteraction($state, $player, $hasRemainingPlacements)) {
            $nextActivePlayerId = $player->playerId;
        } elseif ($competency === Competency::Competency10
            && $this->createNeutralBuildingInteraction->execute(
                $state,
                $player,
                BuildingType::Tower,
                [
                    'competency' => $competency->value,
                    'source' => 'competency',
                    'reason' => 'starting_competency',
                ],
            )) {
            $nextActivePlayerId = $player->playerId;
        } elseif ($hasRemainingPlacements) {
            $nextActivePlayerId = $placementOrder[$state->startingBuildingTurnIndex];
        } else {
            $resolution = $this->resolveCompletedStartingSetup->execute($state);
            $nextActivePlayerId = $resolution->nextActivePlayerId;
            $nextPhase = $resolution->phase;
            $incomeReceipts = $resolution->incomeReceipts;
        }

        return new ChooseCompetencyResultData(
            $nextActivePlayerId,
            $nextPhase,
            'starting',
            '',
            $gainedPower,
            $victoryPoints,
            $incomeReceipts,
        );
    }

    private function createStartingTerraformingInteraction(
        GameStateData $state,
        GamePlayerStateData $player,
        bool $resumeStartingBuildingPlacement,
    ): bool {
        $eligibleHexIds = $this->findEligibleTerraformHexes->execute($state, $player, $player->homeland);
        if ($eligibleHexIds === []) {
            return false;
        }

        $state->pendingInteraction = new PendingInteractionData(
            PendingInteractionType::SpendSpades,
            $player->playerId,
            $eligibleHexIds,
            [
                'phase' => GamePhase::Setup->value,
                'spadeCount' => 2,
                'remainingSpades' => 2,
                'targetTerrain' => $player->homeland->value,
                ...($resumeStartingBuildingPlacement ? ['resumeStartingBuildingPlacement' => true] : []),
            ],
        );

        return true;
    }

    private function createTerraformingInteraction(GameStateData $state, GamePlayerStateData $player, string $builtHexId, bool $powerOffersResolved): bool
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
                'builtHexId' => $builtHexId,
                'powerOffersResolved' => $powerOffersResolved,
            ],
        );

        return true;
    }
}
