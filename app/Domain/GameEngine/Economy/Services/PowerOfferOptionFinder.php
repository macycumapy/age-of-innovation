<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Economy\Services;

use App\Domain\GameEngine\Economy\Data\PowerOfferOptionData;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\State\Data\GameStateData;

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
