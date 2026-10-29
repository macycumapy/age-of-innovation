<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Domain\GameSession\Actions\ExecuteGameAction;
use App\Domain\GameSession\Enums\GameActionContinuation;
use App\Domain\GameSession\Queries\GetGameStateQuery;
use App\Domain\GameSession\Queries\GetGameTurnContextQuery;
use App\Domain\GameSession\Queries\GetLegalGameActionsQuery;
use App\Mcp\Presentation\GameActionIdentifier;
use App\Mcp\Presentation\GameActionPresenter;
use App\Mcp\Presentation\GameContextUpdatePresenter;
use App\Mcp\Presentation\GameStatePresenter;
use App\Mcp\Stores\GameContextSnapshotStore;
use App\Mcp\Validators\ExecuteGameActionValidator;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('execute_action')]
#[Description('Commit an exact current action_id in one call: stage and confirm internally under one transaction. Spends resources and may advance the game; not a preview and not undoable through MCP. Use only when authorized to commit. Returns confirmed summary, version, continuation and context_update with fresh actions when your turn continues. Existing staged selections must be resolved by the user outside MCP first. After an ambiguous result, read get_turn_context instead of blindly retrying.')]
final class ExecuteGameActionTool extends GameTool
{
    public function handle(
        Request $request,
        ExecuteGameActionValidator $validator,
        ExecuteGameAction $execute,
        GetLegalGameActionsQuery $actions,
        GameActionIdentifier $identifiers,
        GameActionPresenter $presenter,
        GetGameStateQuery $state,
        GetGameTurnContextQuery $context,
        GameContextUpdatePresenter $updates,
        GameStatePresenter $statePresenter,
        GameContextSnapshotStore $snapshots,
    ): ResponseFactory {
        $data = $validator->validate($request);

        return $this->respond($request, fn (User $user): array => DB::transaction(function () use ($user, $data, $state, $execute, $actions, $identifiers, $presenter, $context, $updates, $statePresenter, $snapshots): array {
            $before = $state->execute($user, $data->gameId);
            $options = $actions->execute($user, $before->gameId);
            $option = $identifiers->resolve($data->actionId, $options);
            $result = $execute->execute($user, $before->gameId, $data->version, $option);
            $payload = $presenter->confirmed($result);

            if ($result->continuation === GameActionContinuation::YourTurn) {
                $after = $context->execute($user, $before->gameId);
                $payload['context_update'] = $updates->present($before, $after);
                $snapshot = $snapshots->remember($statePresenter->turnContext($after));
                if (isset($snapshot['context_id'])) {
                    $payload['context_update']['context_id'] = $snapshot['context_id'];
                }
            }

            return $payload;
        }));
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
