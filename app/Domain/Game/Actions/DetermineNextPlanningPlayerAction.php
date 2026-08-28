<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Validation\ValidationException;

final class DetermineNextPlanningPlayerAction
{
    public function __construct(private DetermineStartingBuildingOrderAction $determineStartingBuildingOrder)
    {
    }

    public function execute(Game $game, GamePlayer $currentPlayer): GamePlayer
    {
        $playersById = $game->players()->get()->keyBy('id');
        $turnOrder = $game->state->turnOrder;
        $currentIndex = array_search($currentPlayer->id, $turnOrder, true);

        if ($currentIndex === false) {
            throw ValidationException::withMessages([
                'game' => 'Нарушен порядок игроков в состоянии партии.',
            ]);
        }

        foreach (range(1, count($turnOrder)) as $offset) {
            $playerId = $turnOrder[($currentIndex + $offset) % count($turnOrder)];
            $candidate = $playersById->get($playerId);

            if ($candidate instanceof GamePlayer && $candidate->faction === null) {
                return $candidate;
            }
        }

        $firstPlayer = $playersById->get($this->determineStartingBuildingOrder->execute($game)[0] ?? null);

        if (! $firstPlayer instanceof GamePlayer) {
            throw ValidationException::withMessages([
                'game' => 'Не найден первый игрок партии.',
            ]);
        }

        return $firstPlayer;
    }
}
