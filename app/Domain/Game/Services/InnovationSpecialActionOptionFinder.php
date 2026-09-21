<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\InnovationSpecialActionOptionData;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\Innovation;

final class InnovationSpecialActionOptionFinder
{
    /** @return list<InnovationSpecialActionOptionData> */
    public function execute(GameStateData $state, GamePlayerStateData $player): array
    {
        if ($state->round->phase !== GamePhase::Actions
            || $state->pendingInteraction !== null
            || $state->round->hasTakenMainAction) {
            return [];
        }

        return array_values(array_map(
            static fn (Innovation $innovation): InnovationSpecialActionOptionData => new InnovationSpecialActionOptionData($innovation),
            array_filter(
                array_map(static fn (string $id): ?Innovation => Innovation::tryFrom($id), $player->inventionIds),
                static fn (?Innovation $innovation): bool => $innovation?->hasSpecialAction() === true
                    && ! in_array($innovation->specialActionId(), $player->usedSpecialActionIds, true),
            ),
        ));
    }
}
