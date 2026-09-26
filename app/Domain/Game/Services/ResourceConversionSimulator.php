<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Actions\ApplyResourceExchangeAction;
use App\Domain\Game\Actions\ApplySacrificePowerAction;
use App\Domain\Game\Data\GameActionSimulationData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\ResourceExchangeOptionData;
use App\Domain\Game\Data\SacrificePowerOptionData;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Enums\ResourceExchange;
use InvalidArgumentException;

final class ResourceConversionSimulator
{
    public function __construct(
        private ResourceConversionOptionFinder $optionFinder,
        private ApplyResourceExchangeAction $applyResourceExchange,
        private ApplySacrificePowerAction $applySacrificePower,
    ) {
    }

    public function execute(
        GameStateData $state,
        int $playerId,
        ResourceExchangeOptionData|SacrificePowerOptionData $option,
    ): GameActionSimulationData {
        $simulatedState = $state->deepCopy();
        $player = collect($simulatedState->players)->firstWhere('playerId', $playerId);
        if (! $player instanceof GamePlayerStateData) {
            throw new InvalidArgumentException('Не найдено состояние игрока для симуляции.');
        }

        $isAvailable = collect($this->optionFinder->execute($player))->contains(
            static fn ($candidate): bool => $candidate->toArray() === $option->toArray(),
        );
        if (! $isAvailable) {
            throw new InvalidArgumentException('Недопустимое преобразование ресурсов.');
        }

        if ($option instanceof SacrificePowerOptionData) {
            $this->applySacrificePower->execute($player, $option->amount);
        } else {
            $this->applyResourceExchange->execute($player, $this->exchangePayload($option));
        }

        return new GameActionSimulationData($simulatedState, $player->playerId);
    }

    /** @return array<string, int|array<string, int>> */
    private function exchangePayload(ResourceExchangeOptionData $option): array
    {
        /** @var array<string, int> $powerToBook */
        $powerToBook = array_fill_keys(array_column(KnowledgeDiscipline::cases(), 'value'), 0);
        /** @var array<string, int> $bookToCoin */
        $bookToCoin = array_fill_keys(array_column(KnowledgeDiscipline::cases(), 'value'), 0);

        if ($option->exchange === ResourceExchange::PowerToBook) {
            if ($option->discipline === null) {
                throw new InvalidArgumentException('Для обмена книги нужна дисциплина.');
            }
            $powerToBook[$option->discipline->value] = 1;
        } elseif ($option->exchange === ResourceExchange::BookToCoin) {
            if ($option->discipline === null) {
                throw new InvalidArgumentException('Для обмена книги нужна дисциплина.');
            }
            $bookToCoin[$option->discipline->value] = 1;
        }

        $payload = [
            ResourceExchange::PowerToScholar->value => 0,
            ResourceExchange::PowerToTool->value => 0,
            ResourceExchange::PowerToCoin->value => 0,
            ResourceExchange::ScholarToTool->value => 0,
            ResourceExchange::ToolToCoin->value => 0,
            ResourceExchange::PowerToBook->value => $powerToBook,
            ResourceExchange::BookToCoin->value => $bookToCoin,
        ];

        if (! in_array($option->exchange, [ResourceExchange::PowerToBook, ResourceExchange::BookToCoin], true)) {
            $payload[$option->exchange->value] = 1;
        }

        return $payload;
    }
}
