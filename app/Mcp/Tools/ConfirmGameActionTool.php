<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Domain\GameSession\Actions\ConfirmGameAction;
use App\Mcp\Presentation\GameActionPresenter;
use App\Mcp\Validators\GameActionValidator;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('confirm_action')]
#[Description('Commit the staged action at the supplied version. Returns a short action summary, new version, next_player_id (game player ID), and continuation: your_turn means get_turn_context; wait_for_turn means wait directly without rereading context; game_ended means stop. No full state or action list is returned.')]
final class ConfirmGameActionTool extends GameTool
{
    public function handle(Request $request, GameActionValidator $validator, ConfirmGameAction $confirm, GameActionPresenter $presenter): ResponseFactory
    {
        $data = $validator->validate($request);

        return $this->respond($request, fn (User $user): array => $presenter->confirmed($confirm->execute($user, $data->gameId, $data->version)));
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
