<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\PlayerAbilities\Actions;

use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\Enums\GameEventType;
use App\Domain\GameEngine\History\Actions\AppendGameHistoryAction;
use App\Domain\GameEngine\Research\Enums\Competency;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PerformCompetencyAction
{
    public function __construct(
        private ApplyCompetencyAction $applyCompetencyAction,
        private AppendGameHistoryAction $appendGameHistory,
    ) {
    }

    public function execute(Game $game, GamePlayer $player): Game
    {
        return DB::transaction(function () use ($game, $player): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;

            if (! $lockedGame->phase->isActionPhase()
                || ! $lockedGame->isActivePlayer($player)
                || $state->pendingInteraction !== null
                || $state->round->hasTakenMainAction
            ) {
                throw ValidationException::withMessages(['game' => 'Сейчас нельзя использовать действие компетенции.']);
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

            $this->applyCompetencyAction->execute($playerState);
            $state->round->hasTakenMainAction = true;
            $lockedGame->update(['state' => $state, 'version' => $lockedGame->version + 1]);
            $this->appendGameHistory->execute(
                $lockedGame,
                $player,
                GameActionType::SpecialAction,
                ['competency' => Competency::Competency07->value],
                [[
                    'type' => GameEventType::CompetencyActionUsed->value,
                    'player_id' => $player->id,
                    'competency' => Competency::Competency07->value,
                    'power' => 4,
                ]],
                $stateVersionBefore,
                $lockedGame->version,
            );

            return $lockedGame->refresh();
        });
    }
}
