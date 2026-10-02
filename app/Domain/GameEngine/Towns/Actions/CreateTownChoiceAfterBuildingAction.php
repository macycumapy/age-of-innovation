<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Towns\Actions;

use App\Domain\GameEngine\Economy\Actions\CreatePowerOffersAfterBuildingAction;
use App\Domain\GameEngine\Interactions\Data\PendingInteractionData;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;

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
            $nextActivePlayerId = $this->createPowerOffersAfterBuilding->execute(
                $state,
                $player->playerId,
                $builtHexId,
                $queuedBuiltHexIds,
            );

            if ($nextActivePlayerId !== null && $state->pendingInteraction?->type === PendingInteractionType::PowerOffer) {
                $state->pendingInteraction->context['townBuiltHexId'] = $builtHexId;

                return $nextActivePlayerId;
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

            return $player->playerId;
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

            return $player->playerId;
        }

        $state->pendingInteraction = null;

        return $player->playerId;
    }
}
