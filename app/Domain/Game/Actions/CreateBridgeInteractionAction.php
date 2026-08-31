<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Enums\PendingInteractionType;
use Illuminate\Validation\ValidationException;

final class CreateBridgeInteractionAction
{
    public function __construct(private FindEligibleBridgePairsAction $findEligibleBridgePairs)
    {
    }

    public function execute(GameStateData $state, GamePlayerStateData $playerState): void
    {
        $builtBridgeCount = collect($state->board->bridges)
            ->where('ownerPlayerId', $playerState->playerId)
            ->count();

        if ($builtBridgeCount >= 3) {
            throw ValidationException::withMessages(['bridge' => 'У игрока не осталось мостов.']);
        }

        $pairs = $this->findEligibleBridgePairs->execute($state, $playerState->playerId);

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
