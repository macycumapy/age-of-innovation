<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Enums\RoundScoringGoal;
use App\Domain\Game\Enums\RoundScoringTile;

class RoundScoringProgressEvaluator
{
    public function execute(GameStateData $before, GameStateData $after, int $playerId): int
    {
        if (! $before->round->phase->isActionPhase()) {
            return 0;
        }

        $tile = RoundScoringTile::tryFrom((string) $before->round->scoringTileId);
        $playerBefore = $this->player($before, $playerId);
        $playerAfter = $this->player($after, $playerId);

        if ($tile === null || $playerBefore === null || $playerAfter === null) {
            return 0;
        }

        return match ($tile->goal()) {
            RoundScoringGoal::Workshop => $this->buildingGain(
                $before,
                $after,
                $playerId,
                [BuildingType::Workshop],
            ) * 2,
            RoundScoringGoal::Guild => $this->buildingGain(
                $before,
                $after,
                $playerId,
                [BuildingType::Guild],
            ) * 3,
            RoundScoringGoal::School => $this->buildingGain(
                $before,
                $after,
                $playerId,
                [BuildingType::School],
            ) * 4,
            RoundScoringGoal::PalaceOrUniversity => $this->buildingGain(
                $before,
                $after,
                $playerId,
                [BuildingType::Palace, BuildingType::University],
            ) * 5,
            RoundScoringGoal::Spade => max(
                0,
                $playerBefore->unassignedSpades - $playerAfter->unassignedSpades,
            ) * 2,
            RoundScoringGoal::Knowledge => max(
                0,
                $this->knowledgeLevel($playerAfter) - $this->knowledgeLevel($playerBefore),
            ),
            RoundScoringGoal::Town => max(
                0,
                count($playerAfter->townTileIds) - count($playerBefore->townTileIds),
            ) * 5,
            RoundScoringGoal::ShippingOrTerraforming => max(
                0,
                ($playerAfter->shippingLevel + $playerAfter->terraformingLevel)
                    - ($playerBefore->shippingLevel + $playerBefore->terraformingLevel),
            ) * 3,
            RoundScoringGoal::Innovation => max(
                0,
                count($playerAfter->inventionIds) - count($playerBefore->inventionIds),
            ) * 5,
        };
    }

    /** @param list<BuildingType> $types */
    private function buildingGain(
        GameStateData $before,
        GameStateData $after,
        int $playerId,
        array $types,
    ): int {
        return max(
            0,
            $this->buildingCount($after, $playerId, $types)
                - $this->buildingCount($before, $playerId, $types),
        );
    }

    /** @param list<BuildingType> $types */
    private function buildingCount(GameStateData $state, int $playerId, array $types): int
    {
        return collect($state->board->hexes)->filter(
            static fn (BoardHexStateData $hex): bool => $hex->building?->ownerPlayerId === $playerId
                && in_array($hex->building->type, $types, true),
        )->count();
    }

    private function knowledgeLevel(GamePlayerStateData $player): int
    {
        return array_sum(array_map(
            static fn (KnowledgeDiscipline $discipline): int => $player->knowledge->{$discipline->value},
            KnowledgeDiscipline::cases(),
        ));
    }

    private function player(GameStateData $state, int $playerId): ?GamePlayerStateData
    {
        $player = collect($state->players)->firstWhere('playerId', $playerId);

        return $player instanceof GamePlayerStateData ? $player : null;
    }
}
