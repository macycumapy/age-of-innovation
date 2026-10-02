<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Economy\Actions;

use App\Domain\GameEngine\Economy\Services\BookDistributionValidator;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;

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
