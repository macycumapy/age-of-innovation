<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\ChooseCompetencyResultData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\Competency;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GameEventType;
use App\Domain\Game\Enums\GamePhase;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ChooseCompetencyAction
{
    public function __construct(
        private AppendGameHistoryAction $appendGameHistory,
        private ApplyChooseCompetencyAction $applyChooseCompetency,
    ) {
    }

    public function execute(Game $game, GamePlayer $player, Competency $competency): Game
    {
        return DB::transaction(function () use ($game, $player, $competency): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $phaseBefore = $lockedGame->phase;
            $stateVersionBefore = $lockedGame->version;

            if ($player->game_id !== $lockedGame->id || ! $lockedGame->isActivePlayer($player)) {
                throw ValidationException::withMessages([
                    'competency_id' => 'Эта компетенция недоступна.',
                ]);
            }

            $playerState = collect($state->players)->firstWhere('playerId', $player->id);
            if (! $playerState instanceof GamePlayerStateData) {
                throw ValidationException::withMessages(['game' => 'Не найдено состояние игрока.']);
            }

            $result = $this->applyChooseCompetency->execute($state, $playerState, $competency);
            $lockedGame->update([
                'phase' => $result->nextPhase,
                'active_game_player_id' => $result->nextActivePlayerId,
                'state' => $state,
                'version' => $lockedGame->version + 1,
            ]);
            $this->appendGameHistory->execute(
                $lockedGame,
                $player,
                GameActionType::ChooseCompetency,
                $this->historyPayload($state->round->number, $competency, $result),
                $this->historyEvents($state->round->number, $player->id, $competency, $result),
                $stateVersionBefore,
                $lockedGame->version,
                $result->nextPhase !== $phaseBefore,
            );

            return $lockedGame->refresh();
        });
    }

    /** @return array<string, mixed> */
    private function historyPayload(
        int $round,
        Competency $competency,
        ChooseCompetencyResultData $result,
    ): array {
        if ($result->reason === 'starting') {
            return [
                'competency_id' => $competency->value,
                'reason' => $result->reason,
                'income_started' => $result->nextPhase !== GamePhase::Setup,
                'round' => $round,
                'income_receipts' => $result->incomeReceipts,
                'gained_power' => $result->gainedPower,
            ];
        }

        return [
            'competency_id' => $competency->value,
            'reason' => $result->reason,
            'built_hex_id' => $result->reason === 'building' ? $result->builtHexId : null,
            'gained_power' => $result->gainedPower,
            'victory_points' => $result->victoryPoints,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function historyEvents(
        int $round,
        int $playerId,
        Competency $competency,
        ChooseCompetencyResultData $result,
    ): array {
        if ($result->reason === 'starting') {
            return [
                [
                    'type' => GameEventType::StartingCompetencyChosen->value,
                    'player_id' => $playerId,
                    'competency_id' => $competency->value,
                    'next_player_id' => $result->nextActivePlayerId,
                    'next_phase' => $result->nextPhase->value,
                ],
                ...($result->nextPhase !== GamePhase::Setup ? [[
                    'type' => GameEventType::IncomePhaseStarted->value,
                    'round' => $round,
                ]] : []),
            ];
        }

        return [[
            'type' => $result->reason === 'building'
                ? GameEventType::BuildingCompetencyChosen->value
                : GameEventType::InnovationCompetencyChosen->value,
            'player_id' => $playerId,
            'competency_id' => $competency->value,
            'built_hex_id' => $result->reason === 'building' ? $result->builtHexId : null,
        ]];
    }
}
