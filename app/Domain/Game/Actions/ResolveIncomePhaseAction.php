<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Enums\GamePhase;
use App\Models\GamePlayer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

final class ResolveIncomePhaseAction
{
    public function __construct(private ApplyIncomeAction $applyIncome)
    {
    }

    /**
     * @param Collection<int, GamePlayer> $players
     * @return array{GamePlayer, GamePhase}
     */
    public function execute(GameStateData $state, Collection $players): array
    {
        while ($state->round->incomeTurnIndex < count($state->turnOrder)) {
            $playerId = $state->turnOrder[$state->round->incomeTurnIndex];
            $player = $players->firstWhere('id', $playerId);
            $playerState = collect($state->players)->firstWhere('playerId', $playerId);

            if (! $player instanceof GamePlayer || ! $playerState instanceof GamePlayerStateData) {
                throw ValidationException::withMessages(['game' => 'Нарушен порядок получения дохода.']);
            }

            $this->applyIncome->execute($state, $playerState);
            $state->round->incomeTurnIndex++;

            if ($playerState->resources->books->unassigned > 0
                || $playerState->knowledge->unassignedSteps > 0) {
                $state->round->phase = GamePhase::Income;

                return [$player, GamePhase::Income];
            }
        }

        $firstPlayer = $players->firstWhere('id', $state->turnOrder[0] ?? null);

        if (! $firstPlayer instanceof GamePlayer) {
            throw ValidationException::withMessages(['game' => 'Не найден первый игрок нового раунда.']);
        }

        $state->round->phase = GamePhase::Actions;

        return [$firstPlayer, GamePhase::Actions];
    }
}
