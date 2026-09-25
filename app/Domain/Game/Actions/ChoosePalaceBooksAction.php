<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GameEventType;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Models\Game;
use App\Models\GamePlayer;

final class ChoosePalaceBooksAction
{
    public function __construct(private DistributeRewardBooksAction $distributeRewardBooks)
    {
    }

    /** @param array<string, int> $bookCounts */
    public function execute(Game $game, GamePlayer $player, array $bookCounts): Game
    {
        return $this->distributeRewardBooks->execute(
            $game,
            $player,
            $bookCounts,
            PendingInteractionType::ChoosePalaceBooks,
            GameActionType::ChoosePalace,
            GameEventType::PalaceBooksChosen,
            'Крепости',
        );
    }
}
