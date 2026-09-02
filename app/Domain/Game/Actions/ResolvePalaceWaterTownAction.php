<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ResolvePalaceWaterTownAction
{
    public function __construct(
        private AppendGameHistoryAction $appendGameHistory,
    ) {
    }

    public function execute(Game $game, User $user, bool $accept, ?string $waterHexId): Game
    {
        return DB::transaction(function () use ($game, $user, $accept, $waterHexId): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $interaction = $state->pendingInteraction;
            $player = $lockedGame->players()->whereKey($interaction?->playerId)->whereBelongsTo($user)->first();
            $townsByWaterHexId = $interaction?->context['townsByWaterHexId'] ?? [];

            if ($lockedGame->phase !== GamePhase::Actions
                || $lockedGame->active_player_id !== $user->id
                || $interaction?->type !== PendingInteractionType::OfferPalaceWaterTown
                || ! $player instanceof GamePlayer
                || ! is_array($townsByWaterHexId)
                || ($accept && (! is_string($waterHexId) || ! isset($townsByWaterHexId[$waterHexId])))) {
                throw ValidationException::withMessages(['town' => 'Сейчас нельзя подтвердить создание города через воду.']);
            }

            $playerState = collect($state->players)->firstWhere('playerId', $player->id);

            if (! $playerState instanceof GamePlayerStateData) {
                throw ValidationException::withMessages(['town' => 'Не найдено состояние игрока.']);
            }

            $stateVersionBefore = $lockedGame->version;
            $builtHexId = (string) ($interaction->context['builtHexId'] ?? '');
            $queuedBuiltHexIds = is_array($interaction->context['queuedBuiltHexIds'] ?? null)
                ? $interaction->context['queuedBuiltHexIds']
                : [];

            if ($accept) {
                $townHexIds = $townsByWaterHexId[$waterHexId];
                $state->pendingInteraction = new PendingInteractionData(
                    PendingInteractionType::ChooseTown,
                    $player->id,
                    array_values(array_unique($state->availableTownTileIds)),
                    [
                        'townHexIds' => [...$townHexIds, $waterHexId],
                        'builtHexId' => $builtHexId,
                        'markerHexId' => $waterHexId,
                        'queuedBuiltHexIds' => $queuedBuiltHexIds,
                    ],
                );
                $nextActiveUserId = $player->user_id;
            } else {
                $state->pendingInteraction = null;
                $nextActiveUserId = $player->user_id;
            }

            $lockedGame->update([
                'active_player_id' => $nextActiveUserId,
                'state' => $state,
                'version' => $lockedGame->version + 1,
            ]);
            $this->appendGameHistory->execute(
                $lockedGame,
                $user,
                $accept ? GameActionType::AcceptPalaceWaterTown : GameActionType::DeclinePalaceWaterTown,
                [
                    'water_hex_id' => $accept ? $waterHexId : null,
                    'built_hex_id' => $builtHexId,
                    'town_hex_ids' => $accept ? $townsByWaterHexId[$waterHexId] : [],
                    'queued_built_hex_ids' => $queuedBuiltHexIds,
                ],
                [['type' => $accept ? 'palace_water_town_accepted' : 'palace_water_town_declined', 'player_id' => $player->id]],
                $stateVersionBefore,
                $lockedGame->version,
            );

            return $lockedGame->refresh();
        });
    }
}
