<?php

declare(strict_types=1);

namespace App\Domain\Automation\Evaluation\Services;

use App\Domain\GameEngine\Board\Actions\FindReachableLandHexesAction;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Economy\Services\PlayerIncomeCalculator;
use App\Domain\GameEngine\Research\Enums\Competency;
use App\Domain\GameEngine\Research\Enums\KnowledgeDiscipline;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;

final class CompetencyEvaluator
{
    private const int VICTORY_POINT_WEIGHT = 10;

    /** Optional future actions are not guaranteed rewards. */
    private const float FUTURE_ACTION_DISCOUNT = 0.5;

    public function __construct(private FindReachableLandHexesAction $findReachableLandHexes)
    {
    }

    public function execute(GameStateData $state, GamePlayerStateData $player): int
    {
        if ($state->round->phase === GamePhase::Finished) {
            return 0;
        }

        $futureRounds = $state->round->phase === GamePhase::Setup ? 6 : max(0, 6 - $state->round->number);
        $currentRoundAvailable = $state->round->phase !== GamePhase::Setup
            && $state->round->phase !== GamePhase::ScienceBonus
            && ! in_array($player->playerId, $state->passedPlayerIds, true);
        $passes = $futureRounds + (int) $currentRoundAvailable;
        $score = 0;
        foreach ($player->competencyIds as $id) {
            $competency = Competency::tryFrom($id);
            $score += match ($competency) {
                Competency::Competency07 => $this->powerScore($player, $futureRounds, $currentRoundAvailable),
                Competency::Competency08, Competency::Competency12 => $competency->passVictoryPoints(
                    count($player->townTileIds),
                    min(array_map(static fn (KnowledgeDiscipline $discipline): int => $player->knowledge->{$discipline->value}, KnowledgeDiscipline::cases())),
                ) * $passes * self::VICTORY_POINT_WEIGHT,
                Competency::Competency09 => (int) round((($currentRoundAvailable ? $player->resources->scholars : 0)
                    + PlayerIncomeCalculator::calculate($player, $state->board, includeRoundBonus: false)->scholars * $futureRounds)
                    * 2 * self::VICTORY_POINT_WEIGHT * self::FUTURE_ACTION_DISCOUNT),
                Competency::Competency11 => $this->edgeScore($state, $player, $passes),
                default => 0,
            };
        }

        return $score;
    }

    private function powerScore(GamePlayerStateData $player, int $futureRounds, bool $currentRoundAvailable): int
    {
        $power = $player->resources->power;
        $firstSteps = min(4, $power->bowlOne);
        $secondSteps = min(4 - $firstSteps, $power->bowlTwo + $firstSteps);
        $currentValue = $currentRoundAvailable && ! in_array(Competency::Competency07->value, $player->usedSpecialActionIds, true)
            ? $firstSteps * 6 + $secondSteps * 5 : 0;
        $futureValue = min(4, $power->bowlOne + $power->bowlTwo + $power->bowlThree) * 5;

        return $currentValue + (int) round($futureValue * $futureRounds * self::FUTURE_ACTION_DISCOUNT);
    }

    private function edgeScore(GameStateData $state, GamePlayerStateData $player, int $passes): int
    {
        $workshops = 0;
        foreach ($state->board->hexes as $hex) {
            if ($hex->building?->ownerPlayerId === $player->playerId && $hex->building->type === BuildingType::Workshop && ! $hex->building->isNeutral) {
                $workshops++;
            }
        }
        $reachable = $this->findReachableLandHexes->execute($state, $player);
        $targets = 0;
        foreach ($state->board->hexes as $hex) {
            if ($hex->building === null && $hex->terrain->isHomeland()
                && in_array($hex->id, $state->board->edgeHexIds, true) && in_array($hex->id, $reachable, true)) {
                $targets++;
            }
        }

        return (int) round(min($targets, max(0, BuildingType::Workshop->supplyLimit() - $workshops), $passes)
            * 3 * self::VICTORY_POINT_WEIGHT * self::FUTURE_ACTION_DISCOUNT);
    }
}
