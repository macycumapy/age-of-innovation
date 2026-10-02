<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Towns\Services;

use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Towns\Data\PalaceWaterTownOptionData;

final class PalaceWaterTownOptionFinder
{
    /** @return list<PalaceWaterTownOptionData> */
    public function execute(GameStateData $state, int $playerId): array
    {
        $interaction = $state->pendingInteraction;

        if ($interaction?->type !== PendingInteractionType::OfferPalaceWaterTown
            || $interaction->playerId !== $playerId) {
            return [];
        }

        $options = [new PalaceWaterTownOptionData(false)];
        $townsByWaterHexId = $interaction->context['townsByWaterHexId'] ?? [];

        if (! is_array($townsByWaterHexId)) {
            return $options;
        }

        foreach (array_keys($townsByWaterHexId) as $waterHexId) {
            if (is_string($waterHexId)) {
                $options[] = new PalaceWaterTownOptionData(true, $waterHexId);
            }
        }

        return $options;
    }
}
