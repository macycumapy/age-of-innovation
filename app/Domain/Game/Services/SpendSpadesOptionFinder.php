<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\SpendSpadesOptionData;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Enums\TerrainType;

final class SpendSpadesOptionFinder
{
    /** @return list<SpendSpadesOptionData> */
    public function execute(
        GameStateData $state,
        GamePlayerStateData $player,
        bool $requireActionPhase = true,
    ): array {
        $interaction = $state->pendingInteraction;
        if (($requireActionPhase && ! $state->round->phase->isActionPhase())
            || $interaction?->type !== PendingInteractionType::SpendSpades
            || $interaction->playerId !== $player->playerId) {
            return [];
        }

        $selectedHexId = $interaction->context['selectedHexId'] ?? null;
        $spentSpades = max(1, (int) ($interaction->context['spentSpades'] ?? 1));
        if (is_string($selectedHexId)) {
            return $player->unassignedSpades >= $spentSpades
                ? [new SpendSpadesOptionData($selectedHexId, $spentSpades)]
                : [];
        }

        $targetTerrain = TerrainType::tryFrom((string) ($interaction->context['targetTerrain'] ?? ''));
        $spadeCount = max(1, (int) ($interaction->context['spadesToSpend'] ?? 1));
        if ($targetTerrain === null || $player->unassignedSpades < $spadeCount) {
            return [];
        }

        $options = [];
        foreach ($interaction->optionIds as $hexId) {
            $hex = is_string($hexId)
                ? collect($state->board->hexes)->firstWhere('id', $hexId)
                : null;
            if ($hex instanceof BoardHexStateData
                && $hex->building === null
                && $hex->terrain->isHomeland()
                && $hex->terrain !== $targetTerrain) {
                $options[] = new SpendSpadesOptionData($hexId, $spadeCount);
            }
        }

        return $options;
    }
}
