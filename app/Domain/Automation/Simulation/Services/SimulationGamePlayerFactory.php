<?php

declare(strict_types=1);

namespace App\Domain\Automation\Simulation\Services;

use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
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
