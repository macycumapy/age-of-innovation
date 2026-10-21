<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Domain\GameSession\Data\GameTurnContextData;
use App\Domain\GameSession\Queries\WaitForGameTurnQuery;
use App\Mcp\Presentation\GameContextUpdatePresenter;
use App\Mcp\Presentation\GameStatePresenter;
use App\Mcp\Stores\GameContextSnapshotStore;
use App\Mcp\Validators\WaitForGameTurnValidator;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('wait_for_turn')]
#[Description('Wait up to 30 seconds until your turn, pending choice, or game end. Pass context_id only for the exact context you still hold. your_turn returns either a smaller context_update (check base_context_id/game_id/base_version) or full compact-v2 context, with fresh legal actions. Save the new context_id. Choose directly without another read. Unknown/expired/foreign snapshots fall back to full context. game_ended returns final state; timeout returns only reason and preserves your context_id. Retry only while authorized. Client/proxy timeouts must exceed the wait.')]
#[IsReadOnly]
final class WaitForGameTurnTool extends GameTool
{
    public function handle(Request $request, WaitForGameTurnValidator $validator, WaitForGameTurnQuery $query, GameStatePresenter $presenter, GameContextSnapshotStore $snapshots, GameContextUpdatePresenter $updates): ResponseFactory
    {
        $data = $validator->validate($request);

        return $this->respond($request, function (User $user) use ($data, $query, $presenter, $snapshots, $updates): array {
            $result = $query->execute($user, $data->gameId, $data->timeoutSeconds);

            if ($result->view === null) {
                return ['reason' => $result->reason->value];
            }

            if ($result->actions !== null) {
                $next = $snapshots->remember($presenter->turnContext(new GameTurnContextData($result->view, $result->actions)));
                $before = $data->contextId === null ? null : $snapshots->find($user->id, $result->view->gameId, $result->view->playerId, $data->contextId);
                if ($before !== null) {
                    $update = $updates->fromSnapshots($before, $next);
                    if ($update['format'] === 'context-delta-v1') {
                        $update['base_context_id'] = $data->contextId;
                        if (isset($next['context_id'])) {
                            $update['context_id'] = $next['context_id'];
                        }

                        $response = ['reason' => $result->reason->value, 'game_id' => $result->gameId, 'version' => $result->view->version, 'context_update' => $update];
                        $full = ['reason' => $result->reason->value, ...$next];
                        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
                        if (strlen(json_encode($response, $flags)) < strlen(json_encode($full, $flags))) {
                            return $response;
                        }
                    }
                }

                return ['reason' => $result->reason->value, ...$next];
            }

            return ['reason' => $result->reason->value, ...$presenter->present($result->view)];
        });
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...parent::schema($schema),
            'context_id' => $schema->string()->pattern('^[a-f0-9]{32}$')->description('ID of the exact context you still hold. Omit if lost or uncertain. Enables a smaller context_update; missing/expired/foreign snapshots fall back to a full context.'),
            'timeout_seconds' => $schema->integer()->min(0)->max(WaitForGameTurnQuery::TIMEOUT_SECONDS)->description('Defaults to 30 seconds on every call. Use a smaller value if needed, or 0 for an immediate check.'),
        ];
    }
}
