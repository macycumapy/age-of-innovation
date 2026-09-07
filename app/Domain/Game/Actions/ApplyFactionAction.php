<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use Illuminate\Validation\ValidationException;

final class ApplyFactionAction
{
    public function __construct(
        private GainPowerAction $gainPower,
        private CreateBridgeInteractionAction $createBridgeInteraction,
    ) {
    }

    public function execute(
        GameStateData $state,
        GamePlayerStateData $playerState,
        ?KnowledgeDiscipline $discipline,
    ): void {
        $faction = $playerState->faction;
        $actionId = $faction->specialActionId();

        if (! $faction->hasSpecialAction()
            || ($faction !== Faction::Moles && in_array($actionId, $playerState->usedSpecialActionIds, true))) {
            throw ValidationException::withMessages(['faction' => 'Действие этой расы недоступно.']);
        }

        if ($faction === Faction::Moles) {
            if ($state->round->hasTakenMainAction || $playerState->resources->tools < 1) {
                throw ValidationException::withMessages(['faction' => 'Недостаточно инструментов или основное действие уже выполнено.']);
            }

            $this->createBridgeInteraction->execute($state, $playerState, canBuildAcrossTerrain: true);
            $playerState->resources->tools--;
            $state->round->hasTakenMainAction = true;
        }

        if ($faction === Faction::Philosophers) {
            if ($discipline === null) {
                throw ValidationException::withMessages(['discipline' => 'Выберите книгу.']);
            }

            $playerState->resources->books->{$discipline->value}++;
            $state->round->hasTakenMainAction = true;
        }

        if ($faction === Faction::Psychics) {
            $this->gainPower->execute($playerState, 5);
        }

        if ($faction !== Faction::Moles) {
            $playerState->usedSpecialActionIds[] = $actionId;
        }
    }
}
