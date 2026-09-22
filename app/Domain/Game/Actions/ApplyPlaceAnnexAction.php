<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PlaceAnnexResultData;
use App\Domain\Game\Enums\GamePhase;
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

        if ($state->round->phase !== GamePhase::Actions
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
