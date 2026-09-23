<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PlacePalaceGuildOptionData;
use App\Domain\Game\Enums\PendingInteractionType;

final class PlacePalaceGuildOptionFinder
{
    /** @return list<PlacePalaceGuildOptionData> */
    public function execute(GameStateData $state, GamePlayerStateData $player): array
    {
        $interaction = $state->pendingInteraction;
        if ($interaction?->type !== PendingInteractionType::PlacePalaceGuild
            || $interaction->playerId !== $player->playerId) {
            return [];
        }

        $selectedHexId = $interaction->context['selectedHexId'] ?? null;
        $hexIds = is_string($selectedHexId)
            ? [$selectedHexId]
            : array_values(array_filter($interaction->optionIds, 'is_string'));

        return array_map(
            static fn (string $hexId): PlacePalaceGuildOptionData => new PlacePalaceGuildOptionData($hexId),
            $hexIds,
        );
    }
}
