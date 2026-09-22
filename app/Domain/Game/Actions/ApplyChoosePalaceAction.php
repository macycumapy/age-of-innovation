<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\ChoosePalaceResultData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\PalaceAbility;
use App\Domain\Game\Enums\PendingInteractionType;
use Illuminate\Validation\ValidationException;

final class ApplyChoosePalaceAction
{
    public function __construct(
        private AdvanceDevelopmentTrackAction $advanceDevelopmentTrack,
        private ApplyDevelopmentTrackRoundScoringAction $applyDevelopmentTrackRoundScoring,
        private CreateTownChoiceAfterBuildingAction $createTownChoiceAfterBuilding,
        private GainPowerAction $gainPower,
    ) {
    }

    public function execute(GameStateData $state, GamePlayerStateData $player, PalaceAbility $palace): ChoosePalaceResultData
    {
        $interaction = $state->pendingInteraction;
        if ($interaction?->type !== PendingInteractionType::ChoosePalace
            || $interaction->playerId !== $player->playerId
            || ! in_array($palace->value, $interaction->optionIds, true)
            || $player->palaceId !== null) {
            throw ValidationException::withMessages(['palace_id' => 'Этот жетон Дворца недоступен.']);
        }

        $builtHexId = (string) ($interaction->context['builtHexId'] ?? '');
        $victoryPoints = $palace->buildingVictoryPoints(BuildingType::Palace);
        $player->palaceId = $palace->value;
        $player->victoryPoints += $victoryPoints;
        $gainedPower = 0;
        $gainedBooks = 0;
        $gainedSpades = 0;
        $shippingReward = ['steps' => 0, 'books' => 0, 'victoryPoints' => 0];

        if ($palace === PalaceAbility::Palace10) {
            $gainedPower = $this->gainPower->execute($player, 12);
            $gainedBooks = 2;
            $player->resources->books->unassigned += $gainedBooks;
        } elseif ($palace === PalaceAbility::Palace15) {
            $gainedBooks = 2;
            $gainedSpades = 2;
            $player->resources->books->unassigned += $gainedBooks;
            $player->unassignedSpades += $gainedSpades;
        } elseif ($palace === PalaceAbility::Palace14) {
            $shippingReward = $this->advanceDevelopmentTrack->advanceShipping($player, 2);
            $shippingReward['victoryPoints'] += $this->applyDevelopmentTrackRoundScoring->execute(
                $state,
                $player,
                $shippingReward['steps'],
            );
            $gainedBooks = $shippingReward['books'];
        }

        $state->availablePalaceIds = array_values(array_filter(
            $state->availablePalaceIds,
            static fn (string $palaceId): bool => $palaceId !== $palace->value,
        ));
        $state->pendingInteraction = null;

        if ($gainedBooks > 0) {
            $state->pendingInteraction = new PendingInteractionData(
                PendingInteractionType::ChoosePalaceBooks,
                $player->playerId,
                context: ['bookCount' => $gainedBooks, 'source' => 'palace', 'builtHexId' => $builtHexId],
            );
            $nextActiveUserId = $player->userId;
        } elseif ($palace === PalaceAbility::Palace11) {
            $state->pendingInteraction = new PendingInteractionData(
                PendingInteractionType::ChooseTown,
                $player->playerId,
                array_values(array_unique($state->availableTownTileIds)),
                ['townHexIds' => [], 'builtHexId' => $builtHexId, 'freePalaceTownTile' => true],
            );
            $nextActiveUserId = $player->userId;
        } elseif ($palace === PalaceAbility::Palace16) {
            $eligibleHexIds = array_values(array_map(
                static fn (BoardHexStateData $hex): string => $hex->id,
                array_filter(
                    $state->board->hexes,
                    static fn (BoardHexStateData $hex): bool => $hex->terrain === $player->homeland && $hex->building === null,
                ),
            ));
            $state->pendingInteraction = new PendingInteractionData(
                PendingInteractionType::PlacePalaceGuild,
                $player->playerId,
                $eligibleHexIds,
                ['palaceBuiltHexId' => $builtHexId, 'selectedHexId' => null],
            );
            $nextActiveUserId = $player->userId;
        } else {
            $nextActiveUserId = $this->createTownChoiceAfterBuilding->execute($state, $player, $builtHexId);
        }

        return new ChoosePalaceResultData(
            $nextActiveUserId,
            $builtHexId,
            $victoryPoints,
            $gainedPower,
            $gainedBooks,
            $gainedSpades,
            $shippingReward,
        );
    }
}
