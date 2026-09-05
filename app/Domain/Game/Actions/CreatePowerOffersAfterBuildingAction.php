<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Services\BuildingAdjacencyChecker;

final class CreatePowerOffersAfterBuildingAction
{
    /** @param list<string> $queuedBuiltHexIds */
    public function execute(
        GameStateData $state,
        int $buildingPlayerId,
        string $builtHexId,
        array $queuedBuiltHexIds = [],
    ): ?int {
        $builtHex = collect($state->board->hexes)->firstWhere('id', $builtHexId);

        if (! $builtHex instanceof BoardHexStateData) {
            return null;
        }

        $powerByPlayerId = [];
        $neighborHexIds = BuildingAdjacencyChecker::neighborHexIds($state->board, $builtHex);

        foreach ($state->board->hexes as $hex) {
            $ownerPlayerId = $hex->building?->ownerPlayerId;

            if (! in_array($hex->id, $neighborHexIds, true)
                || $ownerPlayerId === null
                || $ownerPlayerId === $buildingPlayerId) {
                continue;
            }

            $powerByPlayerId[$ownerPlayerId] = ($powerByPlayerId[$ownerPlayerId] ?? 0)
                + $hex->building->type->powerValue()
                + ($hex->building->hasAnnex ? 1 : 0);
        }

        $buildingPlayerIndex = array_search($buildingPlayerId, $state->turnOrder, true);
        $orderedPlayerIds = $buildingPlayerIndex === false
            ? $state->turnOrder
            : array_map(
                static fn (int $offset): int => $state->turnOrder[
                    ($buildingPlayerIndex + $offset) % count($state->turnOrder)
                ],
                range(1, count($state->turnOrder)),
            );
        $offers = [];

        foreach ($orderedPlayerIds as $playerId) {
            $playerState = collect($state->players)->firstWhere('playerId', $playerId);
            $availablePower = $playerState instanceof GamePlayerStateData
                ? $playerState->resources->power->bowlOne + $playerState->resources->power->bowlTwo
                : 0;
            $powerAmount = $powerByPlayerId[$playerId] ?? 0;

            if ($powerAmount > 0 && $availablePower > 0 && $playerState instanceof GamePlayerStateData) {
                $offers[] = [
                    'playerId' => $playerId,
                    'userId' => $playerState->userId,
                    'powerAmount' => $powerAmount,
                ];
            }
        }

        if ($offers === []) {
            $nextBuiltHexId = array_shift($queuedBuiltHexIds);

            if (is_string($nextBuiltHexId)) {
                return $this->execute($state, $buildingPlayerId, $nextBuiltHexId, $queuedBuiltHexIds);
            }

            $state->pendingInteraction = null;

            return null;
        }

        $currentOffer = array_shift($offers);
        $state->pendingInteraction = new PendingInteractionData(
            PendingInteractionType::PowerOffer,
            $currentOffer['playerId'],
            [],
            [
                'buildingPlayerId' => $buildingPlayerId,
                'builtHexId' => $builtHexId,
                'powerAmount' => $currentOffer['powerAmount'],
                'remainingOffers' => $offers,
                ...($queuedBuiltHexIds === [] ? [] : ['queuedBuiltHexIds' => $queuedBuiltHexIds]),
            ],
        );

        return $currentOffer['userId'];
    }
}
