<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Domain\Game\Queries\WaitForGameTurnQuery;
use App\Domain\Game\Services\GameTurnWaitBackoff;
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
#[Description('Wait until your turn, pending choice, or game end. Omit timeout_seconds for automatic waits starting at 20 seconds and increasing by 10 seconds after each timeout, capped at 40 seconds; success resets the sequence. Returns reason (your_turn, timeout, game_ended), with visible game state only on success. On timeout, call again only while authorized to keep playing. HTTP/client timeouts must exceed the wait; specify a shorter timeout_seconds if needed.')]
#[IsReadOnly]
final class WaitForGameTurnTool extends GameTool
{
    public function handle(Request $request, WaitForGameTurnValidator $validator, WaitForGameTurnQuery $query, GameStatePresenter $presenter): ResponseFactory
    {
        $data = $validator->validate($request);

        return $this->respond($request, function (User $user) use ($data, $query, $presenter): array {
            $result = $query->execute($user, $data->gameId, $data->timeoutSeconds);

            if ($result->reason === 'timeout') {
                return ['reason' => $result->reason];
            }

            return ['reason' => $result->reason, ...$presenter->present($result->view)];
        });
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...parent::schema($schema),
            'timeout_seconds' => $schema->integer()->min(0)->max(GameTurnWaitBackoff::MAXIMUM_SECONDS)->description('Omit for automatic linear backoff per user and game. An explicit value overrides this call without increasing the counter. Use 0 for an immediate check.'),
        ];
    }
}
