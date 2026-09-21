<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Data\ChooseRoundBonusOptionData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\RoundBonusOfferData;
use App\Domain\Game\Enums\PendingInteractionType;

final class ChooseRoundBonusOptionFinder
{
    /** @return list<ChooseRoundBonusOptionData> */
    public function execute(GameStateData $state, GamePlayerStateData $player): array
    {
        $interaction = $state->pendingInteraction;

        if ($interaction?->type !== PendingInteractionType::ChooseRoundBonus
            || $interaction->playerId !== $player->playerId
            || $state->setupPool === null) {
            return [];
        }

        return array_values(array_map(
            static fn (RoundBonusOfferData $offer): ChooseRoundBonusOptionData => new ChooseRoundBonusOptionData(
                $offer->roundBonus,
                $offer->coins,
            ),
            array_filter(
                $state->setupPool->availableRoundBonuses,
                static fn (RoundBonusOfferData $offer): bool => in_array(
                    $offer->roundBonus->value,
                    $interaction->optionIds,
                    true,
                ),
            ),
        ));
    }
}
