<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Enums\Competency;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Models\Game;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class DistributeRewardsAction
{
    public function __construct(
        private ChooseScienceBonusBooksAction $chooseScienceBonusBooks,
        private ChooseInnovationRewardAction  $chooseInnovationReward,
        private ChooseShippingBooksAction     $chooseShippingBooks,
        private ChooseTerraformingBooksAction $chooseTerraformingBooks,
        private ChoosePalaceBooksAction       $choosePalaceBooks,
        private ChooseTownBooksAction         $chooseTownBooks,
        private ChooseFelineTownBonusAction   $chooseFelineTownBonus,
        private ChooseStartingResourcesAction $chooseStartingResources,
        private ChooseCompetencyAction        $chooseCompetency,
    ) {
    }

    /**
     * @param array<string, int> $bookCounts
     * @param array<string, int> $knowledgeCounts
     */
    public function execute(
        Game $game,
        User $user,
        array $bookCounts,
        array $knowledgeCounts = [],
        ?Competency $competency = null,
    ): Game {
        return match ($game->state->pendingInteraction?->type) {
            PendingInteractionType::ChooseStartingResources => $this->chooseStartingResources->execute(
                $game,
                $user,
                $bookCounts,
                $knowledgeCounts,
                $competency,
            ),
            PendingInteractionType::ChooseCompetency => $competency instanceof Competency
                ? $this->chooseCompetency->execute($game, $user, $competency)
                : throw ValidationException::withMessages(['competency_id' => 'Выберите компетенцию.']),
            PendingInteractionType::ChooseScienceBonusBooks => $this->chooseScienceBonusBooks->execute($game, $user, $bookCounts),
            PendingInteractionType::ChooseInnovationReward => $this->chooseInnovationReward->execute($game, $user, $bookCounts, $knowledgeCounts),
            PendingInteractionType::ChooseShippingBooks => $this->chooseShippingBooks->execute($game, $user, $bookCounts),
            PendingInteractionType::ChooseTerraformingBooks => $this->chooseTerraformingBooks->execute($game, $user, $bookCounts),
            PendingInteractionType::ChoosePalaceBooks => $this->choosePalaceBooks->execute($game, $user, $bookCounts),
            PendingInteractionType::ChooseTownBooks => $this->chooseTownBooks->execute($game, $user, $bookCounts, $knowledgeCounts),
            PendingInteractionType::ChooseFelineTownBonus => $this->chooseFelineTownBonus->execute($game, $user, $bookCounts, $knowledgeCounts),
            default => throw ValidationException::withMessages(['book_counts' => 'Сейчас нельзя распределить книги.']),
        };
    }

}
