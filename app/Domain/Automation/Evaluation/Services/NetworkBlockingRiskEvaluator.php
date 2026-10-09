<?php

declare(strict_types=1);

namespace App\Domain\Automation\Evaluation\Services;

use App\Domain\Automation\Evaluation\Data\TownAnnexPlanningData;
use App\Domain\GameEngine\Board\Actions\FindReachableLandHexesAction;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Board\Services\BuildWorkshopOptionFinder;
use App\Domain\GameEngine\Board\Services\PaidTerraformingOptionFinder;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;

final class NetworkBlockingRiskEvaluator
{
    private const int BLOCKED_EXIT_WEIGHT = 40;

    private const int LOST_EXPANSION_WEIGHT = 20;

    private const int THREATENED_TOWN_WEIGHT = 80;

    private const int ASSISTED_OPPONENT_TERRAFORMING_WEIGHT = 40;

    public function __construct(
        private FindReachableLandHexesAction $findReachableLandHexes,
        private BuildWorkshopOptionFinder $buildWorkshopOptionFinder,
        private PaidTerraformingOptionFinder $paidTerraformingOptionFinder,
        private TownAnnexPlanningEvaluator $townAnnexPlanningEvaluator,
    ) {
    }

    /**
     * @param list<list<BoardHexStateData>> $components
     * @param list<int> $componentScores
     */
    public function townRisk(GameStateData $state, GamePlayerStateData $player, array $components, array $componentScores, TownAnnexPlanningData $planning): int
    {
        if ($planning->expansionHexIds === [] || ! $state->round->phase->isActionPhase()) {
            return 0;
        }
        $contested = $this->contestedHexIds($state, $player);
        $targets = array_slice(array_values(array_filter(
            $planning->expansionHexIds,
            static fn (string $id): bool => isset($contested[$id])
        )), 0, 3);
        $risk = 0;
        foreach ($targets as $targetId) {
            $blocked = clone $state;
            $blocked->board = clone $state->board;
            $blocked->board->hexes = array_map(static function (BoardHexStateData $hex) use ($targetId): BoardHexStateData {
                if ($hex->id !== $targetId) {
                    return $hex;
                }
                $hex = clone $hex;
                $hex->terrain = TerrainType::Water;

                return $hex;
            }, $state->board->hexes);
            $remaining = $this->townAnnexPlanningEvaluator->execute($blocked, $player, $components, $componentScores);
            $risk = max($risk, max(0, $planning->projectedTowns - $remaining->projectedTowns) * self::THREATENED_TOWN_WEIGHT);
        }

        return $risk;
    }

    public function execute(GameStateData $before, GameStateData $after, int $playerId): int
    {
        $playerBefore = collect($before->players)->firstWhere('playerId', $playerId);
        $playerAfter = collect($after->players)->firstWhere('playerId', $playerId);
        if (! $playerBefore instanceof GamePlayerStateData || ! $playerAfter instanceof GamePlayerStateData) {
            return 0;
        }
        $ownedBefore = [];
        foreach ($before->board->hexes as $hex) {
            if ($hex->building?->ownerPlayerId === $playerId) {
                $ownedBefore[$hex->id] = true;
            }
        }
        $hasNewBuilding = array_any($after->board->hexes, static fn (BoardHexStateData $hex): bool =>
            $hex->building?->ownerPlayerId === $playerId && ! isset($ownedBefore[$hex->id]));
        $bridgesBefore = count(array_filter($before->board->bridges, static fn ($bridge): bool => $bridge->ownerPlayerId === $playerId));
        $bridgesAfter = count(array_filter($after->board->bridges, static fn ($bridge): bool => $bridge->ownerPlayerId === $playerId));
        $hasNewBridge = $bridgesAfter > $bridgesBefore;
        $terraformingRisk = $this->partialTerraformingRisk($before, $after, $playerAfter);
        if (! $hasNewBuilding && ! $hasNewBridge && $playerAfter->shippingLevel <= $playerBefore->shippingLevel) {
            return -$terraformingRisk;
        }

        return $this->risk($before, $playerBefore) - $this->risk($after, $playerAfter) - $terraformingRisk;
    }

