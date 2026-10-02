<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\PlayerAbilities\Actions;

use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\Enums\GameEventType;
use App\Domain\GameEngine\History\Actions\AppendGameHistoryAction;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\PlayerAbilities\Enums\PalaceAbility;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ChoosePalaceAction
{
    public function __construct(
        private AppendGameHistoryAction $appendGameHistory,
        private ApplyChoosePalaceAction $applyChoosePalace,
    ) {
    }

    public function execute(Game $game, GamePlayer $player, PalaceAbility $palace): Game
    {
        return DB::transaction(function () use ($game, $player, $palace): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $interaction = $state->pendingInteraction;

            if (! $lockedGame->phase->isActionPhase()
                || $player->game_id !== $lockedGame->id
                || ! $lockedGame->isActivePlayer($player)
                || $interaction?->type !== PendingInteractionType::ChoosePalace
                || $interaction->playerId !== $player->id) {
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
