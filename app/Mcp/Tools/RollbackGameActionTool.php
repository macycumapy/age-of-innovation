<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Domain\Game\Actions\RollbackGameAction;
use App\Mcp\Validators\GameActionValidator;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('rollback_action')]
#[Description('Clear the unconfirmed selection at the supplied version. Does not undo previously confirmed actions.')]
final class RollbackGameActionTool extends GameTool
{
    public function handle(Request $request, GameActionValidator $validator, RollbackGameAction $rollback): ResponseFactory
    {
        $data = $validator->validate($request);

        return $this->respond($request, function (User $user) use ($data, $rollback): array {
            $game = $rollback->execute($user, $data->gameId, $data->version);

            return ['version' => $game->version, 'rolled_back' => true];
        });
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...parent::schema($schema),
            'version' => $schema->integer()->min(0)->required(),
        ];
    }
}
