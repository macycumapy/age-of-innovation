<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Board\Actions;

use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\BuildingStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PlacePalaceGuildAction
{
    public function execute(Game $game, GamePlayer $player, string $hexId): Game
    {
        return DB::transaction(function () use ($game, $player, $hexId): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $interaction = $state->pendingInteraction;

            if (! $lockedGame->phase->isActionPhase()
                || ! $lockedGame->isActivePlayer($player)
                || $interaction?->type !== PendingInteractionType::PlacePalaceGuild
                || $interaction->playerId !== $player->id
                || ($interaction->context['selectedHexId'] ?? null) !== null
                || ! in_array($hexId, $interaction->optionIds, true)) {
                throw ValidationException::withMessages(['hex_id' => 'Здесь нельзя разместить бесплатный рынок.']);
            }

            $hex = collect($state->board->hexes)->firstWhere('id', $hexId);
            $playerState = collect($state->players)->firstWhere('playerId', $player->id);

            if (! $hex instanceof BoardHexStateData
                || ! $playerState instanceof GamePlayerStateData
                || $hex->building !== null
                || $hex->terrain !== $playerState->homeland) {
                throw ValidationException::withMessages(['hex_id' => 'Выберите свободную ячейку родной местности.']);
            }

            $hex->building = new BuildingStateData(BuildingType::Guild, $player->id);
            $interaction->context['selectedHexId'] = $hexId;
            $state->pendingInteraction = $interaction;
            $lockedGame->update(['state' => $state]);

            return $lockedGame->refresh();
        });
    }
}
