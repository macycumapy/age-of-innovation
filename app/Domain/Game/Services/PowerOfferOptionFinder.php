<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PowerOfferOptionData;
use App\Domain\Game\Enums\PendingInteractionType;

final class PowerOfferOptionFinder
{
    /** @return list<PowerOfferOptionData> */
    public function execute(GameStateData $state, int $playerId): array
    {
        if ($state->pendingInteraction?->type !== PendingInteractionType::PowerOffer
            || $state->pendingInteraction->playerId !== $playerId) {
            return [];
        }

        return [new PowerOfferOptionData(true), new PowerOfferOptionData(false)];
    }
}
