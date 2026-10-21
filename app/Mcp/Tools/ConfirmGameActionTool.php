<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Domain\GameSession\Actions\ConfirmGameAction;
use App\Domain\GameSession\Enums\GameActionContinuation;
use App\Domain\GameSession\Queries\GetGameStateQuery;
use App\Domain\GameSession\Queries\GetGameTurnContextQuery;
use App\Mcp\Presentation\GameActionPresenter;
use App\Mcp\Presentation\GameContextUpdatePresenter;
use App\Mcp\Validators\GameActionValidator;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('confirm_action')]
#[Description('Commit the staged action at the supplied version. Returns summary, version, next_player_id, and continuation. your_turn includes context_update with fresh actions: apply context-delta-v1 only to the same game/base_version, or replace context with context-snapshot-v1.context. Choose directly without rereading context. If the baseline is missing, use get_turn_context. wait_for_turn means wait directly; game_ended means stop.')]
final class ConfirmGameActionTool extends GameTool
{
    public function handle(
        Request $request,
        GameActionValidator $validator,
        ConfirmGameAction $confirm,
        GameActionPresenter $presenter,
        GetGameStateQuery $state,
        GetGameTurnContextQuery $context,
        GameContextUpdatePresenter $updates,
    ): ResponseFactory {
        $data = $validator->validate($request);

        return $this->respond($request, fn (User $user): array => DB::transaction(function () use ($user, $data, $state, $confirm, $presenter, $context, $updates): array {
            $before = $state->execute($user, $data->gameId);
            $result = $confirm->execute($user, $before->gameId, $data->version);
            $payload = $presenter->confirmed($result);

            if ($result->continuation === GameActionContinuation::YourTurn) {
                $payload['context_update'] = $updates->present($before, $context->execute($user, $before->gameId));
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
        ];
    }
}
