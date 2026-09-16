<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\BookAction;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PerformBookActionAction
{
    public function __construct(
        private ApplyBookActionAction $applyBookAction,
        private AppendGameHistoryAction $appendGameHistory,
    ) {
    }

    /** @param array<string, int> $bookCounts */
    public function execute(
        Game $game,
        User $user,
        BookAction $action,
        array $bookCounts,
        ?KnowledgeDiscipline $discipline,
        ?string $hexId,
    ): Game {
        return DB::transaction(function () use ($game, $user, $action, $bookCounts, $discipline, $hexId): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $player = $lockedGame->players()->whereBelongsTo($user)->first();

            if ($lockedGame->phase !== GamePhase::Actions
                || $lockedGame->active_player_id !== $user->id
                || $state->pendingInteraction !== null
                || ! $player instanceof GamePlayer) {
                throw ValidationException::withMessages(['game' => 'Сейчас нельзя выполнять действие за книги.']);
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

            $result = $this->applyBookAction->execute(
                $state,
                $playerState,
                $action,
                $bookCounts,
                $discipline,
                $hexId,
            );
            $lockedGame->update([
                'active_player_id' => $result['nextActiveUserId'],
                'state' => $state,
                'version' => $lockedGame->version + 1,
            ]);
            $this->appendGameHistory->execute(
                $lockedGame,
                $user,
                GameActionType::BookAction,
                [
                    'action' => $action->value,
                    'book_counts' => $bookCounts,
                    'discipline' => $discipline?->value,
                    'hex_id' => $hexId,
                    'victory_points' => $result['victoryPoints'] + $result['buildingBonusPoints'],
                    'bonus_coins' => $result['buildingBonusCoins'],
                    'gained_power' => $result['gainedPower'],
                ],
                [[
                    'type' => 'book_action_used',
                    'player_id' => $player->id,
                    'action' => $action->value,
                ]],
                $stateVersionBefore,
                $lockedGame->version,
            );

            return $lockedGame->refresh();
        });
    }
}
