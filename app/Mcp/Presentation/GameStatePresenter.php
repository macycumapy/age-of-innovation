<?php

declare(strict_types=1);

namespace App\Mcp\Presentation;

use App\Domain\GameEngine\Turns\Enums\GamePhase;
use App\Domain\GameSession\Data\GameStateViewData;
use App\Domain\GameSession\Data\GameTurnContextData;

final class GameStatePresenter
{
    public function __construct(private GameActionPresenter $actions, private GameBoardPresenter $board)
    {
    }

    /** @return array<string, mixed> */
    public function turnContext(GameTurnContextData $context): array
    {
        return [
            ...$this->present($context->view),
            'actions' => $this->actions->legalActions($context->actions)['actions'],
        ];
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

        $state['board'] = $this->board->present($view->state->board);
        if ($view->state->round->phase !== GamePhase::Setup) {
            unset($state['planningSelections'], $state['startingBuildingTurnIndex'], $state['pendingStartingBuildingHexId']);
            if (is_array($state['setupPool'])) {
                unset($state['setupPool']['planningBundles'], $state['setupPool']['firstPlayerIndex']);
            }
        }

        return [
            'format' => 'compact-v1',
            'game_id' => $view->gameId, 'user_id' => $view->userId, 'player_id' => $view->playerId,
            'version' => $view->version, 'active_player_id' => $view->activePlayerId,
            'state' => $state,
            'staged_action' => $view->stagedAction === null ? null : $this->actions->staged($view->stagedAction),
        ];
    }
}
