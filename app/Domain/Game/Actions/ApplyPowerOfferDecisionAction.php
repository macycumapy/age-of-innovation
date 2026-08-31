<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Enums\PendingInteractionType;
use Illuminate\Validation\ValidationException;

final class ApplyPowerOfferDecisionAction
{
    public function __construct(
        private CreatePowerOffersAfterBuildingAction $createPowerOffersAfterBuilding,
        private GainPowerAction $gainPower,
    ) {
    }

    /** @return array{receivedPower: int, victoryPointsSpent: int, nextActiveUserId: int} */
    public function execute(GameStateData $state, int $playerId, bool $accept): array
    {
        $interaction = $state->pendingInteraction;
        $playerState = collect($state->players)->firstWhere('playerId', $playerId);

        if ($interaction?->type !== PendingInteractionType::PowerOffer
            || $interaction->playerId !== $playerId
            || ! $playerState instanceof GamePlayerStateData) {
            throw ValidationException::withMessages(['game' => 'Для игрока нет предложения Силы.']);
        }

        $receivedPower = $accept
            ? $this->gainPower->execute($playerState, (int) ($interaction->context['powerAmount'] ?? 0))
            : 0;
        $victoryPointsSpent = $accept ? max(0, $receivedPower - 1) : 0;
        $playerState->victoryPoints -= $victoryPointsSpent;

        if ($receivedPower > 0) {
            $state->round->isCurrentTurnIrrevocable = true;
        }

        $remainingOffers = $interaction->context['remainingOffers'] ?? [];
        $nextOffer = array_shift($remainingOffers);

        if (is_array($nextOffer)) {
            $state->pendingInteraction = new PendingInteractionData(
                PendingInteractionType::PowerOffer,
                (int) $nextOffer['playerId'],
                [],
                [
                    'buildingPlayerId' => (int) $interaction->context['buildingPlayerId'],
                    'builtHexId' => (string) $interaction->context['builtHexId'],
                    'powerAmount' => (int) $nextOffer['powerAmount'],
                    'remainingOffers' => $remainingOffers,
                    ...(isset($interaction->context['queuedBuiltHexIds'])
                        ? ['queuedBuiltHexIds' => $interaction->context['queuedBuiltHexIds']]
                        : []),
                ],
            );
            $nextActiveUserId = (int) $nextOffer['userId'];
        } else {
            $buildingPlayer = collect($state->players)->firstWhere(
                'playerId',
                (int) $interaction->context['buildingPlayerId'],
            );

            if (! $buildingPlayer instanceof GamePlayerStateData) {
                throw ValidationException::withMessages(['game' => 'Не найден построивший здание игрок.']);
            }

            $queuedBuiltHexIds = $interaction->context['queuedBuiltHexIds'] ?? [];
            $nextBuiltHexId = array_shift($queuedBuiltHexIds);
            $nextActiveUserId = is_string($nextBuiltHexId)
                ? $this->createPowerOffersAfterBuilding->execute(
                    $state,
                    $buildingPlayer->playerId,
                    $nextBuiltHexId,
                    $queuedBuiltHexIds,
                )
                : null;

            if ($nextActiveUserId === null) {
                $state->pendingInteraction = null;
                $nextActiveUserId = $buildingPlayer->userId;
            }
        }

        return [
            'receivedPower' => $receivedPower,
            'victoryPointsSpent' => $victoryPointsSpent,
            'nextActiveUserId' => $nextActiveUserId,
        ];
    }
}
