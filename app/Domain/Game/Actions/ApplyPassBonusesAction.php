<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\Competency;
use App\Domain\Game\Enums\Innovation;
use App\Domain\Game\Enums\PalaceAbility;

final class ApplyPassBonusesAction
{
    /** @return array{victoryPoints: int, sources: list<array{source: string, id: string, points: int}>} */
    public function execute(GameStateData $state, GamePlayerStateData $player): array
    {
        $buildingCounts = $this->buildingCounts($state, $player->playerId);
        $sources = [];
        $this->addSource(
            $sources,
            'round_bonus',
            $player->roundBonus->value,
            $player->roundBonus->passVictoryPoints($buildingCounts['palaceAndUniversity']),
        );

        $weakestKnowledgeLevel = min(
            $player->knowledge->banking,
            $player->knowledge->law,
            $player->knowledge->engineering,
            $player->knowledge->medicine,
        );

        foreach ($player->competencyIds as $competencyId) {
            $competency = Competency::tryFrom($competencyId);

            if ($competency !== null) {
                $this->addSource(
                    $sources,
                    'competency',
                    $competency->value,
                    $competency->passVictoryPoints(count($player->townTileIds), $weakestKnowledgeLevel),
                );
            }
        }

        $palace = PalaceAbility::tryFrom((string) $player->palaceId);

        if ($palace !== null) {
            $this->addSource($sources, 'palace', $palace->value, $palace->passVictoryPoints($buildingCounts['school']));
        }

        foreach ($player->inventionIds as $innovationId) {
            $innovation = Innovation::tryFrom($innovationId);

            if ($innovation !== null) {
                $this->addSource(
                    $sources,
                    'innovation',
                    $innovation->value,
                    $innovation->passVictoryPoints($buildingCounts['guild']),
                );
            }
        }

        $victoryPoints = array_sum(array_column($sources, 'points'));
        $player->victoryPoints += $victoryPoints;

        return ['victoryPoints' => $victoryPoints, 'sources' => $sources];
    }

    /** @return array{palaceAndUniversity: int, school: int, guild: int} */
    private function buildingCounts(GameStateData $state, int $playerId): array
    {
        $buildings = array_filter(
            $state->board->hexes,
            static fn (BoardHexStateData $hex): bool => $hex->building?->ownerPlayerId === $playerId
                && ! $hex->building->isNeutral,
        );

        return [
            'palaceAndUniversity' => count(array_filter(
                $buildings,
                static fn (BoardHexStateData $hex): bool => in_array(
                    $hex->building?->type,
                    [BuildingType::Palace, BuildingType::University],
                    true,
                ),
            )),
            'school' => count(array_filter(
                $buildings,
                static fn (BoardHexStateData $hex): bool => $hex->building?->type === BuildingType::School,
            )),
            'guild' => count(array_filter(
                $buildings,
                static fn (BoardHexStateData $hex): bool => $hex->building?->type === BuildingType::Guild,
            )),
        ];
    }

    /** @param list<array{source: string, id: string, points: int}> $sources */
    private function addSource(array &$sources, string $source, string $id, int $points): void
    {
        if ($points > 0) {
            $sources[] = ['source' => $source, 'id' => $id, 'points' => $points];
        }
    }
}
