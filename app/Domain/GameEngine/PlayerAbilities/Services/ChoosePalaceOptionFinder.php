<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\PlayerAbilities\Services;

use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\PlayerAbilities\Data\ChoosePalaceOptionData;
use App\Domain\GameEngine\PlayerAbilities\Enums\PalaceAbility;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;

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
