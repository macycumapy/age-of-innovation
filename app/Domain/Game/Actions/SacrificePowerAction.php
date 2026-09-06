<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GamePhase;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SacrificePowerAction
{
    public function __construct(private AppendGameHistoryAction $appendGameHistory)
    {
    }

    public function execute(Game $game, User $user, int $amount): Game
    {
        return DB::transaction(function () use ($game, $user, $amount): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $player = $lockedGame->players()->whereBelongsTo($user)->first();

            if ($lockedGame->phase !== GamePhase::Actions
                || $lockedGame->active_player_id !== $user->id
                || ! $player instanceof GamePlayer) {
                throw ValidationException::withMessages([
                    'game' => 'Сейчас нельзя жертвовать Силу.',
                ]);
            }

            $playerState = collect($state->players)->firstWhere('playerId', $player->id);

            if (! $playerState instanceof GamePlayerStateData) {
                throw ValidationException::withMessages([
                    'game' => 'Не найдено состояние игрока.',
                ]);
            }

            if ($amount < 1 || $amount * 2 > $playerState->resources->power->bowlTwo) {
                throw ValidationException::withMessages([
                    'amount' => 'Недостаточно Силы во второй чаше.',
                ]);
            }

            $stateVersionBefore = $lockedGame->version;

            if ($state->turnStartSnapshot === null) {
                $state->turnStartSnapshot = $state->toArray();
                $state->round->turnStartVersion = $stateVersionBefore;
            }

            $playerState->resources->power->bowlTwo -= $amount * 2;
            $playerState->resources->power->bowlThree += $amount;

            $lockedGame->update([
                'state' => $state,
                'version' => $lockedGame->version + 1,
            ]);
            $this->appendGameHistory->execute(
                $lockedGame,
                $user,
                GameActionType::SacrificePower,
                ['amount' => $amount],
                [[
                    'type' => 'power_sacrificed',
                    'player_id' => $player->id,
                    'sacrificed' => $amount,
                    'moved_to_bowl_three' => $amount,
                ]],
                $stateVersionBefore,
                $lockedGame->version,
            );

            return $lockedGame->refresh();
        });
    }
}
