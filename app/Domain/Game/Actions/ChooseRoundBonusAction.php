<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\RoundBonusOfferData;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\GameStatus;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Enums\RoundBonus;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ChooseRoundBonusAction
{
    public function __construct(
        private CompletePassTurnAction $completePassTurn,
        private AppendGameHistoryAction $appendGameHistory,
    ) {
    }

    public function execute(Game $game, User $user, RoundBonus $roundBonus): Game
    {
        return DB::transaction(function () use ($game, $user, $roundBonus): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $interaction = $state->pendingInteraction;
            $player = $lockedGame->players()->whereKey($interaction?->playerId)->whereBelongsTo($user)->first();

            if ($lockedGame->phase !== GamePhase::Actions
                || $lockedGame->active_player_id !== $user->id
                || $interaction?->type !== PendingInteractionType::ChooseRoundBonus
                || ! $player instanceof GamePlayer
                || ! in_array($roundBonus->value, $interaction->optionIds, true)) {
                throw ValidationException::withMessages(['round_bonus' => 'Этот жетон бонуса раунда недоступен.']);
            }

            $playerState = collect($state->players)->firstWhere('playerId', $player->id);
            $offerIndex = collect($state->setupPool?->availableRoundBonuses ?? [])
                ->search(static fn (RoundBonusOfferData $offer): bool => $offer->roundBonus === $roundBonus);

            if (! $playerState instanceof GamePlayerStateData || ! is_int($offerIndex) || $state->setupPool === null) {
                throw ValidationException::withMessages(['round_bonus' => 'Этот жетон бонуса раунда недоступен.']);
            }

            $before = $lockedGame->version;
            $phaseBefore = $lockedGame->phase;
            $oldRoundBonus = $playerState->roundBonus;
            $offer = $state->setupPool->availableRoundBonuses[$offerIndex];
            array_splice($state->setupPool->availableRoundBonuses, $offerIndex, 1);
            $state->setupPool->availableRoundBonuses[] = new RoundBonusOfferData($oldRoundBonus, 0);
            $playerState->roundBonus = $offer->roundBonus;
            $playerState->resources->coins += $offer->coins;
            $completion = $this->completePassTurn->execute($state, $player->id, $lockedGame->players);
            $lockedGame->phase = $completion['phase'];
            $lockedGame->active_player_id = $completion['nextActiveUserId'];
            $lockedGame->status = $completion['phase'] === GamePhase::Finished ? GameStatus::Finished : GameStatus::Active;
            $lockedGame->state = $state;
            $lockedGame->version++;
            $lockedGame->save();
            $this->appendGameHistory->execute(
                $lockedGame,
                $user,
                GameActionType::ChooseRoundBonus,
                [
                'old_round_bonus' => $oldRoundBonus->value,
                'round_bonus' => $roundBonus->value,
                'bonus_coins' => $offer->coins,
                'next_round_started' => $completion['nextRoundStarted'],
                'income_receipts' => $completion['incomeReceipts'],
                'final_scoring' => $completion['finalScoring'],
                'science_bonus_receipts' => $completion['scienceBonusReceipts'] ?? [],
            ],
                [['type' => 'round_bonus_chosen', 'player_id' => $player->id, 'round_bonus' => $roundBonus->value]],
                $before,
                $lockedGame->version,
                $completion['phase'] !== $phaseBefore
            );

            return $lockedGame->refresh();
        });
    }
}
