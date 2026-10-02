<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Research\Actions;

use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\Enums\GameEventType;
use App\Domain\GameEngine\History\Actions\AppendGameHistoryAction;
use App\Domain\GameEngine\Research\Data\DevelopmentAdvancementOptionData;
use App\Domain\GameEngine\Research\Services\DevelopmentAdvancementOptionFinder;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Models\Game;
use App\Models\GamePlayer;
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

    public function execute(Game $game, GamePlayer $player): Game
    {
        return DB::transaction(function () use ($game, $player): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $playerState = collect($state->players)->firstWhere('playerId', $player->id);

            if (! $lockedGame->phase->isActionPhase()
                || $player->game_id !== $lockedGame->id
                || ! $lockedGame->isActivePlayer($player)
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
                $player,
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
