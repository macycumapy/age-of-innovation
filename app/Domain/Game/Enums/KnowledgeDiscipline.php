<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

enum KnowledgeDiscipline: string
{
    case Banking = 'banking';
    case Law = 'law';
    case Engineering = 'engineering';
    case Medicine = 'medicine';
}
