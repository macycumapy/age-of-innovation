<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Domain\GameSession\Data\GameTurnContextData;
use App\Domain\GameSession\Queries\WaitForGameTurnQuery;
use App\Mcp\Presentation\GameStatePresenter;
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
#[Description('Wait up to 30 seconds until your turn, pending choice, or game end. your_turn returns compact-v2 state and legal actions at one shared version: choose directly from actions without another state/context/actions read. game_ended returns final visible state. Fetch get_board_layout only for unknown/missing geometry identified by state.board.layout_id. Timeout returns only reason. On timeout, call again only while authorized to keep playing. Client and proxy timeouts must exceed the wait.')]
#[IsReadOnly]
final class WaitForGameTurnTool extends GameTool
{
    public function handle(Request $request, WaitForGameTurnValidator $validator, WaitForGameTurnQuery $query, GameStatePresenter $presenter): ResponseFactory
    {
        $data = $validator->validate($request);

        return $this->respond($request, function (User $user) use ($data, $query, $presenter): array {
            $result = $query->execute($user, $data->gameId, $data->timeoutSeconds);

            if ($result->view === null) {
                return ['reason' => $result->reason->value];
            }

            if ($result->actions !== null) {
                return ['reason' => $result->reason->value, ...$presenter->turnContext(new GameTurnContextData($result->view, $result->actions))];
            }

            return ['reason' => $result->reason->value, ...$presenter->present($result->view)];
        });
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...parent::schema($schema),
            'timeout_seconds' => $schema->integer()->min(0)->max(WaitForGameTurnQuery::TIMEOUT_SECONDS)->description('Defaults to 30 seconds on every call. Use a smaller value if needed, or 0 for an immediate check.'),
        ];
    }
}
