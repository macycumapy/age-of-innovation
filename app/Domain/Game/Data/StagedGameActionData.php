<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use App\Domain\GameEngine\Contracts\GameActionOption;

final readonly class StagedGameActionData
{
    /** A null option represents finishing the action turn. */
    public function __construct(public int $version, public ?GameActionOption $option)
    {
    }
}
