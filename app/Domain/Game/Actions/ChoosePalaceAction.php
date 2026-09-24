<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GameEventType;
use App\Domain\Game\Enums\PalaceAbility;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ChoosePalaceAction
{
    public function __construct(
        private AppendGameHistoryAction $appendGameHistory,
        private ApplyChoosePalaceAction $applyChoosePalace,
    ) {
    }

    public function execute(Game $game, User $user, PalaceAbility $palace): Game
    {
        return DB::transaction(function () use ($game, $user, $palace): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $interaction = $state->pendingInteraction;
            $player = $lockedGame->players()->whereKey($interaction?->playerId)->whereBelongsTo($user)->first();

            if (! $lockedGame->phase->isActionPhase()
                || $lockedGame->active_player_id !== $user->id
                || $interaction?->type !== PendingInteractionType::ChoosePalace
                || ! $player instanceof GamePlayer) {
                throw ValidationException::withMessages(['palace_id' => 'Этот жетон Дворца недоступен.']);
            }

            $playerState = collect($state->players)->firstWhere('playerId', $player->id);
            if (! $playerState instanceof GamePlayerStateData) {
                throw ValidationException::withMessages(['palace_id' => 'Игрок не может выбрать этот жетон Дворца.']);
            }

            $stateVersionBefore = $lockedGame->version;
            $result = $this->applyChoosePalace->execute($state, $playerState, $palace);
            $lockedGame->update([
                'active_game_player_id' => $result->nextActivePlayerId,
                'state' => $state,
                'version' => $lockedGame->version + 1,
            ]);
            $this->appendGameHistory->execute(
                $lockedGame,
                $player,
                GameActionType::ChoosePalace,
                [
                    'palace_id' => $palace->value,
                    'built_hex_id' => $result->builtHexId,
                    'victory_points' => $result->victoryPoints,
                    'gained_power' => $result->gainedPower,
                    'gained_books' => $result->gainedBooks,
                    'gained_spades' => $result->gainedSpades,
                    'shipping_reward' => $result->shippingReward,
                ],
                [[
                    'type' => GameEventType::PalaceChosen->value,
                    'player_id' => $player->id,
                    'palace_id' => $palace->value,
                    'built_hex_id' => $result->builtHexId,
                    'power' => $result->gainedPower,
                    'books' => $result->gainedBooks,
                    'spades' => $result->gainedSpades,
                    'shipping_steps' => $result->shippingReward['steps'],
                ]],
                $stateVersionBefore,
                $lockedGame->version,
            );

            return $lockedGame->refresh();
        });
    }
}
