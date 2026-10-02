<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\PlayerAbilities\Actions;

use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\Enums\GameEventType;
use App\Domain\GameEngine\History\Actions\AppendGameHistoryAction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Research\Enums\KnowledgeDiscipline;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PerformRoundBonusAction
{
    public function __construct(
        private ApplyRoundBonusAction $applyRoundBonusAction,
        private AppendGameHistoryAction $appendGameHistory,
    ) {
    }

    public function execute(Game $game, GamePlayer $player, ?KnowledgeDiscipline $discipline): Game
    {
        return DB::transaction(function () use ($game, $player, $discipline): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;

            if (! $lockedGame->phase->isActionPhase()
                || ! $lockedGame->isActivePlayer($player)
                || $state->pendingInteraction !== null
                || $state->round->hasTakenMainAction
            ) {
                throw ValidationException::withMessages(['game' => 'Сейчас нельзя использовать бонус раунда.']);
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

            $roundBonus = $playerState->roundBonus;
            $result = $this->applyRoundBonusAction->execute($state, $playerState, $discipline);
            $lockedGame->update(['state' => $state, 'version' => $lockedGame->version + 1]);

            if ($roundBonus !== RoundBonus::Bridge) {
                $this->appendGameHistory->execute(
                    $lockedGame,
                    $player,
                    GameActionType::SpecialAction,
                    [
                        'round_bonus' => $roundBonus->value,
                        'discipline' => $discipline?->value,
                        'gained_power' => $result->gainedPower,
                        'victory_points' => $result->victoryPoints,
                    ],
                    [[
                        'type' => GameEventType::RoundBonusActionUsed->value,
                        'player_id' => $player->id,
                        'round_bonus' => $roundBonus->value,
                        'discipline' => $discipline?->value,
                    ]],
                    $stateVersionBefore,
                    $lockedGame->version,
                );
            }

            return $lockedGame->refresh();
        });
    }
}
