<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use App\Domain\Game\Contracts\GameActionOption;
use App\Domain\Game\Enums\GameActionOptionType;
use Spatie\LaravelData\Data;

final class PassOptionData extends Data implements GameActionOption
{
    /** @param list<\App\Domain\Game\Enums\KnowledgeDiscipline> $knowledgeDisciplines */
    public function __construct(public array $knowledgeDisciplines = [])
    {
    }

    public function type(): GameActionOptionType
    {
        return GameActionOptionType::Pass;
    }
}
