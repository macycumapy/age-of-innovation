<?php

declare(strict_types=1);

namespace App\Domain\Automation\Enums;

enum AutomatedGamePassReason: string
{
    case OnlyLegalAction = 'only_legal_action';
    case PreferredOverAlternatives = 'preferred_over_alternatives';
}
