<?php

declare(strict_types=1);

namespace App\Domain\Automation\Actions;

use App\Domain\Automation\Data\GameActionSearchBudgetData;
use App\Domain\Automation\Data\StrategicPlanData;
use App\Domain\Automation\Services\GameActionSelector;
use App\Domain\Automation\Services\StrategicPlanner;
use App\Domain\Game\Enums\GameBotDifficulty;
use App\Domain\GameEngine\Contracts\GameActionOption;
use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\Interactions\Actions\PerformGameActionOptionAction;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\Turns\Actions\FinishActionTurnAction;
use App\Models\Game;
use App\Models\GamePlayer;
use DomainException;
use Illuminate\Support\Facades\DB;

final class PlayAutomatedTurnAction
{
    private const int MAX_DECISIONS = 32;

    private const int MAX_AUXILIARY_ACTIONS_PER_TURN = 3;

    public function __construct(
        private GameActionSelector $gameActionSelector,
        private PerformGameActionOptionAction $performGameActionOption,
        private FinishActionTurnAction $finishActionTurn,
        private StrategicPlanner $strategicPlanner,
    ) {
    }

    /** @param list<GameActionOption> $plannedOptions */
    public function execute(
        Game $game,
        GamePlayer $player,
        GameBotDifficulty $difficulty = GameBotDifficulty::Balanced,
        bool $singleDecision = false,
        ?\Closure $onDecisionSelected = null,
        array $plannedOptions = [],
        ?GameActionSearchBudgetData $searchBudget = null,
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
            $strategicPlan = $this->strategicPlanner->execute($state, $player->id);

            if ($state->pendingInteraction === null && $state->round->hasTakenMainAction) {
                return $this->finishActionTurn->execute($game, $player);
            }

            $diagnostics = $this->gameActionSelector->selectWithDiagnostics(
                $state,
                $player->id,
                $difficulty,
                $this->auxiliaryActionsRemaining($game, $player),
                $plannedOptions,
                $searchBudget,
                $strategicPlan,
            );
            $onDecisionSelected?->__invoke($diagnostics);
            $selection = $diagnostics->selected;

            if ($selection === null) {
                throw new DomainException('Для автоматического игрока не найдено допустимое действие.');
            }
            $plannedOptions = $selection->continuation;

            $strategicPlan ??= $this->strategicPlanner->execute($selection->simulation->state, $player->id);
            $game = $this->performWithStrategicPlan($game, $player, $selection->option, $strategicPlan);

            if ($singleDecision) {
                return $game;
            }
        }

        throw new DomainException('Автоматический игрок превысил лимит решений за ход.');
    }

    private function performWithStrategicPlan(Game $game, GamePlayer $player, GameActionOption $option, ?StrategicPlanData $plan): Game
    {
        return DB::transaction(function () use ($game, $player, $option, $plan): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            if ($lockedGame->version !== $game->version || $lockedGame->active_game_player_id !== $player->id) {
                throw new DomainException('Состояние партии изменилось во время выбора действия бота.');
            }
            $state = $lockedGame->state;
            $playerState = collect($state->players)->firstWhere('playerId', $player->id);
            if ($playerState instanceof GamePlayerStateData) {
                $playerState->strategicPlan = $plan;
                $lockedGame->update(['state' => $state]);
            }

            return $this->performGameActionOption->execute($lockedGame, $player, $option);
        });
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
