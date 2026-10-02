<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

final class GameActionSearchTimingsData
{
    public int $optionFindingNanoseconds = 0;

    public int $simulationNanoseconds = 0;

    /** Time spent on the primary state copy returned by each simulator. */
    public int $simulationStateCopyNanoseconds = 0;

    /** Remaining simulation time, including dispatch and application of game rules. */
    public int $simulationExecutionNanoseconds = 0;

    public int $stateEvaluationNanoseconds = 0;

    public int $roundScoringNanoseconds = 0;

    public int $finalScoringNanoseconds = 0;

    public int $boardPositionNanoseconds = 0;

    public int $economicNeedsNanoseconds = 0;

    /** Includes option finding, simulations and state evaluation inside continuation search. */
    public int $continuationSearchNanoseconds = 0;

    public int $optionFindingCalls = 0;

    public int $simulationCalls = 0;

    /** @var array<string, GameActionSimulationTimingsData> */
    public array $simulationsByAction = [];

    public int $stateEvaluationCalls = 0;
}
