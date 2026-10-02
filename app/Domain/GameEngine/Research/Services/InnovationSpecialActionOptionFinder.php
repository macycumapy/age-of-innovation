<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Research\Services;

use App\Domain\GameEngine\Research\Data\InnovationSpecialActionOptionData;
use App\Domain\GameEngine\Research\Enums\Innovation;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;

final class InnovationSpecialActionOptionFinder
{
    /** @return list<InnovationSpecialActionOptionData> */
    public function execute(GameStateData $state, GamePlayerStateData $player): array
    {
        if (! $state->round->phase->isActionPhase()
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
