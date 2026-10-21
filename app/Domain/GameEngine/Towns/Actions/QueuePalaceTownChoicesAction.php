<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Towns\Actions;

use App\Domain\GameEngine\Interactions\Data\PendingInteractionData;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\PlayerAbilities\Enums\PalaceAbility;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;

final class QueuePalaceTownChoicesAction
{
    public function __construct(
        private FindEligibleTownHexesAction $findEligibleTownHexes,
        private FindPalaceWaterTownOptionsAction $findPalaceWaterTownOptions,
    ) {
    }

    public function execute(GameStateData $state, GamePlayerStateData $player): void
    {
        if (! in_array($player->palaceId, [PalaceAbility::Palace08->value, PalaceAbility::Palace14->value], true)
            || $state->availableTownTileIds === []) {
            return;
        }

        $visitedHexIds = [];
        foreach ($state->board->hexes as $hex) {
            if ($hex->building?->ownerPlayerId !== $player->playerId
                || $hex->townId !== null || in_array($hex->id, $visitedHexIds, true)) {
                continue;
            }

            $townHexIds = $this->findEligibleTownHexes->execute($state, $player, $hex->id);
            $waterOptions = $townHexIds === []
                ? $this->findPalaceWaterTownOptions->execute($state, $player, $hex->id)
                : [];
            if ($townHexIds === [] && $waterOptions === []) {
                continue;
            }

            $visitedHexIds = [...$visitedHexIds, ...$townHexIds, ...array_merge([], ...array_values($waterOptions))];
            $state->pendingInteractionQueue[] = new PendingInteractionData(
                $townHexIds !== [] ? PendingInteractionType::ChooseTown : PendingInteractionType::OfferPalaceWaterTown,
                $player->playerId,
                $townHexIds !== [] ? array_values(array_unique($state->availableTownTileIds)) : array_keys($waterOptions),
                [
                    'builtHexId' => $hex->id,
                    'palaceTownChoice' => true,
                    'townHexIds' => $townHexIds,
                    'townsByWaterHexId' => $waterOptions,
                ],
            );
        }
    }
}
