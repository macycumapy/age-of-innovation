<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Domain\Game\Actions\JoinGameAction;
use App\Events\GameChanged;
use App\Mcp\Validators\JoinGameValidator;
use App\Models\Game;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('join_game')]
#[Description('Join the requested open lobby as the authenticated user. Requires a game_id. Does not mark readiness or start the game.')]
final class JoinGameTool extends GameTool
{
    public function handle(Request $request, JoinGameValidator $validator, JoinGameAction $joinGame): ResponseFactory
    {
        $data = $validator->validate($request);

        return $this->respond($request, function (User $user) use ($data, $joinGame): array {
            $game = Game::query()->findOrFail($data->gameId);
            $player = $joinGame->execute($game, $user);
            GameChanged::dispatch($game->id);

            return [
                'joined' => true,
                'game_id' => $player->game_id,
                'user_id' => $player->user_id,
                'player_id' => $player->id,
                'seat' => $player->seat,
            ];
        });
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'game_id' => $schema->integer()->min(1)->required(),
        ];
    }
}
