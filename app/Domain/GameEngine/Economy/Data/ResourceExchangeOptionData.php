<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Economy\Data;

use App\Domain\GameEngine\Contracts\GameActionOption;
use App\Domain\GameEngine\Economy\Enums\ResourceExchange;
use App\Domain\GameEngine\Enums\GameActionOptionType;
use App\Domain\GameEngine\Research\Enums\KnowledgeDiscipline;
use Spatie\LaravelData\Data;

final class ResourceExchangeOptionData extends Data implements GameActionOption
{
    public function __construct(
        public ResourceExchange $exchange,
        public ?KnowledgeDiscipline $discipline = null,
    ) {
    }

    public function type(): GameActionOptionType
    {
        return GameActionOptionType::ExchangeResources;
    }
}
