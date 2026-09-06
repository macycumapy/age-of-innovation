<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\GameChanged;
use App\Models\Game;
use Illuminate\Http\Response;

abstract class Controller
{
    protected function gameChanged(Game $game): Response
    {
        GameChanged::dispatch($game->id);

        return response()->noContent();
    }
}
