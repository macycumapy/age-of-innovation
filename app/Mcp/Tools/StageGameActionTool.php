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
#[Description('Stage an action_id returned by get_legal_actions at the supplied version. Does not spend resources or change the game. Confirm or roll back afterwards.')]
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
