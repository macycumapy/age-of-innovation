<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Domain\Game\Actions\CreateGameAction;
use App\Domain\Settings\Services\SettingsService;
use App\Events\GameChanged;
use App\Events\GameHistoryChanged;
use App\Events\GameListChanged;
use App\Http\Controllers\Controller;
use App\Http\Requests\Game\StoreGameRequest;
use App\Http\Resources\GameHistoryCollection;
use App\Http\Resources\GameResource;
use App\Http\Resources\GameSummaryResource;
use App\Models\Game;
use App\Models\GameAction;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Inertia\Inertia;
use Inertia\Response;

class GameController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('games/Index', [
            'games' => Inertia::live(
                fn (): AnonymousResourceCollection => GameSummaryResource::collection(
                    Game::query()->visibleSummariesFor($user)->get(),
                ),
                on: GameListChanged::class,
            ),
        ]);
    }

    public function show(Request $request, Game $game, SettingsService $settings): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('games/Show', [
            'game' => Inertia::live(function () use ($game, $user): GameResource {
                $game->load(['players.user'])->loadCount('players');
                $game->setAttribute('is_joined', $game->players->contains('user_id', $user->id));

                return new GameResource($game);
            }, on: new GameChanged($game->id), throttle: 100),
            'history' => Inertia::live(
                fn (): GameHistoryCollection => (new GameHistoryCollection(
                    $game->actions()->with('gamePlayer.user')
                        ->orderByDesc('sequence')
                        ->limit(GameAction::HISTORY_PAGE_SIZE + 1)
                        ->get(),
                ))->additional(['version' => $game->version]),
                on: new GameHistoryChanged($game->id),
                throttle: 100,
            ),
            'settings' => fn (): array => $settings->get()->toArray(),
        ]);
    }

    public function store(StoreGameRequest $request, CreateGameAction $createGame): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $game = $createGame->execute($user, $request->mapVariant());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Игра создана.',
        ]);

        return to_route('games.show', $game);
    }
}
