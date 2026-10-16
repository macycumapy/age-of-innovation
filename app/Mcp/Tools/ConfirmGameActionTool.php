<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Domain\Game\Actions\ConfirmGameAction;
use App\Mcp\Validators\GameActionValidator;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('confirm_action')]
#[Description('Commit the staged action at the supplied version. May spend resources or advance the game. Read state afterwards for follow-up choices.')]
final class ConfirmGameActionTool extends GameTool
{
    public function handle(Request $request, GameActionValidator $validator, ConfirmGameAction $confirm): ResponseFactory
    {
        $data = $validator->validate($request);

        return $this->respond($request, function (User $user) use ($data, $confirm): array {
            $game = $confirm->execute($user, $data->gameId, $data->version);

            return ['version' => $game->version, 'confirmed' => true];
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
