<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\InnovationSpecialActionOptionData;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GameEventType;
use App\Domain\Game\Enums\Innovation;
use App\Domain\Game\Services\InnovationSpecialActionOptionFinder;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PerformInnovationAction
{
    public function __construct(
        private InnovationSpecialActionOptionFinder $optionFinder,
        private ApplyInnovationSpecialAction $applyInnovationSpecialAction,
        private AppendGameHistoryAction $appendGameHistory,
    ) {
    }

    public function execute(Game $game, User $user, Innovation $innovation): Game
    {
        return DB::transaction(function () use ($game, $user, $innovation): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $player = $lockedGame->players()->whereBelongsTo($user)->first();
            $playerState = $player instanceof GamePlayer ? collect($state->players)->firstWhere('playerId', $player->id) : null;

            if (! $lockedGame->phase->isActionPhase() || $lockedGame->active_player_id !== $user->id
                || $state->pendingInteraction !== null || $state->round->hasTakenMainAction
                || ! $playerState instanceof GamePlayerStateData) {
                throw ValidationException::withMessages(['innovation' => 'Особое действие этой инновации недоступно.']);
            }

            $option = collect($this->optionFinder->execute($state, $playerState))->first(
                static fn (InnovationSpecialActionOptionData $candidate): bool => $candidate->innovation === $innovation,
            );

            if (! $option instanceof InnovationSpecialActionOptionData) {
                throw ValidationException::withMessages(['innovation' => 'Особое действие этой инновации недоступно.']);
            }

            $stateVersionBefore = $lockedGame->version;

            if ($state->turnStartSnapshot === null) {
                $state->turnStartSnapshot = $state->toArray();
                $state->round->turnStartVersion = $stateVersionBefore;
            }

            $reward = $this->applyInnovationSpecialAction->execute($state, $playerState, $option);
            $lockedGame->update(['state' => $state, 'version' => $lockedGame->version + 1]);
            $this->appendGameHistory->execute(
                $lockedGame,
                $player,
                GameActionType::SpecialAction,
                ['innovation' => $innovation->value, 'reward' => $reward->toArray()],
                [['type' => GameEventType::InnovationActionUsed->value, 'player_id' => $player->id, 'innovation' => $innovation->value]],
                $stateVersionBefore,
                $lockedGame->version
            );

            return $lockedGame->refresh();
        });
    }
}
