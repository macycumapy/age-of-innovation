<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\BuildingStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PlacePalaceGuildResultData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\PendingInteractionType;
use Illuminate\Validation\ValidationException;

final class ApplyPlacePalaceGuildAction
{
    public function __construct(
        private ApplyBuildingBonusesAction $applyBuildingBonuses,
        private CreateTownChoiceAfterBuildingAction $createTownChoiceAfterBuilding,
    ) {
    }

    public function execute(
        GameStateData $state,
        GamePlayerStateData $player,
        string $hexId,
    ): PlacePalaceGuildResultData {
        $interaction = $state->pendingInteraction;
        $hex = collect($state->board->hexes)->firstWhere('id', $hexId);
        $selectedHexId = $interaction?->context['selectedHexId'] ?? null;

        if ($interaction?->type !== PendingInteractionType::PlacePalaceGuild
            || $interaction->playerId !== $player->playerId
            || ($selectedHexId !== null && $selectedHexId !== $hexId)
            || ! $hex instanceof BoardHexStateData
            || ! in_array($hexId, $interaction->optionIds, true)) {
            throw ValidationException::withMessages(['hex_id' => 'Здесь нельзя разместить бесплатный рынок.']);
        }

        if ($selectedHexId === null) {
            if ($hex->building !== null || $hex->terrain !== $player->homeland) {
                throw ValidationException::withMessages(['hex_id' => 'Выберите свободную ячейку родной местности.']);
            }

            $hex->building = new BuildingStateData(BuildingType::Guild, $player->playerId);
        } elseif ($hex->building?->type !== BuildingType::Guild
            || $hex->building->ownerPlayerId !== $player->playerId) {
            throw ValidationException::withMessages(['hex_id' => 'Размещённый рынок не найден.']);
        }

        $palaceBuiltHexId = $interaction->context['palaceBuiltHexId'] ?? null;
        $bonuses = $this->applyBuildingBonuses->execute($state, $player, $hex, BuildingType::Guild);
        $state->pendingInteraction = null;

        return new PlacePalaceGuildResultData(
            nextActivePlayerId: $this->createTownChoiceAfterBuilding->execute(
                $state,
                $player,
                $hexId,
                is_string($palaceBuiltHexId) && $palaceBuiltHexId !== '' ? [$palaceBuiltHexId] : [],
            ),
            palaceBuiltHexId: is_string($palaceBuiltHexId) ? $palaceBuiltHexId : '',
            bonuses: $bonuses,
        );
    }
}
