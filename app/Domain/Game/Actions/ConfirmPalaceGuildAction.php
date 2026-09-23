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

final class ConfirmPalaceGuildAction
{
    public function __construct(
        private AppendGameHistoryAction $appendGameHistory,
        private ApplyPlacePalaceGuildAction $applyPlacePalaceGuild,
    ) {
    }

    public function execute(Game $game, User $user): Game
    {
        return DB::transaction(function () use ($game, $user): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $interaction = $state->pendingInteraction;
            $selectedHexId = $interaction?->context['selectedHexId'] ?? null;
            $player = $lockedGame->players()->whereBelongsTo($user)->first();

            if (! $lockedGame->phase->isActionPhase()
                || $lockedGame->active_player_id !== $user->id
                || $interaction?->type !== PendingInteractionType::PlacePalaceGuild
                || ! is_string($selectedHexId)
                || ! $player instanceof GamePlayer) {
                throw ValidationException::withMessages(['game' => 'Сначала разместите бесплатный рынок.']);
            }

            $playerState = collect($state->players)->firstWhere('playerId', $player->id);

            if (! $playerState instanceof GamePlayerStateData) {
                throw ValidationException::withMessages(['game' => 'Размещённый рынок не найден.']);
            }

            $stateVersionBefore = $lockedGame->version;
            $result = $this->applyPlacePalaceGuild->execute(
                $state,
                $playerState,
                $selectedHexId,
            );
            $lockedGame->update([
                'active_player_id' => $result->nextActiveUserId,
                'state' => $state,
                'version' => $lockedGame->version + 1,
            ]);
            $this->appendGameHistory->execute(
                $lockedGame,
                $user,
                GameActionType::PlacePalaceGuild,
                [
                    'hex_id' => $selectedHexId,
                    'palace_built_hex_id' => $result->palaceBuiltHexId,
                    'victory_points' => $result->bonuses['victoryPoints'],
                    'bonus_coins' => $result->bonuses['coins'],
                    'scoring_sources' => $result->bonuses['sources'],
                ],
                [[
                    'type' => GameEventType::PalaceGuildPlaced->value,
                    'player_id' => $player->id,
                    'hex_id' => $selectedHexId,
                ]],
                $stateVersionBefore,
                $lockedGame->version,
            );

            return $lockedGame->refresh();
        });
    }
}
