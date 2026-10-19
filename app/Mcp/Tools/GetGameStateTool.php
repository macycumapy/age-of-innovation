<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Domain\GameSession\Queries\GetGameStateQuery;
use App\Mcp\Presentation\GameStatePresenter;
use App\Mcp\Validators\GameQueryValidator;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_state')]
#[Description('Read the user-visible game state, current version, pending choices, and staged action.')]
#[IsReadOnly]
final class GetGameStateTool extends GameTool
{
    public function handle(Request $request, GameQueryValidator $validator, GetGameStateQuery $query, GameStatePresenter $presenter): ResponseFactory
    {
        $data = $validator->validate($request);

        return $this->respond($request, fn (User $user): array => $presenter->present($query->execute($user, $data->gameId)));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...parent::schema($schema),
        ];
    }
}
