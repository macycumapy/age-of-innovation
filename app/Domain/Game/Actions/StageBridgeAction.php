<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StageBridgeAction
{
    public function __construct(private FindEligibleBridgePairsAction $findEligibleBridgePairs)
    {
    }

    public function execute(Game $game, User $user, string $fromHexId, string $toHexId): Game
    {
        return DB::transaction(function () use ($game, $user, $fromHexId, $toHexId): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $interaction = $state->pendingInteraction;
            $player = $lockedGame->players()
                ->whereKey($interaction?->playerId)
                ->whereBelongsTo($user)
                ->first();

            if ($lockedGame->phase !== GamePhase::Actions
                || $lockedGame->active_player_id !== $user->id
                || $interaction?->type !== PendingInteractionType::PlaceBridge
                || isset($interaction->context['selectedFromHexId'])
                || ! $player instanceof GamePlayer
                || ! $this->containsPair(
                    $this->findEligibleBridgePairs->execute(
                        $state,
                        $player->id,
                        canBuildAcrossTerrain: ($interaction->context['source'] ?? null) === 'faction',
                    ),
                    $fromHexId,
                    $toHexId,
                )) {
                throw ValidationException::withMessages(['bridge' => 'Это место недоступно для строительства моста.']);
            }

            $interaction->context['selectedFromHexId'] = $fromHexId;
            $interaction->context['selectedToHexId'] = $toHexId;
            $state->pendingInteraction = $interaction;
            $lockedGame->update(['state' => $state]);

            return $lockedGame->refresh();
        });
    }

    /** @param list<array{fromHexId: string, toHexId: string}> $pairs */
    private function containsPair(array $pairs, string $fromHexId, string $toHexId): bool
    {
        return collect($pairs)->contains(
            static fn (mixed $pair): bool => is_array($pair)
                && ($pair['fromHexId'] ?? null) === $fromHexId
                && ($pair['toHexId'] ?? null) === $toHexId,
        );
    }
}
