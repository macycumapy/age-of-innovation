<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GamePhase;
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
        private ApplyBuildingBonusesAction $applyBuildingBonuses,
        private CreatePowerOffersAfterBuildingAction $createPowerOffersAfterBuilding,
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

            if ($lockedGame->phase !== GamePhase::Actions
                || $lockedGame->active_player_id !== $user->id
                || $interaction?->type !== PendingInteractionType::PlacePalaceGuild
                || ! is_string($selectedHexId)
                || ! $player instanceof GamePlayer) {
                throw ValidationException::withMessages(['game' => 'Сначала разместите бесплатный рынок.']);
            }

            $hex = collect($state->board->hexes)->firstWhere('id', $selectedHexId);
            $playerState = collect($state->players)->firstWhere('playerId', $player->id);

            if (! $hex instanceof BoardHexStateData
                || $hex->building?->type !== BuildingType::Guild
                || $hex->building->ownerPlayerId !== $player->id
                || ! $playerState instanceof GamePlayerStateData) {
                throw ValidationException::withMessages(['game' => 'Размещённый рынок не найден.']);
            }

            $stateVersionBefore = $lockedGame->version;
            $bonuses = $this->applyBuildingBonuses->execute($state, $playerState, $hex, BuildingType::Guild);
            $palaceBuiltHexId = (string) ($interaction->context['palaceBuiltHexId'] ?? '');
            $state->pendingInteraction = null;
            $nextActiveUserId = $this->createPowerOffersAfterBuilding->execute(
                $state,
                $player->id,
                $selectedHexId,
                [$palaceBuiltHexId],
            ) ?? $player->user_id;
            $lockedGame->update([
                'active_player_id' => $nextActiveUserId,
                'state' => $state,
                'version' => $lockedGame->version + 1,
            ]);
            $this->appendGameHistory->execute(
                $lockedGame,
                $user,
                GameActionType::PlacePalaceGuild,
                [
                    'hex_id' => $selectedHexId,
                    'palace_built_hex_id' => $palaceBuiltHexId,
                    'victory_points' => $bonuses['victoryPoints'],
                    'bonus_coins' => $bonuses['coins'],
                    'scoring_sources' => $bonuses['sources'],
                ],
                [[
                    'type' => 'palace_guild_placed',
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
