<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Actions\FindEligibleAnnexHexesAction;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PlaceAnnexOptionData;

final class PlaceAnnexOptionFinder
{
    public function __construct(private FindEligibleAnnexHexesAction $findEligibleAnnexHexes)
    {
    }

    /** @return list<PlaceAnnexOptionData> */
    public function execute(GameStateData $state, GamePlayerStateData $player): array
    {
        if ($player->availableAnnexes < 1 || $state->pendingInteraction !== null || $state->round->hasTakenMainAction) {
            return [];
        }

        return array_map(
            static fn (string $hexId): PlaceAnnexOptionData => new PlaceAnnexOptionData($hexId),
            $this->findEligibleAnnexHexes->execute($state, $player->playerId),
        );
    }
}
