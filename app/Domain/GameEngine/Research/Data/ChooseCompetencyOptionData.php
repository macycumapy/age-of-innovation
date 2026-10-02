<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Research\Data;

use App\Domain\GameEngine\Contracts\GameActionOption;
use App\Domain\GameEngine\Enums\GameActionOptionType;
use App\Domain\GameEngine\Research\Enums\Competency;
use Spatie\LaravelData\Data;

final class ChooseCompetencyOptionData extends Data implements GameActionOption
{
    public function __construct(public Competency $competency)
    {
    }

    public function type(): GameActionOptionType
    {
        return GameActionOptionType::ChooseCompetency;
    }
}
