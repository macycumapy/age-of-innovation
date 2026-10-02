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
    public function execute(GamePlayerStateData $player): array
    {
        $options = [];
        $power = $player->resources->power->bowlThree;

        if ($power >= 5 && $player->resources->scholars < $player->scholarPoolSize) {
            $options[] = new ResourceExchangeOptionData(ResourceExchange::PowerToScholar);
        }
        if ($power >= 3) {
            $options[] = new ResourceExchangeOptionData(ResourceExchange::PowerToTool);
        }
        if ($power >= 1) {
            $options[] = new ResourceExchangeOptionData(ResourceExchange::PowerToCoin);
        }
        if ($power >= 5) {
            foreach (KnowledgeDiscipline::cases() as $discipline) {
                $options[] = new ResourceExchangeOptionData(ResourceExchange::PowerToBook, $discipline);
            }
        }
        if ($player->resources->scholars >= 1) {
            $options[] = new ResourceExchangeOptionData(ResourceExchange::ScholarToTool);
        }
        if ($player->resources->tools >= 1) {
            $options[] = new ResourceExchangeOptionData(ResourceExchange::ToolToCoin);
        }
        foreach (KnowledgeDiscipline::cases() as $discipline) {
            if ($player->resources->books->{$discipline->value} >= 1) {
                $options[] = new ResourceExchangeOptionData(ResourceExchange::BookToCoin, $discipline);
            }
        }
        if ($player->resources->power->bowlTwo >= 2) {
            $options[] = new SacrificePowerOptionData();
        }

        return $options;
    }
}
