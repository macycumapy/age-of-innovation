<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Board\Services;

use App\Domain\GameEngine\Board\Actions\FindEligibleBridgePairsAction;
use App\Domain\GameEngine\Board\Data\PlaceBridgeOptionData;
use App\Domain\GameEngine\Board\Data\SkipBridgeOptionData;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;

final class PlaceBridgeOptionFinder
{
    public function __construct(
        private FindEligibleBridgePairsAction $findEligibleBridgePairs,
        private BridgeSupply $bridgeSupply,
    ) {
    }

    /** @return list<PlaceBridgeOptionData|SkipBridgeOptionData> */
    public function execute(GameStateData $state, GamePlayerStateData $player): array
    {
        $interaction = $state->pendingInteraction;
        if ($interaction?->type !== PendingInteractionType::PlaceBridge
            || $interaction->playerId !== $player->playerId) {
            return [];
        }

        if ($this->bridgeSupply->remaining($state, $player) === 0) {
            return ($interaction->context['source'] ?? null) === 'palace_15'
                ? [new SkipBridgeOptionData()]
                : [];
        }

        $selectedFromHexId = $interaction->context['selectedFromHexId'] ?? null;
        $selectedToHexId = $interaction->context['selectedToHexId'] ?? null;
        if (is_string($selectedFromHexId) && is_string($selectedToHexId)) {
            return [new PlaceBridgeOptionData($selectedFromHexId, $selectedToHexId)];
        }

        $options = array_map(
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

        if (($interaction->context['source'] ?? null) === 'palace_15') {
            $options[] = new SkipBridgeOptionData();
        }

        return $options;
    }
}
