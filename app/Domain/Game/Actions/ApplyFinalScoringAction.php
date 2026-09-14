<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Services\LargestNetworkSizeCalculator;

final class ApplyFinalScoringAction
{
    /** @var list<int> */
    private const NETWORK_PLACE_POINTS = [18, 12, 6];

    /** @var list<int> */
    private const KNOWLEDGE_PLACE_POINTS = [8, 4, 2];

    /**
     * @return list<array{playerId: int, victoryPoints: int, sources: list<array{source: string, id: string, value: int, rank: int, points: int}>}>
     */
    public function execute(GameStateData $state): array
    {
        $scoringByPlayerId = collect($state->players)->mapWithKeys(
            static fn (GamePlayerStateData $player): array => [
                $player->playerId => ['playerId' => $player->playerId, 'victoryPoints' => 0, 'sources' => []],
            ],
        )->all();
        $networkValues = collect($state->players)->mapWithKeys(
            static fn (GamePlayerStateData $player): array => [
                $player->playerId => LargestNetworkSizeCalculator::calculate(
                    $player,
                    $state->board,
                    includeRoundBonus: false,
                ),
            ],
        )->all();
        $twoPlayerTerritoryScore = $state->setupPool?->twoPlayerTerritoryScore;

        if ($twoPlayerTerritoryScore !== null) {
            $networkValues[0] = $twoPlayerTerritoryScore->value;
        }

        $this->awardRanking(
            $state,
            $networkValues,
            self::NETWORK_PLACE_POINTS,
            'network',
            'largest_network',
            $scoringByPlayerId,
        );

        foreach (KnowledgeDiscipline::cases() as $discipline) {
            $knowledgeValues = collect($state->players)->mapWithKeys(
                static fn (GamePlayerStateData $player): array => [
                    $player->playerId => $player->knowledge->{$discipline->value},
                ],
            )->filter(static fn (int $value): bool => $value > 0)->all();

            if ($state->neutralKnowledge !== null) {
                $knowledgeValues[0] = $state->neutralKnowledge->knowledge->{$discipline->value};
            }

            $this->awardRanking(
                $state,
                $knowledgeValues,
                self::KNOWLEDGE_PLACE_POINTS,
                'knowledge',
                $discipline->value,
                $scoringByPlayerId,
            );
        }

        return array_values($scoringByPlayerId);
    }

    /**
     * @param array<int, int> $valuesByPlayerId
     * @param list<int> $placePoints
     * @param array<int, array{playerId: int, victoryPoints: int, sources: list<array{source: string, id: string, value: int, rank: int, points: int}>}> $scoringByPlayerId
     */
    private function awardRanking(
        GameStateData $state,
        array $valuesByPlayerId,
        array $placePoints,
        string $source,
        string $sourceId,
        array &$scoringByPlayerId,
    ): void {
        arsort($valuesByPlayerId, SORT_NUMERIC);
        $rankIndex = 0;

        foreach (collect($valuesByPlayerId)->groupBy(
            static fn (int $value): int => $value,
            preserveKeys: true,
        ) as $playerIds) {
            $tiedPlayerIds = $playerIds->keys()->map(static fn (int|string $playerId): int => (int) $playerId)->all();
            $pointsForOccupiedPlaces = array_slice(
                array_pad($placePoints, count($valuesByPlayerId), 0),
                $rankIndex,
                count($tiedPlayerIds),
            );
            $pointsPerPlayer = intdiv(array_sum($pointsForOccupiedPlaces), count($tiedPlayerIds));

            foreach ($state->players as $player) {
                if (in_array($player->playerId, $tiedPlayerIds, true)) {
                    $player->victoryPoints += $pointsPerPlayer;
                    $scoringByPlayerId[$player->playerId]['victoryPoints'] += $pointsPerPlayer;
                    $scoringByPlayerId[$player->playerId]['sources'][] = [
                        'source' => $source,
                        'id' => $sourceId,
                        'value' => $valuesByPlayerId[$player->playerId],
                        'rank' => $rankIndex + 1,
                        'points' => $pointsPerPlayer,
                    ];
                }
            }

            $rankIndex += count($tiedPlayerIds);
        }
    }
}
