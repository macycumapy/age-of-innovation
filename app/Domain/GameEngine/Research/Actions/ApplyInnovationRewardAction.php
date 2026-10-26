<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Research\Actions;

use App\Domain\GameEngine\Research\Enums\Innovation;
use App\Domain\GameEngine\Research\Enums\KnowledgeDiscipline;
use App\Domain\GameEngine\Research\Services\InnovationVictoryPointCalculator;
use App\Domain\GameEngine\Scoring\Actions\ApplyDevelopmentTrackRoundScoringAction;
use App\Domain\GameEngine\Scoring\Enums\VictoryPointSource;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;

class ApplyInnovationRewardAction
{
    public function __construct(
        private AdvanceKnowledgeAction $advanceKnowledge,
        private AdvanceDevelopmentTrackAction $advanceDevelopmentTrack,
        private ApplyDevelopmentTrackRoundScoringAction $applyDevelopmentTrackRoundScoring,
        private InnovationVictoryPointCalculator $innovationVictoryPointCalculator,
    ) {
    }

    /** @return array{victoryPoints: int, scholars: int, power: int, books: int, developmentTrackBooks: int, knowledgeSteps: int, shippingSteps: int, terraformingSteps: int, gainedPower: int} */
    public function execute(
        GameStateData $state,
        GamePlayerStateData $player,
        Innovation $innovation,
    ): array {
        $innovationVictoryPoints = $this->innovationVictoryPointCalculator->execute($state, $player, $innovation);
        $reward = [
            'victoryPoints' => $innovationVictoryPoints,
            'scholars' => 0,
            'power' => 0,
            'books' => 0,
            'developmentTrackBooks' => 0,
            'knowledgeSteps' => 0,
            'shippingSteps' => 0,
            'terraformingSteps' => 0,
            'gainedPower' => 0,
        ];

        if ($innovation === Innovation::DeusExMachina) {
            $player->resources->books->unassigned++;
            $reward['books'] = 1;

            foreach (KnowledgeDiscipline::cases() as $discipline) {
                $knowledgeAdvance = $this->advanceKnowledge->execute($state, $player, $discipline, 1);
                $reward['gainedPower'] += $knowledgeAdvance->gainedPower;
                $reward['knowledgeSteps'] += $knowledgeAdvance->advancedSteps;
                $reward['victoryPoints'] += $knowledgeAdvance->victoryPoints;
                $player->addVictoryPoints($knowledgeAdvance->victoryPoints, VictoryPointSource::RoundScoring);
            }
        }

        if ($innovation === Innovation::Architecture) {
            $reward['knowledgeSteps'] = $this->distinctBuildingTypeCount($state, $player);
            $player->knowledge->unassignedSteps += $reward['knowledgeSteps'];
        }

        if ($innovation === Innovation::SteamEngine) {
            $scholarsBefore = $player->resources->scholars;
            $player->resources->scholars = min($player->scholarPoolSize, $scholarsBefore + 1);
            $reward['scholars'] = $player->resources->scholars - $scholarsBefore;
            $shippingReward = $this->advanceDevelopmentTrack->advanceShipping($player);
            $terraformingReward = $this->advanceDevelopmentTrack->advanceTerraforming($player);
            $reward['shippingSteps'] = $shippingReward['steps'];
            $reward['terraformingSteps'] = $terraformingReward['steps'];
            $reward['developmentTrackBooks'] = $shippingReward['books'] + $terraformingReward['books'];
            $reward['books'] += $reward['developmentTrackBooks'];
            $reward['victoryPoints'] += $shippingReward['victoryPoints'] + $terraformingReward['victoryPoints'];
            $reward['victoryPoints'] += $this->applyDevelopmentTrackRoundScoring->execute(
                $state,
                $player,
                $shippingReward['steps'] + $terraformingReward['steps'],
            );
        }

        if ($innovation === Innovation::Palace) {
            $player->resources->power->bowlThree += 2;
            $reward['power'] = 2;
        }

        $player->addVictoryPoints($innovationVictoryPoints, VictoryPointSource::Innovation);

        return $reward;
    }

    private function distinctBuildingTypeCount(GameStateData $state, GamePlayerStateData $player): int
    {
        $types = [];

        foreach ($state->board->hexes as $hex) {
            if ($hex->building?->ownerPlayerId === $player->playerId) {
                $types[$hex->building->type->value] = true;
            }
        }

        return count($types);
    }

}
