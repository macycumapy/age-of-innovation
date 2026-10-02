<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\History\Actions;

use App\Domain\GameEngine\Economy\Data\IncomeReceiptData;
use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\Enums\GameEventType;
use App\Events\GameHistoryChanged;
use App\Models\Game;
use App\Models\GameAction;
use App\Models\GamePlayer;

final class AppendGameHistoryAction
{
    /**
     * @param array<string, mixed> $payload
     * @param list<array<string, mixed>> $events
     */
    public function execute(
        Game $lockedGame,
        GamePlayer $player,
        GameActionType $type,
        array $payload,
        array $events,
        int $stateVersionBefore,
        int $stateVersionAfter,
        bool $createPhaseCheckpoint = false,
    ): GameAction {
        $nextSequence = ((int) $lockedGame->actions()->max('sequence')) + 1;
        $phaseCheckpointPayload = [];
        $incomeReceiptValues = $payload['income_receipts'] ?? [];
        $scienceBonusReceiptValues = $payload['science_bonus_receipts'] ?? [];
        $incomeReceipts = $this->incomeReceiptPayloads($incomeReceiptValues);
        $scienceBonusReceipts = is_array($scienceBonusReceiptValues)
            ? array_values(array_filter($scienceBonusReceiptValues, 'is_array'))
            : [];
        unset($payload['income_receipts']);
        unset($payload['science_bonus_receipts']);

        if ($createPhaseCheckpoint && array_key_exists('final_scoring', $payload)) {
            $phaseCheckpointPayload['final_scoring'] = $payload['final_scoring'];
            unset($payload['final_scoring']);
        }

        $action = $lockedGame->actions()->create([
            'sequence' => $nextSequence,
            'player_id' => $player->user_id,
            'game_player_id' => $player->id,
            'type' => $type,
            'payload' => $payload,
            'events' => $events,
            'state_version_before' => $stateVersionBefore,
            'state_version_after' => $stateVersionAfter,
        ]);

        if ($scienceBonusReceipts !== []) {
            $this->appendScienceBonusPhase($lockedGame, $scienceBonusReceipts);
        }

        if ($incomeReceipts !== []) {
            $this->appendIncomePhase($lockedGame, $incomeReceipts);
        }

        if ($createPhaseCheckpoint) {
            $this->appendPhaseCheckpoint($lockedGame, $phaseCheckpointPayload);
        }

        GameHistoryChanged::dispatch($lockedGame->id);

        return $action;
    }

    /** @return list<array<string, mixed>> */
    private function incomeReceiptPayloads(mixed $receipts): array
    {
        if (! is_array($receipts)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $receipt): ?array => match (true) {
                $receipt instanceof IncomeReceiptData => $receipt->toArray(),
                is_array($receipt) => $receipt,
                default => null,
            },
            $receipts,
        )));
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
                'type' => GameEventType::IncomePhaseResolved->value,
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
                'type' => GameEventType::ScienceBonusPhaseResolved->value,
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
                    'active_game_player_id' => $game->active_game_player_id,
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
                'type' => GameEventType::PhaseStarted->value,
                'phase' => $game->phase->value,
                'round' => $game->round,
            ]],
            'state_version_before' => $game->version,
            'state_version_after' => $game->version,
        ]);
    }
}
