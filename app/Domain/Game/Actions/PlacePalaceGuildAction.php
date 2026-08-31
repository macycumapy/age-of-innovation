<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\BuildingStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PlacePalaceGuildAction
{
    public function execute(Game $game, User $user, string $hexId): Game
    {
        return DB::transaction(function () use ($game, $user, $hexId): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $interaction = $state->pendingInteraction;
            $player = $lockedGame->players()->whereBelongsTo($user)->first();

            if ($lockedGame->phase !== GamePhase::Actions
                || $lockedGame->active_player_id !== $user->id
                || $interaction?->type !== PendingInteractionType::PlacePalaceGuild
                || ! $player instanceof GamePlayer
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
