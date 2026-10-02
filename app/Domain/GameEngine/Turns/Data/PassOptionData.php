<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Turns\Data;

use App\Domain\GameEngine\Contracts\GameActionOption;
use App\Domain\GameEngine\Enums\GameActionOptionType;
use Spatie\LaravelData\Data;

final class PassOptionData extends Data implements GameActionOption
{
    /** @param list<\App\Domain\GameEngine\Research\Enums\KnowledgeDiscipline> $knowledgeDisciplines */
    public function __construct(public array $knowledgeDisciplines = [])
    {
    }

    public function type(): GameActionOptionType
    {
        return GameActionOptionType::Pass;
    }
}
