<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Enums\ResourceExchange;
use Illuminate\Validation\ValidationException;

final class ApplyResourceExchangeAction
{
    /** @param array<string, int|array<string, int>> $exchanges */
    public function execute(
        GamePlayerStateData $player,
        array $exchanges,
    ): void {
        $books = $player->resources->books;
        $powerToScholar = (int) $exchanges[ResourceExchange::PowerToScholar->value];
        $powerToTool = (int) $exchanges[ResourceExchange::PowerToTool->value];
        $powerToCoin = (int) $exchanges[ResourceExchange::PowerToCoin->value];
        $scholarToTool = (int) $exchanges[ResourceExchange::ScholarToTool->value];
        $toolToCoin = (int) $exchanges[ResourceExchange::ToolToCoin->value];
        $powerToBook = $exchanges[ResourceExchange::PowerToBook->value];
        $bookToCoin = $exchanges[ResourceExchange::BookToCoin->value];
        assert(is_array($powerToBook));
        assert(is_array($bookToCoin));

        $powerSpent = ($powerToScholar * 5) + ($powerToTool * 3) + $powerToCoin;
        $bookExchanges = 0;

        foreach (KnowledgeDiscipline::cases() as $discipline) {
            $bookExchanges += (int) $powerToBook[$discipline->value];
        }

        $powerSpent += $bookExchanges * 5;
        $exchangeCount = $powerToScholar + $powerToTool + $powerToCoin + $scholarToTool + $toolToCoin + $bookExchanges;

        foreach (KnowledgeDiscipline::cases() as $discipline) {
            $exchangeCount += (int) $bookToCoin[$discipline->value];
        }

        $hasEnoughResources = $exchangeCount > 0
            && $powerSpent <= $player->resources->power->bowlThree
            && $scholarToTool <= $player->resources->scholars + $powerToScholar
            && $player->resources->scholars + $powerToScholar - $scholarToTool <= $player->scholarPoolSize
            && $toolToCoin <= $player->resources->tools + $powerToTool + $scholarToTool;

        foreach (KnowledgeDiscipline::cases() as $discipline) {
            $hasEnoughResources = $hasEnoughResources
                && (int) $bookToCoin[$discipline->value]
                    <= $books->{$discipline->value} + (int) $powerToBook[$discipline->value];
        }

        if (! $hasEnoughResources) {
            throw ValidationException::withMessages(['exchanges' => 'Недостаточно ресурсов для выбранного обмена.']);
        }

        $player->resources->power->bowlThree -= $powerSpent;
        $player->resources->power->bowlOne += $powerSpent;
        $player->resources->scholars += $powerToScholar - $scholarToTool;
        $player->resources->tools += $powerToTool + $scholarToTool - $toolToCoin;
        $player->resources->coins += $powerToCoin + $toolToCoin;

        foreach (KnowledgeDiscipline::cases() as $discipline) {
            $booksCreated = (int) $powerToBook[$discipline->value];
            $booksSold = (int) $bookToCoin[$discipline->value];
            $books->{$discipline->value} += $booksCreated - $booksSold;
            $player->resources->coins += $booksSold;
        }
    }
}
