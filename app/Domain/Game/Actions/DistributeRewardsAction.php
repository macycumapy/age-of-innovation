<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Enums\Competency;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Validation\ValidationException;

final class DistributeRewardsAction
{
    public function __construct(
        private ChooseScienceBonusBooksAction $chooseScienceBonusBooks,
        private ChooseInnovationRewardAction $chooseInnovationReward,
        private DistributeRewardBooksAction $distributeRewardBooks,
        private ChooseTownBooksAction $chooseTownBooks,
        private ChooseFelineTownBonusAction $chooseFelineTownBonus,
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
        GamePlayer $player,
        array $bookCounts,
        array $knowledgeCounts = [],
        ?Competency $competency = null,
    ): Game {
        return match ($game->state->pendingInteraction?->type) {
            PendingInteractionType::ChooseStartingResources => $this->chooseStartingResources->execute(
                $game,
                $player,
                $bookCounts,
                $knowledgeCounts,
                $competency,
            ),
            PendingInteractionType::ChooseCompetency => $competency instanceof Competency
                ? $this->chooseCompetency->execute($game, $player, $competency)
                : throw ValidationException::withMessages(['competency_id' => 'Выберите компетенцию.']),
            PendingInteractionType::ChooseScienceBonusBooks => $this->chooseScienceBonusBooks->execute($game, $player, $bookCounts),
            PendingInteractionType::ChooseInnovationReward => $this->chooseInnovationReward->execute($game, $player, $bookCounts, $knowledgeCounts),
            PendingInteractionType::ChooseShippingBooks,
            PendingInteractionType::ChooseTerraformingBooks,
            PendingInteractionType::ChoosePalaceBooks => $this->distributeRewardBooks->execute($game, $player, $bookCounts),
            PendingInteractionType::ChooseTownBooks => $this->chooseTownBooks->execute($game, $player, $bookCounts, $knowledgeCounts),
            PendingInteractionType::ChooseFelineTownBonus => $this->chooseFelineTownBonus->execute($game, $player, $bookCounts, $knowledgeCounts),
            default => throw ValidationException::withMessages(['book_counts' => 'Сейчас нельзя распределить книги.']),
        };
    }
}
