<?php

declare(strict_types=1);

namespace App\Domain\Automation\Simulation\Economy\Services;

use App\Domain\Automation\Data\GameActionSimulationData;
use App\Domain\GameEngine\Economy\Actions\ApplyResourceExchangeAction;
use App\Domain\GameEngine\Economy\Actions\ApplySacrificePowerAction;
use App\Domain\GameEngine\Economy\Data\ResourceExchangeBatchOptionData;
use App\Domain\GameEngine\Economy\Data\ResourceExchangeOptionData;
use App\Domain\GameEngine\Economy\Data\SacrificePowerOptionData;
use App\Domain\GameEngine\Economy\Enums\ResourceExchange;
use App\Domain\GameEngine\Economy\Services\ResourceConversionOptionFinder;
use App\Domain\GameEngine\Research\Enums\KnowledgeDiscipline;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
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
        ResourceExchangeOptionData|ResourceExchangeBatchOptionData|SacrificePowerOptionData $option,
    ): GameActionSimulationData {
        if ($option instanceof ResourceExchangeBatchOptionData) {
            $option->counts();
            $result = new GameActionSimulationData($state, $playerId);
            foreach ($option->exchanges as $exchange) {
                $result = $this->execute($result->state, $playerId, $exchange);
            }

            return $result;
        }
        $simulatedState = $state->deepCopy();
        $player = collect($simulatedState->players)->firstWhere('playerId', $playerId);
        if (! $player instanceof GamePlayerStateData) {
            throw new InvalidArgumentException('Не найдено состояние игрока для симуляции.');
        }

        $isAvailable = collect($this->optionFinder->execute($player))->contains(
            static fn (ResourceExchangeOptionData|SacrificePowerOptionData $candidate): bool => match (true) {
                $candidate instanceof ResourceExchangeOptionData && $option instanceof ResourceExchangeOptionData => $candidate->exchange === $option->exchange
                    && $candidate->discipline === $option->discipline,
                $candidate instanceof SacrificePowerOptionData && $option instanceof SacrificePowerOptionData => $option->amount > 0,
                default => false,
            },
        );
        if (! $isAvailable || ($option instanceof ResourceExchangeOptionData && $option->amount < 1)) {
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
            $powerToBook[$option->discipline->value] = $option->amount;
        } elseif ($option->exchange === ResourceExchange::BookToCoin) {
            if ($option->discipline === null) {
                throw new InvalidArgumentException('Для обмена книги нужна дисциплина.');
            }
            $bookToCoin[$option->discipline->value] = $option->amount;
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
            $payload[$option->exchange->value] = $option->amount;
        }

        return $payload;
    }
}
