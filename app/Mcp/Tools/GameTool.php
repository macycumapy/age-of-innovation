<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\User;
use Closure;
use DomainException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

abstract class GameTool extends Tool
{
    /** @param Closure(User): array<string, mixed> $callback */
    protected function respond(Request $request, Closure $callback): ResponseFactory
    {
        try {
            $user = $request->user('sanctum');
            if (! $user instanceof User) {
                $userId = (int) config('game-mcp.user_id');
                if ($userId < 1) {
                    throw new DomainException('Configure GAME_MCP_USER_ID first.');
                }
                $user = User::query()->findOrFail($userId);
            }

            return Response::structured($callback($user));
        } catch (DomainException|ModelNotFoundException|ValidationException $exception) {
            return Response::make(Response::error($exception->getMessage()));
        }
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'game_id' => $schema->integer()->min(1)->description('Required when the user participates in multiple games.'),
        ];
    }

}
