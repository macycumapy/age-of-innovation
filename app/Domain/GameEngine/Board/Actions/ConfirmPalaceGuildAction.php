<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Board\Actions;

use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\Enums\GameEventType;
use App\Domain\GameEngine\History\Actions\AppendGameHistoryAction;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ConfirmPalaceGuildAction
{
    public function __construct(
        private AppendGameHistoryAction $appendGameHistory,
        private ApplyPlacePalaceGuildAction $applyPlacePalaceGuild,
    ) {
    }

    public function execute(Game $game, GamePlayer $player): Game
    {
        return DB::transaction(function () use ($game, $player): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $interaction = $state->pendingInteraction;
            $selectedHexId = $interaction?->context['selectedHexId'] ?? null;

            if (! $lockedGame->phase->isActionPhase()
                || ! $lockedGame->isActivePlayer($player)
                || $interaction?->type !== PendingInteractionType::PlacePalaceGuild
                || ! is_string($selectedHexId)
            ) {
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
                'active_game_player_id' => $result->nextActivePlayerId,
                'state' => $state,
                'version' => $lockedGame->version + 1,
            ]);
            $this->appendGameHistory->execute(
                $lockedGame,
                $player,
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
