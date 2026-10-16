<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Domain\Game\Queries\GetLegalGameActionsQuery;
use App\Mcp\Presentation\GameActionPresenter;
use App\Mcp\Validators\GameQueryValidator;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_legal_actions')]
#[Description('Read legal actions with exact action IDs and parameters for the current game version.')]
#[IsReadOnly]
final class GetLegalGameActionsTool extends GameTool
{
    public function handle(Request $request, GameQueryValidator $validator, GetLegalGameActionsQuery $query, GameActionPresenter $presenter): ResponseFactory
    {
        $data = $validator->validate($request);

        return $this->respond($request, fn (User $user): array => $presenter->legalActions($query->execute($user, $data->gameId)));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...parent::schema($schema),
        ];
    }
}
