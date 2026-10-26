<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\GameEngine\Economy\Services\PowerGainConfirmation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ConfirmLostPower
{
    public function __construct(private PowerGainConfirmation $confirmation)
    {
    }

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST') || ! $request->routeIs(
            'games.town',
            'games.rewards',
            'games.innovation-action',
            'games.book-action',
            'games.round-bonus-action',
            'games.faction-action',
            'games.competency-action',
            'games.palace-action',
            'games.power-offer',
            'games.palace-choice',
            'games.scholar',
            'games.innovation',
            'games.pass',
        )) {
            return $next($request);
        }

        $this->confirmation->userId = $request->user()?->id;
        $this->confirmation->acknowledged = array_values(array_filter(
            explode(',', $request->header('X-Confirmed-Power-Loss', '')),
            static fn (string $key): bool => preg_match('/^\d+:\d+:\d+$/D', $key) === 1,
        ));

        try {
            return $next($request);
        } finally {
            $this->confirmation->userId = null;
            $this->confirmation->acknowledged = [];
        }
    }
}
