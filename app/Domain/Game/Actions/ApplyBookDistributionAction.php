<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Services\BookDistributionValidator;

final class ApplyBookDistributionAction
{
    public function __construct(private BookDistributionValidator $validator)
    {
    }

    /** @param array<string, int> $bookCounts */
    public function execute(GamePlayerStateData $player, array $bookCounts, int $expectedCount): void
    {
        $this->validator->validate($player, $bookCounts, $expectedCount);

        foreach ($bookCounts as $discipline => $count) {
            $player->resources->books->{$discipline} += $count;
        }
        $player->resources->books->unassigned -= $expectedCount;
    }

}
