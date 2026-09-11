<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\PendingInteractionType;

final class OfferWorkshopAfterTerraformingAction
{
    /**
     * @param list<string> $hexIds
     * @param array<string, mixed> $context
     */
    public function execute(
        GameStateData $state,
        GamePlayerStateData $playerState,
        array $hexIds,
        array $context = [],
    ): bool {
        $availableHexIds = array_values(array_filter(
            array_unique($hexIds),
            static fn (string $hexId): bool => collect($state->board->hexes)->contains(
                static fn (BoardHexStateData $hex): bool => $hex->id === $hexId
                    && $hex->terrain === $playerState->homeland
                    && $hex->building === null,
            ),
        ));
        $workshopsOnMap = count(array_filter(
            $state->board->hexes,
            static fn (BoardHexStateData $hex): bool => $hex->building?->ownerPlayerId === $playerState->playerId
                && $hex->building->type === BuildingType::Workshop
                && ! $hex->building->isNeutral,
        ));

        if ($availableHexIds === [] || $workshopsOnMap >= BuildingType::Workshop->supplyLimit()) {
            $state->pendingInteraction = null;

            return false;
        }

        $state->pendingInteraction = new PendingInteractionData(
            PendingInteractionType::BuildWorkshopAfterTerraforming,
            $playerState->playerId,
            $availableHexIds,
            ['toolCost' => 1, 'coinCost' => 2, ...$context],
        );

        return true;
    }
}
