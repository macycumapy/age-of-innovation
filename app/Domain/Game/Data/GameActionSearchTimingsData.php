<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

final class GameActionSearchTimingsData
{
    public int $optionFindingNanoseconds = 0;

    public int $simulationNanoseconds = 0;

    public int $stateEvaluationNanoseconds = 0;

    public int $roundScoringNanoseconds = 0;

    public int $finalScoringNanoseconds = 0;

    public int $boardPositionNanoseconds = 0;

    public int $economicNeedsNanoseconds = 0;

    /** Includes option finding, simulations and state evaluation inside continuation search. */
    public int $continuationSearchNanoseconds = 0;

    public int $optionFindingCalls = 0;

    public int $simulationCalls = 0;

    public int $stateEvaluationCalls = 0;
}
