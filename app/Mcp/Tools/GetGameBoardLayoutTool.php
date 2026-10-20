<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Domain\GameSession\Queries\GetGameBoardLayoutQuery;
use App\Mcp\Presentation\GameBoardPresenter;
use App\Mcp\Validators\GameQueryValidator;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_board_layout')]
#[Description('Read static board geometry: coordinates, original terrain, neighbors, river connections, bank and edge hex IDs. Cache by layout_id and reuse until that ID changes. Join layout and dynamic state hexes by id, not row position. Contains no current buildings or terrain. Requires membership in the requested game.')]
#[IsReadOnly]
final class GetGameBoardLayoutTool extends GameTool
{
    public function handle(Request $request, GameQueryValidator $validator, GetGameBoardLayoutQuery $query, GameBoardPresenter $presenter): ResponseFactory
    {
        $data = $validator->validate($request);

        return $this->respond($request, fn (User $user): array => $presenter->layout($query->execute($user, $data->gameId)));
    }
}
