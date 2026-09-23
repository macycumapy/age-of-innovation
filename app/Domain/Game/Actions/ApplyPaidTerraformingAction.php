<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PaidTerraformingOptionData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Services\PaidTerraformingOptionFinder;
use Illuminate\Validation\ValidationException;

final class ApplyPaidTerraformingAction
{
    public function __construct(private PaidTerraformingOptionFinder $optionFinder)
    {
    }

    public function execute(
        GameStateData $state,
        GamePlayerStateData $player,
        PaidTerraformingOptionData $option,
    ): void {
        $matchingOption = collect($this->optionFinder->execute($state, $player))->first(
            static fn (PaidTerraformingOptionData $candidate): bool => $candidate->hexId === $option->hexId
                && $candidate->useAvailable === $option->useAvailable
                && $candidate->useTunnel === $option->useTunnel
                && $candidate->useFlight === $option->useFlight,
        );

        if (! $matchingOption instanceof PaidTerraformingOptionData) {
            throw ValidationException::withMessages(['hex_id' => 'Эта клетка недоступна для преобразования.']);
        }

        $interaction = $state->pendingInteraction;
        $isExistingSpadeInteraction = $interaction?->type === PendingInteractionType::SpendSpades
            && $interaction->playerId === $player->playerId
            && ! isset($interaction->context['selectedHexId']);
        $targetHex = collect($state->board->hexes)->firstWhere('id', $matchingOption->hexId);

        if (! $targetHex instanceof BoardHexStateData) {
            throw ValidationException::withMessages(['hex_id' => 'Эта клетка недоступна для преобразования.']);
        }

        $purchasedSpadeCount = $matchingOption->useAvailable
            ? 0
            : max(0, $matchingOption->spadeCount - $player->unassignedSpades);
        $spadesToSpend = $matchingOption->useAvailable
            ? min($matchingOption->spadeCount, $player->unassignedSpades)
            : $matchingOption->spadeCount;
        $tunnelToolCost = $matchingOption->useTunnel ? 1 : 0;
        $tunnelVictoryPoints = $matchingOption->useTunnel ? 2 + count($state->players) : 0;
        $flightVictoryPoints = $matchingOption->useFlight ? 5 : 0;

        $player->resources->tools -= $matchingOption->toolCost;
        $player->resources->scholars -= $matchingOption->scholarCost;
        $player->unassignedSpades += $purchasedSpadeCount;

        if ($state->round->phase->isActionPhase()) {
            $state->round->hasTakenMainAction = true;
        }

        if ($isExistingSpadeInteraction) {
            $interaction->context['optionIdsBeforeSelection'] = $interaction->optionIds;
            $interaction->optionIds = [$matchingOption->hexId];
            $interaction->context['remainingSpades'] = (int) ($interaction->context['remainingSpades'] ?? 0)
                + $purchasedSpadeCount;
            $interaction->context['paidTools'] = (int) ($interaction->context['paidTools'] ?? 0)
                + $matchingOption->toolCost;
            $interaction->context['paidSpadeCount'] = (int) ($interaction->context['paidSpadeCount'] ?? 0)
                + $purchasedSpadeCount;
            $interaction->context['spadesToSpend'] = $spadesToSpend;
            $interaction->context['tunnelTools'] = $tunnelToolCost;
            $interaction->context['tunnelVictoryPoints'] = $tunnelVictoryPoints;
            $interaction->context['flightScholarCost'] = $matchingOption->scholarCost;
            $interaction->context['flightVictoryPoints'] = $flightVictoryPoints;
        } else {
            $state->pendingInteraction = new PendingInteractionData(
                PendingInteractionType::SpendSpades,
                $player->playerId,
                [$matchingOption->hexId],
                [
                    'phase' => GamePhase::Actions->value,
                    'spadeCount' => $matchingOption->spadeCount,
                    'remainingSpades' => $matchingOption->spadeCount,
                    'targetTerrain' => $player->homeland->value,
                    'paidTools' => $matchingOption->toolCost,
                    'paidSpadeCount' => $purchasedSpadeCount,
                    'spadesToSpend' => $spadesToSpend,
                    'tunnelTools' => $tunnelToolCost,
                    'tunnelVictoryPoints' => $tunnelVictoryPoints,
                    'flightScholarCost' => $matchingOption->scholarCost,
                    'flightVictoryPoints' => $flightVictoryPoints,
                ],
            );
        }

        $player->victoryPoints += $tunnelVictoryPoints + $flightVictoryPoints;
    }
}
