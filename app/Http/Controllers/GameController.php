<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\CreateGameAction;
use App\Http\Requests\StoreGameRequest;
use App\Http\Resources\GameResource;
use App\Models\Game;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GameController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $games = Game::query()
            ->availableTo($user)
            ->withExists([
                'players as is_joined' => fn (Builder $query): Builder => $query->where('user_id', $user->id),
            ])
            ->withCount('players')
            ->latest()
            ->latest('id')
            ->get();

        return Inertia::render('games/Index', [
            'games' => GameResource::collection($games),
        ]);
    }

    public function show(Request $request, Game $game): Response
    {
        /** @var User $user */
        $user = $request->user();

        $game->load(['players.user'])->loadCount('players');
        $game->setAttribute('is_joined', $game->players->contains('user_id', $user->id));

        return Inertia::render('games/Show', [
            'game' => new GameResource($game),
        ]);
    }

    public function store(StoreGameRequest $request, CreateGameAction $createGame): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $createGame->execute($user, $request->mapVariant());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Игра создана.',
        ]);

        return to_route('games.index');
    }
}
