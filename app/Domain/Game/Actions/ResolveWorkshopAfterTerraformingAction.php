<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GameEventType;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ResolveWorkshopAfterTerraformingAction
{
    public function __construct(
        private AppendGameHistoryAction $appendGameHistory,
        private ApplyWorkshopAfterTerraformingAction $applyWorkshopAfterTerraforming,
    ) {
    }

    public function execute(Game $game, User $user, bool $build, ?string $hexId): Game
    {
        return DB::transaction(function () use ($game, $user, $build, $hexId): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $interaction = $state->pendingInteraction;
            $player = $lockedGame->players()->whereBelongsTo($user)->first();

            if (! $lockedGame->phase->isActionPhase()
                || $lockedGame->active_player_id !== $user->id
                || ! $player instanceof GamePlayer
                || $interaction?->type !== PendingInteractionType::BuildWorkshopAfterTerraforming
                || $interaction->playerId !== $player->id) {
                throw ValidationException::withMessages(['game' => 'Сейчас нельзя подтвердить строительство дома.']);
            }

            $playerState = collect($state->players)->firstWhere('playerId', $player->id);

            if (! $playerState instanceof GamePlayerStateData) {
                throw ValidationException::withMessages(['game' => 'Не найдено состояние игрока.']);
            }

            $stateVersionBefore = $lockedGame->version;
            $result = $this->applyWorkshopAfterTerraforming->execute($state, $playerState, $build, $hexId);

            $lockedGame->update([
                'active_game_player_id' => $result->nextActivePlayerId,
                'state' => $state,
                'version' => $lockedGame->version + 1,
            ]);
            $this->appendGameHistory->execute(
                $lockedGame,
                $user,
                GameActionType::TerraformAndBuild,
                [
                    'built' => $build,
                    'hex_id' => $build ? $hexId : null,
                    'victory_points' => $result->victoryPoints,
                    'bonus_coins' => $result->bonusCoins,
                    'scoring_sources' => $result->scoringSources,
                    'feline_bonus_pending' => $result->felineBonusPending,
                    'tool_cost' => $result->toolCost,
                    'coin_cost' => $result->coinCost,
                ],
                [[
                    'type' => $build ? GameEventType::WorkshopBuiltAfterTerraforming->value : GameEventType::WorkshopDeclinedAfterTerraforming->value,
                    'player_id' => $player->id,
                    'hex_id' => $build ? $hexId : null,
                ]],
                $stateVersionBefore,
                $lockedGame->version,
            );

            return $lockedGame->refresh();
        });
    }
}
