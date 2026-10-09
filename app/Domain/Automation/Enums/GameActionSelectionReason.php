<?php

declare(strict_types=1);

namespace App\Domain\Automation\Enums;

enum GameActionSelectionReason: string
{
    case NoLegalActions = 'no_legal_actions';
    case OnlyLegalAction = 'only_legal_action';
    case HighestScore = 'highest_score';
    case PlannedContinuation = 'planned_continuation';
    case StrategicPlan = 'strategic_plan';
}
