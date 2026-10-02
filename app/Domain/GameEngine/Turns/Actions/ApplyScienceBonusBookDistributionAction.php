<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Turns\Actions;

use App\Domain\GameEngine\Economy\Actions\ApplyBookDistributionAction;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Turns\Data\ScienceBonusBookDistributionResultData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use Illuminate\Validation\ValidationException;

final class ApplyScienceBonusBookDistributionAction
{
    public function __construct(
        private ApplyBookDistributionAction $applyBookDistribution,
        private ResolveScienceBonusPhaseAction $resolveScienceBonusPhase,
    ) {
    }

    /** @param array<string, int> $bookCounts */
    public function execute(
        GameStateData $state,
        GamePlayerStateData $player,
        array $bookCounts,
    ): ScienceBonusBookDistributionResultData {
        $interaction = $state->pendingInteraction;
        $bookCount = (int) ($interaction?->context['bookCount'] ?? 0);

        if ($state->round->phase !== GamePhase::ScienceBonus
            || $interaction?->type !== PendingInteractionType::ChooseScienceBonusBooks
            || $interaction->playerId !== $player->playerId) {
            throw ValidationException::withMessages(['book_counts' => 'Сейчас нельзя выбрать эти книги.']);
        }

        $this->applyBookDistribution->execute($player, $bookCounts, $bookCount);
        $state->pendingInteraction = null;
        [$nextPlayer, $nextPhase, $incomeReceipts, $finalScoring, $scienceBonusReceipts]
            = $this->resolveScienceBonusPhase->execute($state);

        return new ScienceBonusBookDistributionResultData(
            $nextPlayer?->playerId,
            $nextPhase,
            $incomeReceipts,
            $finalScoring,
            $scienceBonusReceipts,
        );
    }

}
