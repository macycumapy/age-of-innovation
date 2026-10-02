<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Turns\Actions;

use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use Illuminate\Validation\ValidationException;

final class ApplyFinishActionTurnAction
{
    public function execute(GameStateData $state, GamePlayerStateData $player): int
    {
        if (! $state->round->phase->isActionPhase() || ! $state->round->hasTakenMainAction) {
            throw ValidationException::withMessages(['game' => 'Сейчас нельзя завершить ход.']);
        }

        $currentIndex = array_search($player->playerId, $state->turnOrder, true);
        $nextPlayerId = null;

        if ($currentIndex !== false) {
            foreach (range(1, count($state->turnOrder)) as $offset) {
                $candidateId = $state->turnOrder[($currentIndex + $offset) % count($state->turnOrder)];

                if (! in_array($candidateId, $state->passedPlayerIds, true)) {
                    $nextPlayerId = $candidateId;
                    break;
                }
            }
        }

        if ($nextPlayerId === null) {
            throw ValidationException::withMessages(['game' => 'Не удалось определить следующего игрока.']);
        }

        $state->pendingInteraction = null;
        $state->turnStartSnapshot = null;
        $state->townChoiceCheckpoint = null;
        $state->round->turnStartVersion = null;
        $state->round->hasTakenMainAction = false;
        $state->round->isCurrentTurnIrrevocable = false;

        return $nextPlayerId;
    }
}
