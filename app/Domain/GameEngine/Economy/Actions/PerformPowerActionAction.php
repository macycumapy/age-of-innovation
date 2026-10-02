<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Economy\Actions;

use App\Domain\GameEngine\Economy\Enums\PowerAction;
use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\Enums\GameEventType;
use App\Domain\GameEngine\History\Actions\AppendGameHistoryAction;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PerformPowerActionAction
{
    public function __construct(
        private ApplyPowerActionAction $applyPowerAction,
        private AppendGameHistoryAction $appendGameHistory,
    ) {
    }

    public function execute(Game $game, GamePlayer $player, PowerAction $action, int $sacrificeAmount): Game
    {
        return DB::transaction(function () use ($game, $player, $action, $sacrificeAmount): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $createsInteraction = in_array($action, [
                PowerAction::BuildBridge,
                PowerAction::TerraformOneSpade,
                PowerAction::TerraformTwoSpades,
            ], true);

            if (! $lockedGame->phase->isActionPhase()
                || $player->game_id !== $lockedGame->id
                || ! $lockedGame->isActivePlayer($player)
                || ($state->pendingInteraction !== null && $createsInteraction)
                || $state->round->hasTakenMainAction) {
                throw ValidationException::withMessages(['action' => 'Сейчас нельзя выполнять действие Силы.']);
            }

            $playerState = collect($state->players)->firstWhere('playerId', $player->id);

            if (! $playerState instanceof GamePlayerStateData) {
                throw ValidationException::withMessages(['game' => 'Не найдено состояние игрока.']);
            }

            $stateVersionBefore = $lockedGame->version;

            if ($state->turnStartSnapshot === null) {
                $state->turnStartSnapshot = $state->toArray();
                $state->round->turnStartVersion = $stateVersionBefore;
            }

            $this->applyPowerAction->execute($state, $playerState, $action, $sacrificeAmount);
            $victoryPoints = $action->victoryPoints(
                $playerState->faction,
                $state->setupPool->playerCount ?? count($state->players),
            );

            if ($action === PowerAction::BuildBridge && $state->pendingInteraction !== null) {
                $state->pendingInteraction->context['sacrificeAmount'] = $sacrificeAmount;
                $state->pendingInteraction->context['victoryPoints'] = $victoryPoints;
            }
            $lockedGame->update(['state' => $state, 'version' => $lockedGame->version + 1]);

            if ($action !== PowerAction::BuildBridge) {
                $this->appendGameHistory->execute(
                    $lockedGame,
                    $player,
                    GameActionType::PowerAction,
                    [
                        'action' => $action->value,
                        'sacrifice_amount' => $sacrificeAmount,
                        'victory_points' => $victoryPoints,
                    ],
                    [[
                        'type' => GameEventType::PowerActionUsed->value,
                        'player_id' => $player->id,
                        'action' => $action->value,
                        'sacrifice_amount' => $sacrificeAmount,
                        'victory_points' => $victoryPoints,
                    ]],
                    $stateVersionBefore,
                    $lockedGame->version,
                );
            }

            return $lockedGame->refresh();
        });
    }
}
