<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Models\Game;
use App\Models\User;

final class ChoosePalaceBooksAction
{
    public function __construct(private DistributeRewardBooksAction $distributeRewardBooks)
    {
    }

    /** @param array<string, int> $bookCounts */
    public function execute(Game $game, User $user, array $bookCounts): Game
    {
        return $this->distributeRewardBooks->execute(
            $game,
            $user,
            $bookCounts,
            PendingInteractionType::ChoosePalaceBooks,
            GameActionType::ChoosePalace,
            'palace_books_chosen',
            'Крепости',
            true,
        );
    }
}
