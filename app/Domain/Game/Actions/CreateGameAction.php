<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Enums\MapVariant;
use App\Domain\Game\Factories\BoardStateFactory;
use App\Models\Game;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreateGameAction
{
    public function __construct(
        private BoardStateFactory $boardStateFactory,
        private CreateGamePlayerAction $createGamePlayer,
    ) {
    }

    public function execute(User $user, MapVariant $mapVariant): Game
    {
        return DB::transaction(function () use ($user, $mapVariant): Game {
            $game = Game::create([
                'state' => new GameStateData(
                    board: $this->boardStateFactory->create($mapVariant),
                ),
                'random_seed' => Str::random(32),
            ]);

            $this->createGamePlayer->execute($game, $user);

            return $game;
        });
    }
}
