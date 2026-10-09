<?php

declare(strict_types=1);

namespace App\Domain\Automation\Services;

use App\Domain\Automation\Data\StrategicPlanData;
use App\Domain\GameEngine\Economy\Data\PowerActionOptionData;
use App\Domain\GameEngine\Economy\Data\ResourceExchangeOptionData;
use App\Domain\GameEngine\Economy\Enums\PowerAction;
use App\Domain\GameEngine\Economy\Services\PlayerIncomeCalculator;
use App\Domain\GameEngine\Economy\Services\PowerActionOptionFinder;
use App\Domain\GameEngine\Economy\Services\ResourceConversionOptionFinder;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;

final class StrategicPlanFundingValidator
{
    public function __construct(
        private PowerActionOptionFinder $powerOptions,
        private ResourceConversionOptionFinder $conversionOptions,
        private GameActionSimulator $simulator,
    ) {
    }

    public function execute(GameStateData $state, GamePlayerStateData $player, StrategicPlanData $plan): bool
    {
        $income = PlayerIncomeCalculator::calculate($player, $state->board);
        $rounds = min(2, max(0, 6 - $state->round->number)
            + ($state->round->phase === GamePhase::Income ? 1 : 0));
        $probe = $state->deepCopy();
        $probe->pendingInteraction = null;
        $probe->round->hasTakenMainAction = false;
        $fundedPlayer = collect($probe->players)->firstWhere('playerId', $player->playerId);
        if (! $fundedPlayer instanceof GamePlayerStateData) {
            return false;
        }
        $fundedPlayer->resources->tools += $income->tools * $rounds;
        $fundedPlayer->resources->coins += $income->coins * $rounds;
        $fundedPlayer->resources->scholars = min(
            $player->scholarPoolSize,
            $fundedPlayer->resources->scholars + $income->scholars * $rounds
        );
        $frontier = [$probe];
        $visited = [];
        for ($depth = 0; $depth <= 3; $depth++) {
            $next = [];
            foreach ($frontier as $candidate) {
                $candidatePlayer = collect($candidate->players)->firstWhere('playerId', $player->playerId);
                if (! $candidatePlayer instanceof GamePlayerStateData) {
                    continue;
                }
                if ($this->shortage($candidatePlayer, $plan) === 0) {
                    return true;
                }
                $key = serialize([$candidatePlayer->resources, $candidate->round->usedSharedActionIds]);
                if (isset($visited[$key])) {
                    continue;
                }
                $visited[$key] = true;
                if (count($visited) > 24) {
                    return true;
                }
                $options = [...$this->conversionOptions->execute($candidatePlayer),
                    ...array_filter(
                        $candidate->round->hasTakenMainAction ? [] : $this->powerOptions->execute($candidate, $candidatePlayer),
                        static fn (PowerActionOptionData $option): bool => in_array(
                            $option->action,
                            [PowerAction::GainTools, PowerAction::GainCoins, PowerAction::GainScholar],
                            true
                        )
                    )];
                foreach ($options as $option) {
                    if (! $option instanceof ResourceExchangeOptionData && ! $option instanceof PowerActionOptionData) {
                        continue;
                    }
                    $simulation = $this->simulator->execute($candidate, $player->playerId, $option);
                    $after = collect($simulation->state->players)->firstWhere('playerId', $player->playerId);
                    if (! $after instanceof GamePlayerStateData) {
                        continue;
                    }
                    $booksBefore = $this->bookCount($candidatePlayer);
                    $booksAfter = $this->bookCount($after);
                    if ($booksAfter < $booksBefore && $booksAfter < $plan->reservedBooks) {
                        continue;
                    }
                    if ($this->shortage($after, $plan) >= $this->shortage($candidatePlayer, $plan)) {
                        continue;
                    }
                    if ($depth === 3) {
                        return true;
                    }
                    $next[] = $simulation->state;
                }
            }
            $frontier = $next;
        }

        return false;
    }

    private function shortage(GamePlayerStateData $player, StrategicPlanData $plan): int
    {
        return max(0, $plan->reservedTools - $player->resources->tools)
            + max(0, $plan->reservedCoins - $player->resources->coins)
            + max(0, $plan->reservedScholars - $player->resources->scholars);
    }

    private function bookCount(GamePlayerStateData $player): int
    {
        $books = $player->resources->books;

        return $books->banking + $books->law + $books->engineering + $books->medicine + $books->unassigned;
    }
}
