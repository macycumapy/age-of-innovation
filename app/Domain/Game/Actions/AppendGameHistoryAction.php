<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Enums\GameActionType;
use App\Events\GameHistoryChanged;
use App\Models\Game;
use App\Models\GameAction;
use App\Models\GamePlayer;
use App\Models\User;

final class AppendGameHistoryAction
{
    /**
     * @param array<string, mixed> $payload
     * @param list<array<string, mixed>> $events
     */
    public function execute(
        Game $lockedGame,
        User $user,
        GameActionType $type,
        array $payload,
        array $events,
        int $stateVersionBefore,
        int $stateVersionAfter,
        bool $createPhaseCheckpoint = false,
    ): GameAction {
        $nextSequence = ((int) $lockedGame->actions()->max('sequence')) + 1;
        $phaseCheckpointPayload = [];
        $incomeReceipts = $payload['income_receipts'] ?? [];
        $scienceBonusReceipts = $payload['science_bonus_receipts'] ?? [];
        unset($payload['income_receipts']);
        unset($payload['science_bonus_receipts']);

        if ($createPhaseCheckpoint && array_key_exists('final_scoring', $payload)) {
            $phaseCheckpointPayload['final_scoring'] = $payload['final_scoring'];
            unset($payload['final_scoring']);
        }

        $action = $lockedGame->actions()->create([
            'sequence' => $nextSequence,
            'player_id' => $user->id,
            'type' => $type,
            'payload' => $payload,
            'events' => $events,
            'state_version_before' => $stateVersionBefore,
            'state_version_after' => $stateVersionAfter,
        ]);

        if (is_array($scienceBonusReceipts) && $scienceBonusReceipts !== []) {
            $this->appendScienceBonusPhase($lockedGame, $scienceBonusReceipts);
        }

        if (is_array($incomeReceipts) && $incomeReceipts !== []) {
            $this->appendIncomePhase($lockedGame, $incomeReceipts);
        }

        if ($createPhaseCheckpoint) {
            $this->appendPhaseCheckpoint($lockedGame, $phaseCheckpointPayload);
        }

        GameHistoryChanged::dispatch($lockedGame->id);

        return $action;
    }

    /** @param list<array<string, mixed>> $receipts */
    private function appendIncomePhase(Game $game, array $receipts): void
    {
        $game->actions()->create([
            'sequence' => ((int) $game->actions()->max('sequence')) + 1,
            'player_id' => null,
            'type' => GameActionType::IncomePhase,
            'payload' => [
                'round' => $game->round,
                'income_receipts' => $receipts,
            ],
            'events' => [[
                'type' => 'income_phase_resolved',
                'round' => $game->round,
            ]],
            'state_version_before' => $game->version,
            'state_version_after' => $game->version,
        ]);
    }

    /** @param list<array<string, mixed>> $receipts */
    private function appendScienceBonusPhase(Game $game, array $receipts): void
    {
        $game->actions()->create([
            'sequence' => ((int) $game->actions()->max('sequence')) + 1,
            'player_id' => null,
            'type' => GameActionType::ScienceBonusPhase,
            'payload' => [
                'round' => $receipts[0]['round'] ?? $game->round,
                'science_bonus_receipts' => $receipts,
            ],
            'events' => [[
                'type' => 'science_bonus_phase_resolved',
                'round' => $receipts[0]['round'] ?? $game->round,
            ]],
            'state_version_before' => $game->version,
            'state_version_after' => $game->version,
        ]);
    }

    /** @param array<string, mixed> $additionalPayload */
    private function appendPhaseCheckpoint(Game $game, array $additionalPayload): void
    {
        $game->load('players');

        $game->actions()->create([
            'sequence' => ((int) $game->actions()->max('sequence')) + 1,
            'player_id' => null,
            'type' => GameActionType::PhaseCheckpoint,
            'payload' => [
                'phase' => $game->phase->value,
                'game' => [
                    'status' => $game->status->value,
                    'round' => $game->round,
                    'phase' => $game->phase->value,
                    'active_player_id' => $game->active_player_id,
                    'version' => $game->version,
                    'state' => $game->state->toArray(),
                    'started_at' => $game->started_at?->toISOString(),
                    'finished_at' => $game->finished_at?->toISOString(),
                ],
                'players' => $game->players->map(static fn (GamePlayer $player): array => [
                    'id' => $player->id,
                    'color' => $player->color?->value,
                    'faction' => $player->faction?->value,
                    'homeland' => $player->homeland?->value,
                    'is_ready' => $player->is_ready,
                    'result_place' => $player->result_place,
                    'final_score' => $player->final_score,
                ])->values()->all(),
                ...$additionalPayload,
            ],
            'events' => [[
                'type' => 'phase_started',
                'phase' => $game->phase->value,
                'round' => $game->round,
            ]],
            'state_version_before' => $game->version,
            'state_version_after' => $game->version,
        ]);
    }
}
