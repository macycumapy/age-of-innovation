<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\GameEngine\Board\Enums\MapVariant;
use App\Domain\GameEngine\Board\Factories\BoardStateFactory;
use App\Domain\GameEngine\State\Data\GameStateData;
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
