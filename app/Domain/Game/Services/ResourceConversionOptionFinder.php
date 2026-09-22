<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\ResourceExchangeOptionData;
use App\Domain\Game\Data\SacrificePowerOptionData;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Enums\ResourceExchange;

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
