<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\InnovationSpecialActionOptionData;
use App\Domain\Game\Data\InnovationSpecialActionResultData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\Innovation;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Services\InnovationSpecialActionOptionFinder;
use Illuminate\Validation\ValidationException;

final class ApplyInnovationSpecialAction
{
    public function __construct(
        private InnovationSpecialActionOptionFinder $optionFinder,
        private FindEligibleTerraformHexesAction $findEligibleTerraformHexes,
    ) {
    }

    public function execute(
        GameStateData $state,
        GamePlayerStateData $player,
        InnovationSpecialActionOptionData $option,
    ): InnovationSpecialActionResultData {
        $isAvailable = collect($this->optionFinder->execute($state, $player))->contains(
            static fn (InnovationSpecialActionOptionData $candidate): bool => $candidate->innovation === $option->innovation,
        );

        if (! $isAvailable) {
            throw ValidationException::withMessages(['innovation' => 'Особое действие этой инновации недоступно.']);
        }

        $result = new InnovationSpecialActionResultData();

        if ($option->innovation === Innovation::Professor) {
            $scholarsBefore = $player->resources->scholars;
            $player->resources->scholars = min($player->scholarPoolSize, $player->resources->scholars + 1);
            $player->victoryPoints += 3;
            $result->scholars = $player->resources->scholars - $scholarsBefore;
            $result->victoryPoints = 3;
        } else {
            $player->unassignedSpades++;
            $result->spades = 1;
            $eligibleHexIds = $this->findEligibleTerraformHexes->execute($state, $player, $player->homeland);

            if ($eligibleHexIds !== []) {
                $state->pendingInteraction = new PendingInteractionData(
                    PendingInteractionType::SpendSpades,
                    $player->playerId,
                    $eligibleHexIds,
                    [
                        'phase' => GamePhase::Actions->value,
                        'spadeCount' => 1,
                        'remainingSpades' => 1,
                        'targetTerrain' => $player->homeland->value,
                    ],
                );
            }
        }

        $player->usedSpecialActionIds[] = $option->innovation->specialActionId();
        $state->round->hasTakenMainAction = true;

        return $result;
    }
}
