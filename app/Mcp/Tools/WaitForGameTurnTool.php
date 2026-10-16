<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Domain\Game\Queries\WaitForGameTurnQuery;
use App\Mcp\Presentation\GameStatePresenter;
use App\Mcp\Validators\WaitForGameTurnValidator;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('wait_for_turn')]
#[Description('Wait up to 20 seconds until your turn or pending choice, or the game ends. Returns reason (your_turn, timeout, game_ended) and current visible game state. On timeout, call again only while explicitly authorized to keep playing.')]
#[IsReadOnly]
final class WaitForGameTurnTool extends GameTool
{
    public function handle(Request $request, WaitForGameTurnValidator $validator, WaitForGameTurnQuery $query, GameStatePresenter $presenter): ResponseFactory
    {
        $data = $validator->validate($request);

        return $this->respond($request, function (User $user) use ($data, $query, $presenter): array {
            $result = $query->execute($user, $data->gameId, $data->timeoutSeconds);

            return ['reason' => $result->reason, ...$presenter->present($result->view)];
        });
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...parent::schema($schema),
            'timeout_seconds' => $schema->integer()->min(0)->max(20)->description('Defaults to 20. Use 0 for an immediate check.'),
        ];
    }
}
