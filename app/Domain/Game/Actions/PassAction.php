<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\GameStatus;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PassAction
{
    public function __construct(
        private BeginPassAction $beginPass,
        private AppendGameHistoryAction $appendGameHistory,
    ) {
    }

    /** @param list<KnowledgeDiscipline> $knowledgeDisciplines */
    public function execute(Game $game, User $user, array $knowledgeDisciplines = []): Game
    {
        return DB::transaction(function () use ($game, $user, $knowledgeDisciplines): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $player = $lockedGame->players()->whereBelongsTo($user)->first();

            if ($lockedGame->phase !== GamePhase::Actions
                || $lockedGame->active_player_id !== $user->id
                || $state->pendingInteraction !== null
                || $state->round->hasTakenMainAction
                || ! $player instanceof GamePlayer) {
                throw ValidationException::withMessages(['game' => 'Сейчас нельзя спасовать.']);
            }

            $playerState = collect($state->players)->firstWhere('playerId', $player->id);

            if (! $playerState instanceof GamePlayerStateData) {
                throw ValidationException::withMessages(['game' => 'Не найдено состояние игрока.']);
            }

            $stateVersionBefore = $lockedGame->version;
            $phaseBefore = $lockedGame->phase;
            $oldRoundBonus = $playerState->roundBonus;
            $roundNumber = $state->round->number;
            $result = $this->beginPass->execute($state, $playerState, $lockedGame->players, $knowledgeDisciplines);
            $completion = $result['completion'];
            $lockedGame->phase = $completion['phase'] ?? GamePhase::Actions;
            $lockedGame->active_player_id = $completion['nextActiveUserId'] ?? $user->id;
            $lockedGame->status = $lockedGame->phase === GamePhase::Finished ? GameStatus::Finished : GameStatus::Active;
            $lockedGame->state = $state;
            $lockedGame->version++;
            $lockedGame->save();
            $this->appendGameHistory->execute($lockedGame, $user, GameActionType::Pass, [
                'old_round_bonus' => $oldRoundBonus->value,
                'knowledge_disciplines' => array_map(
                    static fn (KnowledgeDiscipline $discipline): string => $discipline->value,
                    $knowledgeDisciplines,
                ),
                'pass_order' => $result['passOrder'],
                'victory_points' => $result['victoryPoints'],
                'scoring_sources' => $result['scoringSources'],
                'next_round_started' => $completion['nextRoundStarted'] ?? false,
                'science_bonus_started' => $roundNumber < 6
                    && $completion !== null
                    && $result['passOrder'] === count($state->turnOrder),
                'round' => $roundNumber,
                'income_receipts' => $completion['incomeReceipts'] ?? [],
                'final_scoring' => $completion['finalScoring'] ?? [],
                'final_resource_conversion' => $result['finalResourceConversion'],
                'gained_power' => $result['gainedPower'],
            ], [[
                'type' => 'player_passed',
                'player_id' => $player->id,
                'pass_order' => $result['passOrder'],
            ]], $stateVersionBefore, $lockedGame->version, $lockedGame->phase !== $phaseBefore);

            return $lockedGame->refresh();
        });
    }
}