    private function partialTerraformingRisk(GameStateData $before, GameStateData $after, GamePlayerStateData $player): int
    {
        if (! $after->round->phase->isActionPhase()) {
            return 0;
        }
        $previous = collect($before->board->hexes)->keyBy('id');
        $changed = [];
        foreach ($after->board->hexes as $hex) {
            $original = $previous->get($hex->id);
            if ($original instanceof BoardHexStateData && $hex->building === null
                && $hex->terrain->isHomeland() && $original->terrain->isHomeland()
                && $hex->terrain !== $player->homeland && $hex->terrain !== $original->terrain
                && $hex->terrain->spadesTo($player->homeland) < $original->terrain->spadesTo($player->homeland)) {
                $changed[$hex->id] = $hex;
            }
        }
        if ($changed === []) {
            return 0;
        }
        $probe = clone $after;
        $probe->round = clone $after->round;
        $probe->round->hasTakenMainAction = false;
        $probe->pendingInteraction = null;
        $threatened = [];
        foreach ($after->players as $opponent) {
            if ($opponent->playerId === $player->playerId || in_array($opponent->playerId, $after->passedPlayerIds, true)) {
                continue;
            }
            $targets = [];
            foreach ($this->buildWorkshopOptionFinder->execute($probe, $opponent) as $option) {
                $targets[$option->hexId] = true;
            }
            $workshops = count(array_filter($after->board->hexes, static fn (BoardHexStateData $hex): bool =>
                $hex->building?->ownerPlayerId === $opponent->playerId && ! $hex->building->isNeutral
                && $hex->building->type === BuildingType::Workshop));
            if ($opponent->resources->coins >= 2 && $workshops < BuildingType::Workshop->supplyLimit()) {
                foreach ($this->paidTerraformingOptionFinder->execute($probe, $opponent) as $option) {
                    if ($option->toolCost + 1 <= $opponent->resources->tools) {
                        $targets[$option->hexId] = true;
                    }
                }
            }
            foreach ($changed as $id => $hex) {
                if (isset($targets[$id]) && $hex->terrain->spadesTo($opponent->homeland)
                    < $previous->get($id)->terrain->spadesTo($opponent->homeland)) {
                    $threatened[$id] = true;
                }
            }
        }

        return count($threatened) * self::ASSISTED_OPPONENT_TERRAFORMING_WEIGHT;
    }

    private function risk(GameStateData $state, GamePlayerStateData $player): int
    {
        $hexes = [];
        foreach ($state->board->hexes as $hex) {
            if ($hex->building === null && $hex->terrain->isHomeland()) {
                $hexes[$hex->id] = $hex;
            }
        }
        $roots = array_values(array_intersect($this->findReachableLandHexes->execute($state, $player), array_keys($hexes)));
        if ($roots === []) {
            return 0;
        }
        $reachable = $this->expansionRegion($hexes, $roots);
        $contested = $this->contestedHexIds($state, $player);
        $risk = 0;
        foreach ($roots as $root) {
            if (! isset($contested[$root])) {
                continue;
            }
            $remaining = $this->expansionRegion($hexes, $roots, $root);
            $lost = count(array_diff_key($reachable, $remaining)) - 1;
            if ($lost > 0) {
                $risk = max($risk, self::BLOCKED_EXIT_WEIGHT + min(4, $lost) * self::LOST_EXPANSION_WEIGHT);
            }
        }

        return intdiv($risk * max(1, min(3, 7 - $state->round->number)), 3);
    }

    /** @return array<string, true> */
    private function contestedHexIds(GameStateData $state, GamePlayerStateData $player): array
    {
        $probe = clone $state;
        $probe->round = clone $state->round;
        $probe->round->hasTakenMainAction = false;
        $probe->pendingInteraction = null;
        $contested = [];
        foreach ($state->players as $opponent) {
            if ($opponent->playerId === $player->playerId || in_array($opponent->playerId, $state->passedPlayerIds, true)) {
                continue;
            }
            foreach ($this->buildWorkshopOptionFinder->execute($probe, $opponent) as $option) {
                $contested[$option->hexId] = true;
            }
            $workshops = count(array_filter($state->board->hexes, static fn (BoardHexStateData $hex): bool =>
                $hex->building?->ownerPlayerId === $opponent->playerId && $hex->building->type === BuildingType::Workshop
                    && ! $hex->building->isNeutral));
            if ($opponent->resources->coins < 2 || $workshops >= BuildingType::Workshop->supplyLimit()) {
                continue;
            }
            foreach ($this->paidTerraformingOptionFinder->execute($probe, $opponent) as $option) {
                if ($option->toolCost + 1 <= $opponent->resources->tools) {
                    $contested[$option->hexId] = true;
                }
            }
        }
        return $contested;
    }

    /**
     * @param array<string, BoardHexStateData> $hexes
     * @param list<string> $roots
     * @return array<string, true>
     */
    private function expansionRegion(array $hexes, array $roots, ?string $blocked = null): array
    {
        $visited = [];
        while ($roots !== []) {
            $id = array_pop($roots);
            if ($id === $blocked || isset($visited[$id]) || ! isset($hexes[$id])) {
                continue;
            }
            $visited[$id] = true;
            foreach ($hexes[$id]->adjacentHexIds as $adjacent) {
                if (! isset($visited[$adjacent])) {
                    $roots[] = $adjacent;
                }
            }
        }

        return $visited;
    }
}
