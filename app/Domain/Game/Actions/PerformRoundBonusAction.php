<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Enums\RoundBonus;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PerformRoundBonusAction
{
    public function __construct(
        private ApplyRoundBonusAction $applyRoundBonusAction,
        private AppendGameHistoryAction $appendGameHistory,
    ) {
    }

    public function execute(Game $game, User $user, ?KnowledgeDiscipline $discipline): Game
    {
        return DB::transaction(function () use ($game, $user, $discipline): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $player = $lockedGame->players()->whereBelongsTo($user)->first();

            if ($lockedGame->phase !== GamePhase::Actions
                || $lockedGame->active_player_id !== $user->id
                || $state->pendingInteraction !== null
                || $state->round->hasTakenMainAction
                || ! $player instanceof GamePlayer) {
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
            $this->applyRoundBonusAction->execute($state, $playerState, $discipline);
            if ($roundBonus === RoundBonus::Bridge && $state->pendingInteraction !== null) {
                $state->pendingInteraction->context['source'] = 'round_bonus';
            }
            $lockedGame->update(['state' => $state, 'version' => $lockedGame->version + 1]);

            if ($roundBonus !== RoundBonus::Bridge) {
                $this->appendGameHistory->execute(
                    $lockedGame,
                    $user,
                    GameActionType::SpecialAction,
                    ['round_bonus' => $roundBonus->value, 'discipline' => $discipline?->value],
                    [[
                        'type' => 'round_bonus_action_used',
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
