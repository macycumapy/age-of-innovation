<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Enums\TerrainType;
use App\Models\GamePlayer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

final class ResolveCompletedStartingSetupAction
{
    /**
     * @param Collection<int, GamePlayer> $players
     * @return array{GamePlayer, GamePhase}
     */
    public function execute(GameStateData $state, Collection $players): array
    {
        $desertPlayer = $players->firstWhere('homeland', TerrainType::Desert);
        $desertPlayerState = $desertPlayer instanceof GamePlayer
            ? collect($state->players)->firstWhere('playerId', $desertPlayer->id)
            : null;
        $eligibleHexIds = $desertPlayer instanceof GamePlayer
            ? $this->eligibleHexIds($state, $desertPlayer->id)
            : [];

        if ($desertPlayer instanceof GamePlayer
            && $desertPlayerState?->unassignedSpades > 0
            && $eligibleHexIds !== []) {
            $state->pendingInteraction = new PendingInteractionData(
                PendingInteractionType::SpendSpades,
                $desertPlayer->id,
                $eligibleHexIds,
                ['spadeCount' => 1, 'targetTerrain' => TerrainType::Desert->value],
            );

            return [$desertPlayer, GamePhase::Setup];
        }

        $firstPlayer = $players->firstWhere('id', $state->turnOrder[0] ?? null);

        if (! $firstPlayer instanceof GamePlayer) {
            throw ValidationException::withMessages(['game' => 'Не найден первый игрок нового раунда.']);
        }

        $state->round->phase = GamePhase::Income;

        return [$firstPlayer, GamePhase::Income];
    }

    /** @return list<string> */
    private function eligibleHexIds(GameStateData $state, int $playerId): array
    {
        $hexesById = collect($state->board->hexes)->keyBy('id');
        $eligibleHexIds = [];

        foreach ($state->board->hexes as $hex) {
            if ($hex->building?->ownerPlayerId !== $playerId) {
                continue;
            }

            foreach ($hex->adjacentHexIds as $adjacentHexId) {
                $adjacentHex = $hexesById->get($adjacentHexId);

                if ($adjacentHex instanceof BoardHexStateData
                    && $adjacentHex->building === null
                    && $adjacentHex->terrain->isHomeland()
                    && $adjacentHex->terrain !== TerrainType::Desert) {
                    $eligibleHexIds[] = $adjacentHexId;
                }
            }
        }

        return array_values(array_unique($eligibleHexIds));
    }
}
