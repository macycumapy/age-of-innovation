<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\DevelopmentAdvancementOptionData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GameEventType;
use App\Domain\Game\Services\DevelopmentAdvancementOptionFinder;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PerformAdvanceShippingAction
{
    public function __construct(
        private DevelopmentAdvancementOptionFinder $optionFinder,
        private ApplyDevelopmentAdvancementAction $applyDevelopmentAdvancement,
        private AppendGameHistoryAction $appendGameHistory,
    ) {
    }

    public function execute(Game $game, User $user): Game
    {
        return DB::transaction(function () use ($game, $user): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $player = $lockedGame->players()->whereBelongsTo($user)->first();
            $playerState = $player instanceof GamePlayer
                ? collect($state->players)->firstWhere('playerId', $player->id)
                : null;

            if (! $lockedGame->phase->isActionPhase()
                || $lockedGame->active_player_id !== $user->id
                || ! $playerState instanceof GamePlayerStateData) {
                throw ValidationException::withMessages(['shipping' => 'Сейчас нельзя повысить уровень навигации.']);
            }

            $option = collect($this->optionFinder->execute($state, $playerState))->first(
                static fn (DevelopmentAdvancementOptionData $candidate): bool => $candidate->action === GameActionType::AdvanceShipping,
            );

            if (! $option instanceof DevelopmentAdvancementOptionData) {
                throw ValidationException::withMessages(['shipping' => 'Сейчас нельзя повысить уровень навигации.']);
            }

            $stateVersionBefore = $lockedGame->version;

            if ($state->turnStartSnapshot === null) {
                $state->turnStartSnapshot = $state->toArray();
                $state->round->turnStartVersion = $stateVersionBefore;
            }

            $reward = $this->applyDevelopmentAdvancement->execute($state, $playerState, $option);

            $lockedGame->update(['state' => $state, 'version' => $lockedGame->version + 1]);
            $this->appendGameHistory->execute(
                $lockedGame,
                $user,
                GameActionType::AdvanceShipping,
                ['coins' => $option->coins, 'scholars' => $option->scholars, 'reward' => $reward->toArray()],
                [['type' => GameEventType::ShippingAdvanced->value, 'player_id' => $player->id, 'level' => $playerState->shippingLevel]],
                $stateVersionBefore,
                $lockedGame->version,
            );

            return $lockedGame->refresh();
        });
    }
}
