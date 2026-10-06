<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\PlayerAbilities\Actions;

use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ChoosePalaceRewardOrderAction
{
    public function __construct(private ApplyChoosePalaceRewardOrderAction $applyChoosePalaceRewardOrder)
    {
    }
    public function execute(Game $game, GamePlayer $player, string $firstReward): Game
    {
        return DB::transaction(function () use ($game, $player, $firstReward): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $playerState = collect($state->players)->firstWhere('playerId', $player->id);
            if (! $lockedGame->phase->isActionPhase() || $player->game_id !== $lockedGame->id
                || ! $lockedGame->isActivePlayer($player) || ! $playerState instanceof GamePlayerStateData) {
                throw ValidationException::withMessages(['first_reward' => 'Сейчас нельзя выбрать действие дворца.']);
            }
            $nextPlayerId = $this->applyChoosePalaceRewardOrder->execute($state, $playerState, $firstReward);
            $source = $lockedGame->actions()->where('type', GameActionType::ChoosePalace)
                ->where('game_player_id', $player->id)->latest('sequence')->firstOrFail();
            $payload = $source->payload;
            $payload['first_reward'] = $firstReward;
            $source->update(['payload' => $payload, 'state_version_after' => $lockedGame->version + 1]);
            $lockedGame->update(['state' => $state, 'active_game_player_id' => $nextPlayerId, 'version' => $lockedGame->version + 1]);
            return $lockedGame->refresh();
        });
    }
}
