<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

enum GamePhase: string
{
    case Setup = 'setup';
    case Income = 'income';
    case Actions = 'actions';
    case ScienceBonus = 'science_bonus';
    case Finished = 'finished';
}
