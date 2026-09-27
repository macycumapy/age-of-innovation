<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Game\Actions\CreateAutomatedGameAction;
use App\Domain\Game\Data\AutomatedGameSimulationResultData;
use App\Domain\Game\Enums\GameBotDifficulty;
use App\Domain\Game\Services\AutomatedGameReportBuilder;
use App\Domain\Game\Services\AutomatedGameSimulator;
use App\Jobs\PlayAutomatedTurnJob;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

#[Signature('game:benchmark-bots
    {--games=1 : Количество партий}
    {--players=2 : Количество ботов в партии}
    {--difficulty=fast : fast, balanced или strong}
    {--seed=bot-benchmark : Базовый seed серии}
    {--max-decisions=2000 : Максимум решений на партию}
    {--max-duration=300000 : Максимум миллисекунд на партию}')]
#[Description('Создаёт и запускает серию воспроизводимых партий ботов')]
class BenchmarkAutomatedGamesCommand extends Command
{
    private const string REPORT_DISK = 'local';

    public function handle(
        CreateAutomatedGameAction $createGame,
        AutomatedGameSimulator $simulator,
        AutomatedGameReportBuilder $reportBuilder,
    ): int {
        $gameCount = filter_var($this->option('games'), FILTER_VALIDATE_INT);
        $playerCount = filter_var($this->option('players'), FILTER_VALIDATE_INT);
        $maxDecisions = filter_var($this->option('max-decisions'), FILTER_VALIDATE_INT);
        $maxDuration = filter_var($this->option('max-duration'), FILTER_VALIDATE_INT);
        $difficultyValue = $this->option('difficulty');
        $seed = $this->option('seed');
        $difficulty = is_string($difficultyValue) ? GameBotDifficulty::tryFrom($difficultyValue) : null;

        if (! is_int($gameCount) || $gameCount < 1
            || ! is_int($playerCount) || $playerCount < 2 || $playerCount > 5
            || ! is_int($maxDecisions) || $maxDecisions < 1
            || ! is_int($maxDuration) || $maxDuration < 1
            || ! $difficulty instanceof GameBotDifficulty
            || ! is_string($seed) || $seed === ''
        ) {
            $this->components->error('Некорректные параметры серии симуляций.');

            return self::FAILURE;
        }

        Queue::fake([PlayAutomatedTurnJob::class]);

        $directory = 'bot-reports/batches/'.Str::slug($seed);
        $results = [];
        $errors = [];

        for ($index = 1; $index <= $gameCount; $index++) {
            $gameSeed = "{$seed}-{$index}";

            try {
                $game = $createGame->execute($gameSeed, $playerCount, $difficulty);
                $result = $simulator->execute($game, $maxDecisions, $maxDuration);
                $results[] = $result;
                $this->writeJson(
                    "{$directory}/game-{$index}.json",
                    $reportBuilder->execute($result),
                );
                $this->components->twoColumnDetail(
                    "Партия {$index}/{$gameCount}",
                    $result->completed ? '<fg=green>завершена</>' : '<fg=yellow>остановлена</>',
                );
            } catch (Throwable $exception) {
                $errors[] = ['index' => $index, 'seed' => $gameSeed, 'error' => $exception->getMessage()];
                $this->components->error("Партия {$index}: {$exception->getMessage()}");
            }
        }

        $summary = $this->summary($seed, $difficulty, $playerCount, $results, $errors);
        $this->writeJson("{$directory}/summary.json", $summary);
        $this->table(['Показатель', 'Значение'], [
            ['Партий', $gameCount],
            ['Завершено', $summary['completed_games']],
            ['Ошибок', count($errors)],
            ['Решений', $summary['decisions']],
            ['Среднее решение, мс', $summary['average_decision_milliseconds']],
            ['Исчерпан бюджет', $summary['budget_exhaustions']],
            ['Ранних пасов', $summary['early_passes']],
        ]);
        $this->components->info('Отчёты сохранены: '.self::REPORT_DISK."://{$directory}");

        return count($results) === $gameCount
            && collect($results)->every(static fn (AutomatedGameSimulationResultData $result): bool => $result->completed)
                ? self::SUCCESS
                : self::FAILURE;
    }

    /**
     * @param list<AutomatedGameSimulationResultData> $results
     * @param list<array{index: int, seed: string, error: string}> $errors
     * @return array<string, mixed>
     */
    private function summary(
        string $seed,
        GameBotDifficulty $difficulty,
        int $playerCount,
        array $results,
        array $errors,
    ): array {
        $decisions = collect($results)->flatMap(
            static fn (AutomatedGameSimulationResultData $result): array => $result->decisions,
        );

        return [
            'seed' => $seed,
            'difficulty' => $difficulty->value,
            'player_count' => $playerCount,
            'games' => count($results) + count($errors),
            'completed_games' => collect($results)->filter(
                static fn (AutomatedGameSimulationResultData $result): bool => $result->completed,
            )->count(),
            'decisions' => $decisions->count(),
            'average_decision_milliseconds' => (int) round((float) ($decisions->avg('durationMilliseconds') ?? 0)),
            'maximum_decision_milliseconds' => (int) ($decisions->max('durationMilliseconds') ?? 0),
            'visited_nodes' => (int) $decisions->sum('visitedNodes'),
            'budget_exhaustions' => $decisions->where('budgetExhausted', true)->count(),
            'early_passes' => $decisions->filter(
                static fn ($decision): bool => $decision->passReason !== null && $decision->round < 6,
            )->count(),
            'errors' => $errors,
        ];
    }

    /** @param array<string, mixed> $contents */
    private function writeJson(string $path, array $contents): void
    {
        $written = Storage::disk(self::REPORT_DISK)->put($path, json_encode(
            $contents,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        if (! $written) {
            throw new \RuntimeException("Не удалось сохранить отчёт {$path}.");
        }
    }
}
