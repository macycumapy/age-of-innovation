<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Board\Actions;

use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\PlaceAnnexResultData;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Towns\Actions\CreateTownChoiceAfterBuildingAction;
use Illuminate\Validation\ValidationException;

final class ApplyPlaceAnnexAction
{
    public function __construct(
        private FindEligibleAnnexHexesAction $findEligibleAnnexHexes,
        private CreateTownChoiceAfterBuildingAction $createTownChoiceAfterBuilding,
    ) {
    }

    public function execute(
        GameStateData $state,
        GamePlayerStateData $player,
        string $hexId,
    ): PlaceAnnexResultData {
        $hex = collect($state->board->hexes)->firstWhere('id', $hexId);

        if (! $state->round->phase->isActionPhase()
            || $state->pendingInteraction !== null
            || $state->round->hasTakenMainAction
            || ! $hex instanceof BoardHexStateData
            || $hex->building === null
            || $player->availableAnnexes < 1
            || ! in_array($hexId, $this->findEligibleAnnexHexes->execute($state, $player->playerId), true)) {
            throw ValidationException::withMessages(['annex' => 'Сначала выберите доступное здание.']);
        }

        $player->availableAnnexes--;
        $hex->building->hasAnnex = true;
        $state->round->hasTakenMainAction = true;

        return new PlaceAnnexResultData($this->createTownChoiceAfterBuilding->execute(
            $state,
            $player,
            $hexId,
            powerOffersResolved: true,
        ));
    }
}
