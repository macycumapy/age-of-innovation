<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\DevelopmentAdvancementOptionData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GameEventType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Services\DevelopmentAdvancementOptionFinder;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PerformAdvanceTerraformingAction
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
            if ($lockedGame->phase !== GamePhase::Actions
                || $lockedGame->active_player_id !== $user->id
                || ! $playerState instanceof GamePlayerStateData) {
                throw ValidationException::withMessages(['terraforming' => 'Сейчас нельзя повысить уровень преобразования.']);
            }

            $option = collect($this->optionFinder->execute($state, $playerState))->first(
                static fn (DevelopmentAdvancementOptionData $candidate): bool => $candidate->action === GameActionType::AdvanceTerraforming,
            );

            if (! $option instanceof DevelopmentAdvancementOptionData) {
                throw ValidationException::withMessages(['terraforming' => 'Сейчас нельзя повысить уровень преобразования.']);
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
                GameActionType::AdvanceTerraforming,
                [
                    'tools' => $option->tools,
                    'coins' => $option->coins,
                    'scholars' => $option->scholars,
                    'reward' => $reward->toArray(),
                ],
                [[
                    'type' => GameEventType::TerraformingAdvanced->value,
                    'player_id' => $player->id,
                    'level' => $playerState->terraformingLevel,
                ]],
                $stateVersionBefore,
                $lockedGame->version,
            );

            return $lockedGame->refresh();
        });
    }
}
