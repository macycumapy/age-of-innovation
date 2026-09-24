<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\BridgeStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PlaceBridgeResultData;
use App\Domain\Game\Enums\PendingInteractionType;
use Illuminate\Validation\ValidationException;

final class ApplyPlaceBridgeAction
{
    public function __construct(
        private FindEligibleBridgePairsAction $findEligibleBridgePairs,
        private CreateTownChoiceAfterBuildingAction $createTownChoiceAfterBuilding,
    ) {
    }

    public function execute(
        GameStateData $state,
        GamePlayerStateData $player,
        string $fromHexId,
        string $toHexId,
    ): PlaceBridgeResultData {
        $interaction = $state->pendingInteraction;
        $source = $interaction?->context['source'] ?? null;
        $pairs = $interaction?->type === PendingInteractionType::PlaceBridge
            ? $this->findEligibleBridgePairs->execute(
                $state,
                $player->playerId,
                canBuildAcrossTerrain: $source === 'faction',
            )
            : [];

        if ($interaction?->playerId !== $player->playerId
            || ! in_array($source, ['power', 'round_bonus', 'faction'], true)
            || ! $this->containsPair($pairs, $fromHexId, $toHexId)) {
            throw ValidationException::withMessages(['bridge' => 'Это место недоступно для строительства моста.']);
        }

        $state->board->bridges[] = new BridgeStateData($fromHexId, $toHexId, $player->playerId);
        $nextActivePlayerId = $this->createTownChoiceAfterBuilding->execute(
            $state,
            $player,
            $fromHexId,
            powerOffersResolved: true,
        );

        return new PlaceBridgeResultData($nextActivePlayerId, $source);
    }

    /** @param list<array{fromHexId: string, toHexId: string}> $pairs */
    private function containsPair(array $pairs, string $fromHexId, string $toHexId): bool
    {
        return collect($pairs)->contains(
            static fn (array $pair): bool => $pair['fromHexId'] === $fromHexId
                && $pair['toHexId'] === $toHexId,
        );
    }
}
