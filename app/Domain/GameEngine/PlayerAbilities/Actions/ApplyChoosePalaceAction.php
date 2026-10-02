<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\PlayerAbilities\Actions;

use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Economy\Actions\GainPowerAction;
use App\Domain\GameEngine\Interactions\Actions\AdvancePendingInteractionQueueAction;
use App\Domain\GameEngine\Interactions\Data\PendingInteractionData;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\PlayerAbilities\Data\ChoosePalaceResultData;
use App\Domain\GameEngine\PlayerAbilities\Enums\PalaceAbility;
use App\Domain\GameEngine\Research\Actions\AdvanceDevelopmentTrackAction;
use App\Domain\GameEngine\Scoring\Actions\ApplyDevelopmentTrackRoundScoringAction;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Towns\Actions\CreateTownChoiceAfterBuildingAction;
use Illuminate\Validation\ValidationException;

final class ApplyChoosePalaceAction
{
    public function __construct(
        private AdvanceDevelopmentTrackAction $advanceDevelopmentTrack,
        private ApplyDevelopmentTrackRoundScoringAction $applyDevelopmentTrackRoundScoring,
        private CreateTownChoiceAfterBuildingAction $createTownChoiceAfterBuilding,
        private GainPowerAction $gainPower,
        private AdvancePendingInteractionQueueAction $advancePendingInteractionQueue,
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

        if ($palace === PalaceAbility::Palace15) {
            $gainedBooks = 2;
            $gainedSpades = 2;
            $player->resources->books->unassigned += $gainedBooks;
            $player->unassignedSpades += $gainedSpades;

            $stepContext = ['builtHexId' => $builtHexId];
            $state->pendingInteractionQueue = [
                new PendingInteractionData(
                    PendingInteractionType::ChoosePalaceBooks,
                    $player->playerId,
                    context: ['bookCount' => $gainedBooks, 'source' => 'palace', ...$stepContext],
                ),
                new PendingInteractionData(PendingInteractionType::SpendSpades, $player->playerId, context: $stepContext),
                new PendingInteractionData(PendingInteractionType::PlaceBridge, $player->playerId, context: $stepContext),
                new PendingInteractionData(PendingInteractionType::PlaceBridge, $player->playerId, context: $stepContext),
            ];
            $nextActivePlayerId = $this->advancePendingInteractionQueue->execute($state, $player, $builtHexId);
        } elseif ($gainedBooks > 0) {
            $state->pendingInteraction = new PendingInteractionData(
                PendingInteractionType::ChoosePalaceBooks,
                $player->playerId,
                context: ['bookCount' => $gainedBooks, 'source' => 'palace', 'builtHexId' => $builtHexId],
            );
            $nextActivePlayerId = $player->playerId;
        } elseif ($palace === PalaceAbility::Palace11) {
            $state->pendingInteraction = new PendingInteractionData(
                PendingInteractionType::ChooseTown,
                $player->playerId,
                array_values(array_unique($state->availableTownTileIds)),
                ['townHexIds' => [], 'builtHexId' => $builtHexId, 'freePalaceTownTile' => true],
            );
            $nextActivePlayerId = $player->playerId;
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
            $nextActivePlayerId = $player->playerId;
        } else {
            $nextActivePlayerId = $this->createTownChoiceAfterBuilding->execute($state, $player, $builtHexId);
        }

        return new ChoosePalaceResultData(
            $nextActivePlayerId,
            $builtHexId,
            $victoryPoints,
            $gainedPower,
            $gainedBooks,
            $gainedSpades,
            $shippingReward,
        );
    }
}
