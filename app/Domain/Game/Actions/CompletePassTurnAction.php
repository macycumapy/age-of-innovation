<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Enums\GamePhase;
use App\Models\GamePlayer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

final class CompletePassTurnAction
{
    public function __construct(private ResolveScienceBonusPhaseAction $resolveScienceBonusPhase)
    {
    }

    /**
     * @param Collection<int, GamePlayer> $players
     * @return array{nextActiveUserId: int|null, phase: GamePhase, nextRoundStarted: bool, incomeReceipts: array, finalScoring: array, scienceBonusReceipts?: array}
     */
    public function execute(GameStateData $state, int $currentPlayerId, Collection $players): array
    {
        $state->pendingInteraction = null;
        $state->round->isCurrentTurnIrrevocable = false;

        if (count($state->passedPlayerIds) < count($state->turnOrder)) {
            $currentIndex = array_search($currentPlayerId, $state->turnOrder, true);

            foreach (range(1, count($state->turnOrder)) as $offset) {
                $candidateId = $state->turnOrder[((int) $currentIndex + $offset) % count($state->turnOrder)];

                if (! in_array($candidateId, $state->passedPlayerIds, true)) {
                    $candidate = $players->firstWhere('id', $candidateId);

                    if ($candidate instanceof GamePlayer) {
                        return [
                            'nextActiveUserId' => $candidate->user_id,
                            'phase' => GamePhase::Actions,
                            'nextRoundStarted' => false,
                            'incomeReceipts' => [],
                            'finalScoring' => [],
                        ];
                    }
                }
            }

            throw ValidationException::withMessages(['game' => 'Не удалось определить следующего игрока.']);
        }

        $state->turnOrder = $state->passedPlayerIds;
        $state->passedPlayerIds = [];
        $state->round->phase = GamePhase::ScienceBonus;
        $state->round->scienceBonusTurnIndex = 0;
        [$nextPlayer, $phase, $incomeReceipts, $finalScoring, $scienceBonusReceipts] = $this->resolveScienceBonusPhase->execute($state, $players);

        return [
            'nextActiveUserId' => $nextPlayer?->user_id,
            'phase' => $phase,
            'nextRoundStarted' => $phase !== GamePhase::ScienceBonus,
            'incomeReceipts' => $incomeReceipts,
            'finalScoring' => $finalScoring,
            'scienceBonusReceipts' => $scienceBonusReceipts,
        ];
    }
}
