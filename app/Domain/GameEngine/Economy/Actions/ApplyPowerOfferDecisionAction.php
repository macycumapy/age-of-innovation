<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Economy\Actions;

use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Interactions\Actions\CreateBuildingFollowUpInteractionAction;
use App\Domain\GameEngine\Interactions\Data\PendingInteractionData;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Towns\Actions\CreateTownChoiceAfterBuildingAction;
use App\Domain\GameEngine\Towns\Actions\StartFelineTownBonusAction;
use Illuminate\Validation\ValidationException;

final class ApplyPowerOfferDecisionAction
{
    public function __construct(
        private CreatePowerOffersAfterBuildingAction $createPowerOffersAfterBuilding,
        private CreateTownChoiceAfterBuildingAction $createTownChoiceAfterBuilding,
        private GainPowerAction $gainPower,
        private StartFelineTownBonusAction $startFelineTownBonus,
        private CreateBuildingFollowUpInteractionAction $createBuildingFollowUpInteraction,
    ) {
    }

    /** @return array{receivedPower: int, victoryPointsSpent: int, nextActivePlayerId: int, advanceTurnCheckpoint: bool} */
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
        $powerAcceptedDuringOfferChain = ($interaction->context['powerAcceptedDuringOfferChain'] ?? false) === true
            || $receivedPower > 0;

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
                    ...(isset($interaction->context['queuedTownHexIds'])
                        ? ['queuedTownHexIds' => $interaction->context['queuedTownHexIds']]
                        : []),
                    ...(isset($interaction->context['buildingFollowUpType'])
                        ? ['buildingFollowUpType' => $interaction->context['buildingFollowUpType']]
                        : []),
                    ...(isset($interaction->context['queuedBuiltHexIds'])
                        ? ['queuedBuiltHexIds' => $interaction->context['queuedBuiltHexIds']]
                        : []),
                    ...(isset($interaction->context['townBuiltHexId'])
                        ? ['townBuiltHexId' => $interaction->context['townBuiltHexId']]
                        : []),
                    ...(isset($interaction->context['felineBonusPending'])
                        ? ['felineBonusPending' => $interaction->context['felineBonusPending']]
                        : []),
                    ...($powerAcceptedDuringOfferChain ? ['powerAcceptedDuringOfferChain' => true] : []),
                ],
            );
            $nextActivePlayerId = (int) $nextOffer['playerId'];
        } else {
            $buildingPlayer = collect($state->players)->firstWhere(
                'playerId',
                (int) $interaction->context['buildingPlayerId'],
            );

            if (! $buildingPlayer instanceof GamePlayerStateData) {
                throw ValidationException::withMessages(['game' => 'Не найден построивший здание игрок.']);
            }

            $queuedBuiltHexIdsContext = $interaction->context['queuedBuiltHexIds'] ?? [];
            $queuedBuiltHexIds = is_array($queuedBuiltHexIdsContext)
                ? array_values(array_filter($queuedBuiltHexIdsContext, is_string(...)))
                : [];
            $nextBuiltHexId = array_shift($queuedBuiltHexIds);
            $nextActivePlayerId = is_string($nextBuiltHexId)
                ? $this->createPowerOffersAfterBuilding->execute(
                    $state,
                    $buildingPlayer->playerId,
                    $nextBuiltHexId,
                    $queuedBuiltHexIds,
                )
                : null;

            if ($nextActivePlayerId !== null
                && $state->pendingInteraction->type === PendingInteractionType::PowerOffer
                && isset($interaction->context['townBuiltHexId'])) {
                $state->pendingInteraction->context['townBuiltHexId'] = $interaction->context['townBuiltHexId'];
            }

            if ($nextActivePlayerId !== null
                && $state->pendingInteraction->type === PendingInteractionType::PowerOffer
                && $powerAcceptedDuringOfferChain) {
                $state->pendingInteraction->context['powerAcceptedDuringOfferChain'] = true;
            }

            if ($nextActivePlayerId === null) {
                if (isset($interaction->context['buildingFollowUpType'])) {
                    $nextActivePlayerId = $this->createBuildingFollowUpInteraction->execute(
                        $state,
                        $buildingPlayer,
                        (string) $interaction->context['builtHexId'],
                        BuildingType::from($interaction->context['buildingFollowUpType']),
                        powerOffersResolved: true,
                        queuedTownHexIds: (array) ($interaction->context['queuedTownHexIds'] ?? []),
                    );
                } elseif (($interaction->context['felineBonusPending'] ?? false) === true) {
                    $this->startFelineTownBonus->execute($state, $buildingPlayer, [
                        'continueBuildingAfterPowerHexId' => (string) $interaction->context['builtHexId'],
                    ]);
                    $nextActivePlayerId = $buildingPlayer->playerId;
                } else {
                    $townBuiltHexId = $interaction->context['townBuiltHexId'] ?? null;
                    if (is_string($townBuiltHexId)) {
                        $nextActivePlayerId = $this->createTownChoiceAfterBuilding->execute(
                            $state,
                            $buildingPlayer,
                            $townBuiltHexId,
                            powerOffersResolved: true,
                        );
                    } else {
                        $state->pendingInteraction = null;
                        $nextActivePlayerId = $buildingPlayer->playerId;
                    }
                }
            }
        }

        return [
            'receivedPower' => $receivedPower,
            'victoryPointsSpent' => $victoryPointsSpent,
            'nextActivePlayerId' => $nextActivePlayerId,
            'advanceTurnCheckpoint' => $powerAcceptedDuringOfferChain
                && $state->pendingInteraction?->type !== PendingInteractionType::PowerOffer,
        ];
    }
}
