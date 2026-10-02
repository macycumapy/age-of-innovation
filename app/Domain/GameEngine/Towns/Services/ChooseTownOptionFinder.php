<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Towns\Services;

use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Towns\Data\ChooseTownOptionData;
use App\Domain\GameEngine\Towns\Enums\TownTile;

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
