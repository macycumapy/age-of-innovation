<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Turns\Services;

use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\PlayerAbilities\Data\RoundBonusOfferData;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Turns\Data\ChooseRoundBonusOptionData;

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
