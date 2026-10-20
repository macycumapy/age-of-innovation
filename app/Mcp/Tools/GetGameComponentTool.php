<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Enums\GameComponentType;
use App\Mcp\Presentation\GameComponentPresenter;
use App\Mcp\Validators\GameComponentValidator;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_component')]
#[Description('Read the engine description of one component by type and exact id from the game context. Reuse descriptions already in context; do not preload the catalog. Descriptions summarize effects, not live eligibility, costs, or available copies. No game_id required.')]
#[IsReadOnly]
final class GetGameComponentTool extends GameTool
{
    public function handle(Request $request, GameComponentValidator $validator, GameComponentPresenter $presenter): ResponseFactory
    {
        $data = $validator->validate($request);

        return $this->respond($request, fn (User $user): array => $presenter->present($data));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->enum(GameComponentType::class)->required(),
            'id' => $schema->string()->max(64)->required()->description('Exact component ID from the game context, such as league_of_cities or palace_08.'),
        ];
    }
}
