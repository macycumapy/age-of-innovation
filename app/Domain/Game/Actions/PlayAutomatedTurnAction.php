<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\GameBotDifficulty;
use App\Domain\Game\Services\GameActionSelector;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use DomainException;

final class PlayAutomatedTurnAction
{
    private const int MAX_DECISIONS = 32;

    public function __construct(
        private GameActionSelector $gameActionSelector,
        private PerformGameActionOptionAction $performGameActionOption,
        private FinishActionTurnAction $finishActionTurn,
    ) {
    }

    public function execute(
        Game $game,
        User $user,
        GameBotDifficulty $difficulty = GameBotDifficulty::Balanced,
    ): Game {
        $player = $game->players()->whereBelongsTo($user)->first();

        if (! $player instanceof GamePlayer) {
            throw new DomainException('Пользователь не участвует в этой партии.');
        }

        for ($decision = 0; $decision < self::MAX_DECISIONS; $decision++) {
            $game->refresh();

            if ($game->active_player_id !== $user->id) {
                return $game;
            }

            $state = $game->state;
            $playerState = collect($state->players)->firstWhere('playerId', $player->id);

            if (! $playerState instanceof GamePlayerStateData) {
                throw new DomainException('Не найдено состояние автоматического игрока.');
            }

            if ($state->pendingInteraction === null && $state->round->hasTakenMainAction) {
                return $this->finishActionTurn->execute($game, $user);
            }

            $selection = $this->gameActionSelector->execute($state, $player->id, $difficulty);

            if ($selection === null) {
                throw new DomainException('Для автоматического игрока не найдено допустимое действие.');
            }

            $game = $this->performGameActionOption->execute($game, $user, $selection->option);
        }

        throw new DomainException('Автоматический игрок превысил лимит решений за ход.');
    }
}
