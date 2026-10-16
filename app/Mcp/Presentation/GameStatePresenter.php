<?php

declare(strict_types=1);

namespace App\Mcp\Presentation;

use App\Domain\Game\Data\GameStateViewData;

final class GameStatePresenter
{
    public function __construct(private GameActionPresenter $actions)
    {
    }

    /** @return array<string, mixed> */
    public function present(GameStateViewData $view): array
    {
        $state = $view->state->toArray();
        unset($state['turnStartSnapshot'], $state['townChoiceCheckpoint']);
        foreach ($state['players'] as &$playerState) {
            unset($playerState['strategicPlan']);
        }
        unset($playerState);

        return [
            'game_id' => $view->gameId, 'user_id' => $view->userId, 'player_id' => $view->playerId,
            'version' => $view->version, 'active_player_id' => $view->activePlayerId,
            'state' => $state,
            'staged_action' => $view->stagedAction === null ? null : $this->actions->staged($view->stagedAction),
        ];
    }
}
