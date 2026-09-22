<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Data\ChooseTownOptionData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Enums\TownTile;

final class ChooseTownOptionFinder
{
    /** @return list<ChooseTownOptionData> */
    public function execute(GameStateData $state, int $playerId): array
    {
        $interaction = $state->pendingInteraction;

        if ($interaction?->type !== PendingInteractionType::ChooseTown || $interaction->playerId !== $playerId) {
            return [];
        }

        $options = [];

        foreach ($interaction->optionIds as $optionId) {
            $townTile = is_string($optionId) ? TownTile::tryFrom($optionId) : null;

            if ($townTile !== null) {
                $options[] = new ChooseTownOptionData($townTile);
            }
        }

        return $options;
    }
}
