<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Domain\GameSession\Queries\GetGameTurnContextQuery;
use App\Mcp\Presentation\GameStatePresenter;
use App\Mcp\Stores\GameContextSnapshotStore;
use App\Mcp\Validators\GameQueryValidator;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_turn_context')]
#[Description('Read compact-v2 game state and legal actions in one consistent snapshot with a single version. Save context_id with this exact context and pass it to wait_for_turn to request smaller updates. The full dynamic board refers to static geometry by layout_id; fetch get_board_layout only if its geometry is unknown or missing. Prefer this over separate state and action reads. Does not modify the game.')]
#[IsReadOnly]
final class GetGameTurnContextTool extends GameTool
{
    public function handle(Request $request, GameQueryValidator $validator, GetGameTurnContextQuery $query, GameStatePresenter $presenter, GameContextSnapshotStore $snapshots): ResponseFactory
    {
        $data = $validator->validate($request);

        return $this->respond($request, fn (User $user): array => $snapshots->remember($presenter->turnContext($query->execute($user, $data->gameId))));
    }
}
