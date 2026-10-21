<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Domain\GameSession\Actions\StageGameAction;
use App\Domain\GameSession\Queries\GetLegalGameActionsQuery;
use App\Mcp\Presentation\GameActionIdentifier;
use App\Mcp\Presentation\GameActionPresenter;
use App\Mcp\Validators\StageGameActionValidator;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('stage_action')]
#[Description('Stage an action_id from current legal actions at the supplied version. Does not spend resources or change the game. Inspect the result before confirming or rolling back. If stage_action fails or its result is uncertain, do not call confirm_action. Fetch get_turn_context, choose again from its current actions, and stage again. Confirm only after a successful stage_action response whose staged_action matches the intended action_id and version. Never batch stage_action and confirm_action in parallel.')]
final class StageGameActionTool extends GameTool
{
    public function handle(Request $request, StageGameActionValidator $validator, GetLegalGameActionsQuery $query, GameActionIdentifier $identifiers, StageGameAction $stage, GameActionPresenter $presenter): ResponseFactory
    {
        $data = $validator->validate($request);

        return $this->respond($request, function (User $user) use ($data, $query, $identifiers, $stage, $presenter): array {
            $options = $query->execute($user, $data->gameId);
            $option = $identifiers->resolve($data->actionId, $options);
            $staged = $stage->execute($user, $data->gameId, $data->version, $option);

            return ['staged_action' => $presenter->staged($staged)];
        });
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...parent::schema($schema),
            'version' => $schema->integer()->min(0)->required(),
            'action_id' => $schema->string()->max(64)->required(),
        ];
    }
}
