<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use App\Domain\Game\Contracts\GameActionOption;
use App\Domain\Game\Enums\GameActionOptionType;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Enums\ResourceExchange;
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
