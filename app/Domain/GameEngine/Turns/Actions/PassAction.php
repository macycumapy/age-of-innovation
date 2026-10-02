<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Turns\Actions;

use App\Domain\Game\Enums\GameStatus;
use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\Enums\GameEventType;
use App\Domain\GameEngine\History\Actions\AppendGameHistoryAction;
use App\Domain\GameEngine\Research\Enums\KnowledgeDiscipline;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use App\Models\Game;
use App\Models\GamePlayer;
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
    public function execute(Game $game, GamePlayer $player, array $knowledgeDisciplines = []): Game
    {
        return DB::transaction(function () use ($game, $player, $knowledgeDisciplines): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;

            if (! $lockedGame->phase->isActionPhase()
                || ! $lockedGame->isActivePlayer($player)
                || $state->pendingInteraction !== null
                || $state->round->hasTakenMainAction
            ) {
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
            $lockedGame->active_game_player_id = $completion['nextActivePlayerId'] ?? $player->id;
            $lockedGame->status = $lockedGame->phase === GamePhase::Finished ? GameStatus::Finished : GameStatus::Active;
            $lockedGame->state = $state;
            $lockedGame->version++;
            $lockedGame->save();
            $this->appendGameHistory->execute($lockedGame, $player, GameActionType::Pass, [
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
                'type' => GameEventType::PlayerPassed->value,
                'player_id' => $player->id,
                'pass_order' => $result['passOrder'],
            ]], $stateVersionBefore, $lockedGame->version, $lockedGame->phase !== $phaseBefore);

            return $lockedGame->refresh();
        });
    }
}
