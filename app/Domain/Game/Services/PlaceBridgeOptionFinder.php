<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Actions\FindEligibleBridgePairsAction;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PlaceBridgeOptionData;
use App\Domain\Game\Enums\PendingInteractionType;

final class PlaceBridgeOptionFinder
{
    public function __construct(private FindEligibleBridgePairsAction $findEligibleBridgePairs)
    {
    }

    /** @return list<PlaceBridgeOptionData> */
    public function execute(GameStateData $state, GamePlayerStateData $player): array
    {
        $interaction = $state->pendingInteraction;
        if ($interaction?->type !== PendingInteractionType::PlaceBridge
            || $interaction->playerId !== $player->playerId) {
            return [];
        }

        $selectedFromHexId = $interaction->context['selectedFromHexId'] ?? null;
        $selectedToHexId = $interaction->context['selectedToHexId'] ?? null;
        if (is_string($selectedFromHexId) && is_string($selectedToHexId)) {
            return [new PlaceBridgeOptionData($selectedFromHexId, $selectedToHexId)];
        }

        return array_map(
            static fn (array $pair): PlaceBridgeOptionData => new PlaceBridgeOptionData(
                $pair['fromHexId'],
                $pair['toHexId'],
            ),
            $this->findEligibleBridgePairs->execute(
                $state,
                $player->playerId,
                canBuildAcrossTerrain: ($interaction->context['source'] ?? null) === 'faction',
            ),
        );
    }
}
