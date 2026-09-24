<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Models\GamePlayer;
use Illuminate\Database\Eloquent\Collection;

final class SimulationGamePlayerFactory
{
    /** @return Collection<int, GamePlayer> */
    public function create(GameStateData $state): Collection
    {
        return new Collection(array_map(
            static function (GamePlayerStateData $playerState): GamePlayer {
                $player = new GamePlayer();
                $player->forceFill([
                    'id' => $playerState->playerId,
                    'user_id' => $playerState->playerId,
                ]);

                return $player;
            },
            $state->players,
        ));
    }
}
