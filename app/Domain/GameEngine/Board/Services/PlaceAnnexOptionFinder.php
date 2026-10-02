<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Board\Services;

use App\Domain\GameEngine\Board\Actions\FindEligibleAnnexHexesAction;
use App\Domain\GameEngine\Board\Data\PlaceAnnexOptionData;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;

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
