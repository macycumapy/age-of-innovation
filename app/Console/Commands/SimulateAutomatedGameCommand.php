<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Game\Data\AutomatedGameDecisionData;
use App\Domain\Game\Data\AutomatedGameSimulationResultData;
use App\Domain\Game\Services\AutomatedGameSimulator;
use App\Models\Game;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

#[Signature('game:simulate-bots
    {game : Идентификатор активной партии со всеми ботами}
    {--max-decisions=2000 : Максимальное количество решений}
    {--max-duration=300000 : Максимальная длительность в миллисекундах}')]
#[Description('Синхронно завершает партию ботов и выводит диагностический отчёт')]
class SimulateAutomatedGameCommand extends Command
{
    private const string REPORT_DISK = 'local';

    private const string REPORT_DIRECTORY = 'bot-reports';

    public function handle(AutomatedGameSimulator $simulator): int
    {
        $gameId = filter_var($this->argument('game'), FILTER_VALIDATE_INT);
        $maxDecisions = filter_var($this->option('max-decisions'), FILTER_VALIDATE_INT);
        $maxDuration = filter_var($this->option('max-duration'), FILTER_VALIDATE_INT);

        if (! is_int($gameId) || ! is_int($maxDecisions) || ! is_int($maxDuration)) {
            $this->components->error('Идентификатор и лимиты должны быть целыми числами.');

            return self::FAILURE;
        }

        $game = Game::query()->find($gameId);

        if (! $game instanceof Game) {
            $this->components->error('Партия не найдена.');

            return self::FAILURE;
        }

        try {
            $result = $simulator->execute($game, $maxDecisions, $maxDuration);
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->renderSummary($result);

        if (! $this->writeReport($game->id, $result)) {
            return self::FAILURE;
        }

        return $result->completed ? self::SUCCESS : self::FAILURE;
    }

    private function renderSummary(AutomatedGameSimulationResultData $result): void
    {
        $durations = array_column($result->decisions, 'durationMilliseconds');
        $visitedNodes = array_column($result->decisions, 'visitedNodes');
        $passes = array_filter(
            $result->decisions,
            static fn (AutomatedGameDecisionData $decision): bool => $decision->passReason !== null,
        );

        $this->table(['Показатель', 'Значение'], [
            ['Завершена', $result->completed ? 'да' : 'нет'],
            ['Причина остановки', $result->stoppedReason ?? '—'],
            ['Решений', count($result->decisions)],
            ['Длительность, мс', $result->durationMilliseconds],
            ['Среднее решение, мс', $durations === [] ? 0 : (int) round(array_sum($durations) / count($durations))],
            ['Максимальное решение, мс', $durations === [] ? 0 : max($durations)],
            ['Посещено узлов', array_sum($visitedNodes)],
            ['Исчерпан бюджет', count(array_filter(
                $result->decisions,
                static fn (AutomatedGameDecisionData $decision): bool => $decision->budgetExhausted,
            ))],
            ['Пасов', count($passes)],
            ['Ранних пасов', count(array_filter(
                $passes,
                static fn (AutomatedGameDecisionData $decision): bool => $decision->round < 6,
            ))],
        ]);
        $this->table(
            ['Игрок', 'Итоговые ПО'],
            collect($result->finalScores)->map(
                static fn (int $score, int|string $playerId): array => [$playerId, $score],
            )->values()->all(),
        );
    }

    private function writeReport(
        int $gameId,
        AutomatedGameSimulationResultData $result,
    ): bool {
        try {
            $path = self::REPORT_DIRECTORY."/game-{$gameId}.json";
            $written = Storage::disk(self::REPORT_DISK)->put($path, json_encode(
                $this->report($result),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ));

            if (! $written) {
                $this->components->error('Не удалось сохранить отчёт в локальном хранилище.');

                return false;
            }

            $this->components->info('Отчёт сохранён: '.self::REPORT_DISK."://{$path}");

            return true;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return false;
        }
    }

    /** @return array<string, mixed> */
    private function report(AutomatedGameSimulationResultData $result): array
    {
        return [
            'completed' => $result->completed,
            'stopped_reason' => $result->stoppedReason,
            'duration_milliseconds' => $result->durationMilliseconds,
            'final_scores' => $result->finalScores,
            'decisions' => array_map(
                static fn (AutomatedGameDecisionData $decision): array => [
                    'game_player_id' => $decision->gamePlayerId,
                    'round' => $decision->round,
                    'phase' => $decision->phase->value,
                    'action_type' => $decision->actionType?->value,
                    'selected_score' => $decision->selectedScore,
                    'candidates' => $decision->candidates,
                    'visited_nodes' => $decision->visitedNodes,
                    'duration_milliseconds' => $decision->durationMilliseconds,
                    'budget_exhausted' => $decision->budgetExhausted,
                    'pass_reason' => $decision->passReason,
                    'remaining_resources' => $decision->remainingResources === null ? null : [
                        'coins' => $decision->remainingResources->coins,
                        'tools' => $decision->remainingResources->tools,
                        'scholars' => $decision->remainingResources->scholars,
                        'books' => $decision->remainingResources->books,
                        'power' => $decision->remainingResources->power,
                        'spades' => $decision->remainingResources->spades,
                    ],
                ],
                $result->decisions,
            ),
        ];
    }
}
