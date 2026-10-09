<?php

declare(strict_types=1);

namespace App\Domain\Automation\Evaluation\Services;

use App\Domain\GameEngine\Board\Actions\FindReachableLandHexesAction;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Services\BuildWorkshopOptionFinder;
use App\Domain\GameEngine\Board\Services\PaidTerraformingOptionFinder;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;

final class NetworkBlockingRiskEvaluator
{
    private const int BLOCKED_EXIT_WEIGHT = 40;

    private const int LOST_EXPANSION_WEIGHT = 20;

    public function __construct(
        private FindReachableLandHexesAction $findReachableLandHexes,
        private BuildWorkshopOptionFinder $buildWorkshopOptionFinder,
        private PaidTerraformingOptionFinder $paidTerraformingOptionFinder,
    ) {
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
        if (! $hasNewBuilding && ! $hasNewBridge && $playerAfter->shippingLevel <= $playerBefore->shippingLevel) {
            return 0;
        }

        return $this->risk($before, $playerBefore) - $this->risk($after, $playerAfter);
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
