<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GameBotDifficulty;
use App\Domain\Game\Services\GameActionSelector;
use App\Models\Game;
use App\Models\GamePlayer;
use DomainException;

final class PlayAutomatedTurnAction
{
    private const int MAX_DECISIONS = 32;

    private const int MAX_AUXILIARY_ACTIONS_PER_TURN = 1;

    public function __construct(
        private GameActionSelector $gameActionSelector,
        private PerformGameActionOptionAction $performGameActionOption,
        private FinishActionTurnAction $finishActionTurn,
    ) {
    }

    public function execute(
        Game $game,
        GamePlayer $player,
        GameBotDifficulty $difficulty = GameBotDifficulty::Balanced,
        bool $singleDecision = false,
    ): Game {
        if ($player->game_id !== $game->id) {
            throw new DomainException('Автоматический игрок не участвует в этой партии.');
        }

        for ($decision = 0; $decision < self::MAX_DECISIONS; $decision++) {
            $game->refresh();

            if ($game->active_game_player_id !== $player->id) {
                return $game;
            }

            $state = $game->state;

            if ($state->pendingInteraction === null && $state->round->hasTakenMainAction) {
                return $this->finishActionTurn->execute($game, $player);
            }

            $selection = $this->gameActionSelector->execute(
                $state,
                $player->id,
                $difficulty,
                $this->auxiliaryActionsRemaining($game, $player),
            );

            if ($selection === null) {
                throw new DomainException('Для автоматического игрока не найдено допустимое действие.');
            }

            $game = $this->performGameActionOption->execute($game, $player, $selection->option);

            if ($singleDecision) {
                return $game;
            }
        }

        throw new DomainException('Автоматический игрок превысил лимит решений за ход.');
    }

    private function auxiliaryActionsRemaining(Game $game, GamePlayer $player): int
    {
        $turnStartVersion = $game->state->round->turnStartVersion;

        if ($turnStartVersion === null) {
            return self::MAX_AUXILIARY_ACTIONS_PER_TURN;
        }

        $actionsTaken = $game->actions()
            ->where('game_player_id', $player->id)
            ->where('state_version_before', '>=', $turnStartVersion)
            ->whereIn('type', [
                GameActionType::ExchangeResources,
                GameActionType::SacrificePower,
            ])
            ->count();

        return max(0, self::MAX_AUXILIARY_ACTIONS_PER_TURN - $actionsTaken);
    }
}
