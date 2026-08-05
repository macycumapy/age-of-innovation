<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use App\Domain\Game\Enums\PendingInteractionType;
use Spatie\LaravelData\Data;

/**
 * @property PendingInteractionType $type Тип решения, ожидаемого от игрока.
 * @property int $playerId Идентификатор участника, который должен принять решение.
 * @property list<string|int> $optionIds Допустимые варианты решения.
 * @property array<string, mixed> $context Дополнительные данные, необходимые для проверки решения.
 */
class PendingInteractionData extends Data
{
    /**
     * @param list<string|int> $optionIds
     * @param array<string, mixed> $context
     */
    public function __construct(
        public PendingInteractionType $type,
        public int $playerId,
        public array $optionIds = [],
        public array $context = [],
    ) {
    }
}
