<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Enums\KnowledgeDiscipline;

class FinalScoringProgressEvaluator
{
    /** @var list<int> */
    private const NETWORK_PLACE_POINTS = [18, 12, 6];

    /** @var list<int> */
    private const KNOWLEDGE_PLACE_POINTS = [8, 4, 2];

    public function execute(GameStateData $before, GameStateData $after, int $playerId): int
    {
        return $this->projectedScore($after, $playerId) - $this->projectedScore($before, $playerId);
    }

    private function projectedScore(GameStateData $state, int $playerId): int
    {
        if (! collect($state->players)->contains(
            static fn (GamePlayerStateData $player): bool => $player->playerId === $playerId,
        )) {
            return 0;
        }

        $networkValues = collect($state->players)->mapWithKeys(
            static fn (GamePlayerStateData $player): array => [
                $player->playerId => LargestNetworkSizeCalculator::calculate(
                    $player,
                    $state->board,
                    includeRoundBonus: false,
                ),
            ],
        )->all();

        if ($state->setupPool?->twoPlayerTerritoryScore !== null) {
            $networkValues[0] = $state->setupPool->twoPlayerTerritoryScore->value;
        }

        $score = $this->rankingPoints($networkValues, self::NETWORK_PLACE_POINTS, $playerId);

        foreach (KnowledgeDiscipline::cases() as $discipline) {
            $knowledgeValues = collect($state->players)->mapWithKeys(
                static fn (GamePlayerStateData $player): array => [
                    $player->playerId => $player->knowledge->{$discipline->value},
                ],
            )->filter(static fn (int $value): bool => $value > 0)->all();

            if ($state->neutralKnowledge !== null) {
                $knowledgeValues[0] = $state->neutralKnowledge->knowledge->{$discipline->value};
            }

            $score += $this->rankingPoints($knowledgeValues, self::KNOWLEDGE_PLACE_POINTS, $playerId);
        }

        return $score;
    }

    /**
     * @param array<int, int> $valuesByPlayerId
     * @param list<int> $placePoints
     */
    private function rankingPoints(array $valuesByPlayerId, array $placePoints, int $playerId): int
    {
        arsort($valuesByPlayerId, SORT_NUMERIC);
        $rankIndex = 0;

        foreach (collect($valuesByPlayerId)->groupBy(
            static fn (int $value): int => $value,
            preserveKeys: true,
        ) as $playersAtValue) {
            $tiedPlayerIds = $playersAtValue->keys()
                ->map(static fn (int|string $id): int => (int) $id)
                ->all();
            $occupiedPlacePoints = array_slice(
                array_pad($placePoints, count($valuesByPlayerId), 0),
                $rankIndex,
                count($tiedPlayerIds),
            );

            if (in_array($playerId, $tiedPlayerIds, true)) {
                return intdiv(array_sum($occupiedPlacePoints), count($tiedPlayerIds));
            }

            $rankIndex += count($tiedPlayerIds);
        }

        return 0;
    }
}
