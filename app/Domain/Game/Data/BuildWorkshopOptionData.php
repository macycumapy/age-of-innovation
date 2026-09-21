<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use App\Domain\Game\Contracts\GameActionOption;
use Spatie\LaravelData\Data;

final class BuildWorkshopOptionData extends Data implements GameActionOption
{
    public function __construct(public string $hexId)
    {
    }

    public function type(): string
    {
        return 'build_workshop';
    }
}
