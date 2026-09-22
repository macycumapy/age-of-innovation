<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Data\ChoosePalaceOptionData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Enums\PalaceAbility;
use App\Domain\Game\Enums\PendingInteractionType;

final class ChoosePalaceOptionFinder
{
    /** @return list<ChoosePalaceOptionData> */
    public function execute(GameStateData $state, GamePlayerStateData $player): array
    {
        $interaction = $state->pendingInteraction;

        if ($interaction?->type !== PendingInteractionType::ChoosePalace
            || $interaction->playerId !== $player->playerId
            || $player->palaceId !== null) {
            return [];
        }

        $options = [];
        foreach ($interaction->optionIds as $optionId) {
            $palace = is_string($optionId) ? PalaceAbility::tryFrom($optionId) : null;
            if ($palace !== null) {
                $options[] = new ChoosePalaceOptionData($palace);
            }
        }

        return $options;
    }
}
