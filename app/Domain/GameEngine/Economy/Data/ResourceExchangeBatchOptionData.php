<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Economy\Data;

use App\Domain\GameEngine\Contracts\GameActionOption;
use App\Domain\GameEngine\Economy\Enums\ResourceExchange;
use App\Domain\GameEngine\Enums\GameActionOptionType;
use App\Domain\GameEngine\Research\Enums\KnowledgeDiscipline;
use InvalidArgumentException;
use Spatie\LaravelData\Data;

final class ResourceExchangeBatchOptionData extends Data implements GameActionOption
{
    /** @param list<ResourceExchangeOptionData> $exchanges */
    public function __construct(public array $exchanges)
    {
    }

    public function type(): GameActionOptionType
    {
        return GameActionOptionType::ExchangeResources;
    }

    /** @return array<string, int|array<string, int>> */
    public function counts(): array
    {
        if ($this->exchanges === []) {
            throw new InvalidArgumentException('Пакет обменов не может быть пустым.');
        }
        $payload = array_fill_keys(array_column(ResourceExchange::cases(), 'value'), 0);
        $books = array_fill_keys(array_column(KnowledgeDiscipline::cases(), 'value'), 0);
        $payload[ResourceExchange::PowerToBook->value] = $books;
        $payload[ResourceExchange::BookToCoin->value] = $books;
        foreach ($this->exchanges as $exchange) {
            if ($exchange->amount < 1) {
                throw new InvalidArgumentException('Количество обменов должно быть положительным.');
            }
            $isBookExchange = in_array($exchange->exchange, [ResourceExchange::PowerToBook, ResourceExchange::BookToCoin], true);
            if ($isBookExchange !== ($exchange->discipline !== null)) {
                throw new InvalidArgumentException('Некорректная дисциплина обмена.');
            }
            if ($isBookExchange) {
                $counts = $payload[$exchange->exchange->value];
                $counts[$exchange->discipline->value] += $exchange->amount;
                $payload[$exchange->exchange->value] = $counts;
            } else {
                $count = $payload[$exchange->exchange->value];
                $payload[$exchange->exchange->value] = $count + $exchange->amount;
            }
        }

        return $payload;
    }
}
