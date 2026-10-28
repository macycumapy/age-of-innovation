<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Economy\Services;

use App\Domain\GameEngine\Economy\Data\ResourceExchangeOptionData;
use App\Domain\GameEngine\Economy\Data\SacrificePowerOptionData;
use App\Domain\GameEngine\Economy\Enums\ResourceExchange;
use App\Domain\GameEngine\Research\Enums\KnowledgeDiscipline;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;

final class ResourceConversionOptionFinder
{
    /** @return list<ResourceExchangeOptionData|SacrificePowerOptionData> */
    public function execute(GamePlayerStateData $player, bool $includeAllAmounts = false): array
    {
        $power = $player->resources->power->bowlThree;

        $options = [
            ...$this->exchangeOptions(ResourceExchange::PowerToScholar, min(intdiv($power, 5), $player->scholarPoolSize - $player->resources->scholars), $includeAllAmounts),
            ...$this->exchangeOptions(ResourceExchange::PowerToTool, intdiv($power, 3), $includeAllAmounts),
            ...$this->exchangeOptions(ResourceExchange::PowerToCoin, $power, $includeAllAmounts),
        ];
        foreach (KnowledgeDiscipline::cases() as $discipline) {
            array_push($options, ...$this->exchangeOptions(ResourceExchange::PowerToBook, intdiv($power, 5), $includeAllAmounts, $discipline));
        }
        array_push(
            $options,
            ...$this->exchangeOptions(ResourceExchange::ScholarToTool, $player->resources->scholars, $includeAllAmounts),
            ...$this->exchangeOptions(ResourceExchange::ToolToCoin, $player->resources->tools, $includeAllAmounts),
        );
        foreach (KnowledgeDiscipline::cases() as $discipline) {
            array_push($options, ...$this->exchangeOptions(ResourceExchange::BookToCoin, $player->resources->books->{$discipline->value}, $includeAllAmounts, $discipline));
        }
        $maximumSacrifice = intdiv($player->resources->power->bowlTwo, 2);
        for ($amount = 1; $amount <= ($includeAllAmounts ? $maximumSacrifice : min(1, $maximumSacrifice)); $amount++) {
            $options[] = new SacrificePowerOptionData($amount);
        }

        return $options;
    }

    /** @return list<ResourceExchangeOptionData> */
    private function exchangeOptions(ResourceExchange $exchange, int $maximumAmount, bool $includeAllAmounts, ?KnowledgeDiscipline $discipline = null): array
    {
        $options = [];
        for ($amount = 1; $amount <= ($includeAllAmounts ? $maximumAmount : min(1, $maximumAmount)); $amount++) {
            $options[] = new ResourceExchangeOptionData($exchange, $discipline, $amount);
        }

        return $options;
    }
}
