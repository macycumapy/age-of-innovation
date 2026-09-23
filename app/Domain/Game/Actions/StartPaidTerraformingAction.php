<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\PaidTerraformingOptionData;
use App\Domain\Game\Services\PaidTerraformingOptionFinder;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StartPaidTerraformingAction
{
    public function __construct(
        private PaidTerraformingOptionFinder $optionFinder,
        private ApplyPaidTerraformingAction $applyPaidTerraforming,
    ) {
    }

    public function execute(
        Game $game,
        User $user,
        string $hexId,
        bool $useAvailable,
        bool $useTunnel = false,
        bool $useFlight = false,
    ): Game {
        return DB::transaction(function () use ($game, $user, $hexId, $useAvailable, $useTunnel, $useFlight): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $player = $lockedGame->players()->whereBelongsTo($user)->first();

            if ($lockedGame->active_player_id !== $user->id || ! $player instanceof GamePlayer) {
                throw ValidationException::withMessages(['game' => 'Сейчас нельзя начать преобразование.']);
            }

            $state = $lockedGame->state;
            $playerState = collect($state->players)->firstWhere('playerId', $player->id);

            if (! $playerState instanceof GamePlayerStateData) {
                throw ValidationException::withMessages(['game' => 'Не найдено состояние игрока.']);
            }

            $option = collect($this->optionFinder->execute($state, $playerState))->first(
                static fn (PaidTerraformingOptionData $candidate): bool => $candidate->hexId === $hexId
                    && $candidate->useAvailable === $useAvailable
                    && $candidate->useTunnel === $useTunnel
                    && $candidate->useFlight === $useFlight,
            );

            if (! $option instanceof PaidTerraformingOptionData) {
                throw ValidationException::withMessages(['hex_id' => 'Эта клетка недоступна для преобразования.']);
            }

            if ($lockedGame->phase->isActionPhase() && $state->turnStartSnapshot === null) {
                $state->turnStartSnapshot = $state->toArray();
                $state->round->turnStartVersion = $lockedGame->version;
            }

            $this->applyPaidTerraforming->execute($state, $playerState, $option);

            $lockedGame->update([
                'state' => $state,
                'version' => $lockedGame->version + 1,
            ]);

            return $lockedGame->refresh();
        });
    }
}
