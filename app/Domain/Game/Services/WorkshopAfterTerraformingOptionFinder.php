<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\WorkshopAfterTerraformingOptionData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\PendingInteractionType;

final class WorkshopAfterTerraformingOptionFinder
{
    /** @return list<WorkshopAfterTerraformingOptionData> */
    public function execute(GameStateData $state, GamePlayerStateData $player): array
    {
        $interaction = $state->pendingInteraction;

        if ($interaction?->type !== PendingInteractionType::BuildWorkshopAfterTerraforming
            || $interaction->playerId !== $player->playerId) {
            return [];
        }

        $options = [new WorkshopAfterTerraformingOptionData(false)];
        $toolCost = max(0, (int) ($interaction->context['toolCost'] ?? 1));
        $coinCost = max(0, (int) ($interaction->context['coinCost'] ?? 2));
        $workshopsOnMap = count(array_filter(
            $state->board->hexes,
            static fn (BoardHexStateData $hex): bool => $hex->building?->ownerPlayerId === $player->playerId
                && $hex->building->type === BuildingType::Workshop
                && ! $hex->building->isNeutral,
        ));

        if ($player->resources->tools < $toolCost
            || $player->resources->coins < $coinCost
            || $workshopsOnMap >= BuildingType::Workshop->supplyLimit()) {
            return $options;
        }

        foreach ($interaction->optionIds as $hexId) {
            if (! is_string($hexId)) {
                continue;
            }

            $hex = collect($state->board->hexes)->firstWhere('id', $hexId);

            if ($hex instanceof BoardHexStateData
                && $hex->building === null
                && $hex->terrain === $player->homeland) {
                $options[] = new WorkshopAfterTerraformingOptionData(true, $hexId);
            }
        }

        return $options;
    }
}
