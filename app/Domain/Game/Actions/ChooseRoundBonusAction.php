<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\ChooseRoundBonusOptionData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GameEventType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\GameStatus;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Enums\RoundBonus;
use App\Domain\Game\Services\ChooseRoundBonusOptionFinder;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ChooseRoundBonusAction
{
    public function __construct(
        private ChooseRoundBonusOptionFinder $optionFinder,
        private ApplyChooseRoundBonusAction $applyChooseRoundBonus,
        private AppendGameHistoryAction $appendGameHistory,
    ) {
    }

    public function execute(Game $game, GamePlayer $player, RoundBonus $roundBonus): Game
    {
        return DB::transaction(function () use ($game, $player, $roundBonus): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $interaction = $state->pendingInteraction;

            if (! $lockedGame->phase->isActionPhase()
                || ! $lockedGame->isActivePlayer($player)
                || $interaction?->type !== PendingInteractionType::ChooseRoundBonus
                || ! in_array($roundBonus->value, $interaction->optionIds, true)) {
                throw ValidationException::withMessages(['round_bonus' => 'Этот жетон бонуса раунда недоступен.']);
            }

            $playerState = collect($state->players)->firstWhere('playerId', $player->id);
            if (! $playerState instanceof GamePlayerStateData) {
                throw ValidationException::withMessages(['round_bonus' => 'Этот жетон бонуса раунда недоступен.']);
            }

            $option = collect($this->optionFinder->execute($state, $playerState))->first(
                static fn (ChooseRoundBonusOptionData $candidate): bool => $candidate->roundBonus === $roundBonus,
            );

            if (! $option instanceof ChooseRoundBonusOptionData) {
                throw ValidationException::withMessages(['round_bonus' => 'Этот жетон бонуса раунда недоступен.']);
            }

            $before = $lockedGame->version;
            $phaseBefore = $lockedGame->phase;
            $result = $this->applyChooseRoundBonus->execute($state, $playerState, $option, $lockedGame->players);
            $lockedGame->phase = $result->phase;
            $lockedGame->active_game_player_id = $result->nextActivePlayerId;
            $lockedGame->status = $result->phase === GamePhase::Finished ? GameStatus::Finished : GameStatus::Active;
            $lockedGame->state = $state;
            $lockedGame->version++;
            $lockedGame->save();
            $this->appendGameHistory->execute(
                $lockedGame,
                $player,
                GameActionType::ChooseRoundBonus,
                [
                'old_round_bonus' => $result->oldRoundBonus->value,
                'round_bonus' => $roundBonus->value,
                'bonus_coins' => $result->bonusCoins,
                'next_round_started' => $result->nextRoundStarted,
                'income_receipts' => $result->incomeReceipts,
                'final_scoring' => $result->finalScoring,
                'science_bonus_receipts' => $result->scienceBonusReceipts,
            ],
                [['type' => GameEventType::RoundBonusChosen->value, 'player_id' => $player->id, 'round_bonus' => $roundBonus->value]],
                $before,
                $lockedGame->version,
                $result->phase !== $phaseBefore
            );

            return $lockedGame->refresh();
        });
    }
}
