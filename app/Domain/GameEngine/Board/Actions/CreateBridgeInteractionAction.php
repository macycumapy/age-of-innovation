<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Board\Actions;

use App\Domain\GameEngine\Board\Services\BridgeSupply;
use App\Domain\GameEngine\Interactions\Data\PendingInteractionData;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use Illuminate\Validation\ValidationException;

final class CreateBridgeInteractionAction
{
    public function __construct(
        private FindEligibleBridgePairsAction $findEligibleBridgePairs,
        private BridgeSupply $bridgeSupply,
    ) {
    }

    public function execute(
        GameStateData $state,
        GamePlayerStateData $playerState,
        bool $canBuildAcrossTerrain = false,
    ): void {
        if ($this->bridgeSupply->remaining($state, $playerState) === 0) {
            throw ValidationException::withMessages(['bridge' => 'У игрока не осталось мостов.']);
        }

        $pairs = $this->findEligibleBridgePairs->execute(
            $state,
            $playerState->playerId,
            $canBuildAcrossTerrain,
        );

        if ($pairs === []) {
            throw ValidationException::withMessages(['bridge' => 'Нет доступного места для строительства моста.']);
        }

        $state->pendingInteraction = new PendingInteractionData(
            PendingInteractionType::PlaceBridge,
            $playerState->playerId,
            array_values(array_unique(array_merge(
                array_column($pairs, 'fromHexId'),
                array_column($pairs, 'toHexId'),
            ))),
            ['pairs' => $pairs],
        );
    }
}
