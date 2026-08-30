<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\Competency;
use App\Domain\Game\Enums\FinalRoundScoringTile;
use App\Domain\Game\Enums\PalaceAbility;
use App\Domain\Game\Enums\RoundScoringTile;

final class ApplyBuildingBonusesAction
{
    /** @return array{victoryPoints: int, coins: int, sources: list<array{source: string, id: string, points: int}>} */
    public function execute(
        GameStateData $state,
        GamePlayerStateData $playerState,
        BoardHexStateData $hex,
        BuildingType $buildingType,
    ): array {
        $isEdgeHex = in_array($hex->id, $state->board->edgeHexIds, true);
        $isRiverBank = in_array($hex->id, $state->board->riverBankHexIds, true);
        $sources = [];

        $roundScoringTile = RoundScoringTile::tryFrom((string) $state->round->scoringTileId);

        if ($roundScoringTile !== null) {
            $this->addScoringSource(
                $sources,
                'round_scoring',
                $roundScoringTile->value,
                $roundScoringTile->buildingVictoryPoints($buildingType),
            );
        }

        if ($state->round->number === 6) {
            $finalRoundScoringTile = FinalRoundScoringTile::tryFrom((string) $state->round->additionalScoringTileId);

            if ($finalRoundScoringTile !== null) {
                $this->addScoringSource(
                    $sources,
                    'additional_round_scoring',
                    $finalRoundScoringTile->value,
                    $finalRoundScoringTile->buildingVictoryPoints($buildingType, $isEdgeHex),
                );
            }
        }

        $this->addScoringSource(
            $sources,
            'round_bonus',
            $playerState->roundBonus->value,
            $playerState->roundBonus->buildingVictoryPoints($buildingType, $isRiverBank),
        );

        foreach ($playerState->competencyIds as $competencyId) {
            $competency = Competency::tryFrom($competencyId);

            if ($competency !== null) {
                $this->addScoringSource(
                    $sources,
                    'competency',
                    $competency->value,
                    $competency->buildingVictoryPoints($buildingType, $isEdgeHex),
                );
            }
        }

        $palaceAbility = PalaceAbility::tryFrom((string) $playerState->palaceId);

        if ($palaceAbility !== null) {
            $this->addScoringSource(
                $sources,
                'palace',
                $palaceAbility->value,
                $palaceAbility->buildingVictoryPoints($buildingType),
            );
        }

        $factionRewards = $playerState->faction->buildingRewards($buildingType, $isRiverBank);

        $this->addScoringSource(
            $sources,
            'faction',
            $playerState->faction->value,
            $factionRewards['victoryPoints'],
        );

        $victoryPoints = array_sum(array_column($sources, 'points'));
        $playerState->victoryPoints += $victoryPoints;
        $playerState->resources->coins += $factionRewards['coins'];

        return [
            'victoryPoints' => $victoryPoints,
            'coins' => $factionRewards['coins'],
            'sources' => $sources,
        ];
    }

    /**
     * @param list<array{source: string, id: string, points: int}> $sources
     */
    private function addScoringSource(array &$sources, string $source, string $id, int $points): void
    {
        if ($points > 0) {
            $sources[] = [
                'source' => $source,
                'id' => $id,
                'points' => $points,
            ];
        }
    }
}
