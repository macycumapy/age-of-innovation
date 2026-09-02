<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Enums\PendingInteractionType;

final class CreateTownChoiceAfterBuildingAction
{
    public function __construct(
        private FindEligibleTownHexesAction $findEligibleTownHexes,
        private FindPalaceWaterTownOptionsAction $findPalaceWaterTownOptions,
        private CreatePowerOffersAfterBuildingAction $createPowerOffersAfterBuilding,
    ) {
    }

    /** @param list<string> $queuedBuiltHexIds */
    public function execute(
        GameStateData $state,
        GamePlayerStateData $player,
        string $builtHexId,
        array $queuedBuiltHexIds = [],
        bool $powerOffersResolved = false,
    ): int {
        if (! $powerOffersResolved) {
            $nextActiveUserId = $this->createPowerOffersAfterBuilding->execute(
                $state,
                $player->playerId,
                $builtHexId,
                $queuedBuiltHexIds,
            );

            if ($nextActiveUserId !== null && $state->pendingInteraction?->type === PendingInteractionType::PowerOffer) {
                $state->pendingInteraction->context['townBuiltHexId'] = $builtHexId;

                return $nextActiveUserId;
            }
        }

        $townHexIds = $this->findEligibleTownHexes->execute($state, $player, $builtHexId);

        if ($townHexIds !== [] && $state->availableTownTileIds !== []) {
            $state->pendingInteraction = new PendingInteractionData(
                PendingInteractionType::ChooseTown,
                $player->playerId,
                array_values(array_unique($state->availableTownTileIds)),
                [
                    'townHexIds' => $townHexIds,
                    'builtHexId' => $builtHexId,
                    'queuedBuiltHexIds' => $queuedBuiltHexIds,
                ],
            );

            return $player->userId;
        }

        $waterTownOptions = $this->findPalaceWaterTownOptions->execute($state, $player, $builtHexId);

        if ($waterTownOptions !== [] && $state->availableTownTileIds !== []) {
            $state->pendingInteraction = new PendingInteractionData(
                PendingInteractionType::OfferPalaceWaterTown,
                $player->playerId,
                array_keys($waterTownOptions),
                [
                    'townsByWaterHexId' => $waterTownOptions,
                    'builtHexId' => $builtHexId,
                    'queuedBuiltHexIds' => $queuedBuiltHexIds,
                ],
            );

            return $player->userId;
        }

        $state->pendingInteraction = null;

        return $player->userId;
    }
}
