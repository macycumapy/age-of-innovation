<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GameEventType;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PerformFactionAction
{
    public function __construct(
        private ApplyFactionAction $applyFactionAction,
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
            ) {
                throw ValidationException::withMessages(['game' => 'Сейчас нельзя использовать действие расы.']);
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

            $faction = $playerState->faction;
            $this->applyFactionAction->execute($state, $playerState, $discipline);
            if ($faction === Faction::Moles && $state->pendingInteraction !== null) {
                $state->pendingInteraction->context['source'] = 'faction';
            }
            $lockedGame->update(['state' => $state, 'version' => $lockedGame->version + 1]);

            if ($faction !== Faction::Moles) {
                $this->appendGameHistory->execute(
                    $lockedGame,
                    $player,
                    GameActionType::SpecialAction,
                    ['faction' => $faction->value, 'discipline' => $discipline?->value],
                    [[
                        'type' => GameEventType::FactionActionUsed->value,
                        'player_id' => $player->id,
                        'faction' => $faction->value,
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
