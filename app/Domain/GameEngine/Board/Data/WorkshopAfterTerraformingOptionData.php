<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Board\Data;

use App\Domain\GameEngine\Contracts\GameActionOption;
use App\Domain\GameEngine\Enums\GameActionOptionType;
use Spatie\LaravelData\Data;

final class WorkshopAfterTerraformingOptionData extends Data implements GameActionOption
{
    public function __construct(
        public bool $build,
        public ?string $hexId = null,
    ) {
    }

    public function type(): GameActionOptionType
    {
        return GameActionOptionType::ResolveWorkshopAfterTerraforming;
    }
}
