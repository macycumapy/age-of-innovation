<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\GameHistoryRequest;
use App\Http\Resources\GameHistoryEntryResource;
use App\Models\Game;
use App\Models\GameAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

final class GameHistoryController extends Controller
{
    public function __invoke(GameHistoryRequest $request, Game $game): JsonResponse
    {
        $actions = $game->actions()
            ->with('player:id,name')
            ->when(
                $request->beforeSequence(),
                fn (Builder $query, int $sequence): Builder => $query->where('sequence', '<', $sequence),
            )
            ->orderByDesc('sequence')
            ->limit(GameAction::HISTORY_PAGE_SIZE + 1)
            ->get();

        return response()->json([
            'data' => GameHistoryEntryResource::collection(
                $actions->take(GameAction::HISTORY_PAGE_SIZE),
            )->resolve($request),
            'hasMore' => $actions->count() > GameAction::HISTORY_PAGE_SIZE,
        ]);
    }
}
