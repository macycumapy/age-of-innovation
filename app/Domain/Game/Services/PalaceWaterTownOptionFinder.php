<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PalaceWaterTownOptionData;
use App\Domain\Game\Enums\PendingInteractionType;

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
