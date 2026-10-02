<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Interactions\Actions;

use App\Domain\GameEngine\Economy\Actions\ApplyBookDistributionAction;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use Illuminate\Validation\ValidationException;

final class ApplyRewardBookDistributionAction
{
    public function __construct(
        private ApplyBookDistributionAction $applyBookDistribution,
        private AdvancePendingInteractionQueueAction $advancePendingInteractionQueue,
    ) {
    }

    /** @param array<string, int> $bookCounts */
    public function execute(
        GameStateData $state,
        GamePlayerStateData $player,
        array $bookCounts,
        PendingInteractionType $expectedInteractionType,
    ): int {
        $interaction = $state->pendingInteraction;
        $bookCount = (int) ($interaction?->context['bookCount'] ?? 0);

        if (! in_array($expectedInteractionType, $this->supportedInteractionTypes(), true)
            || $interaction?->type !== $expectedInteractionType
            || $interaction->playerId !== $player->playerId) {
            throw ValidationException::withMessages(['book_counts' => 'Сейчас нельзя распределить эти книги.']);
        }

        $this->applyBookDistribution->execute($player, $bookCounts, $bookCount);
        $state->pendingInteraction = null;

        if ($expectedInteractionType === PendingInteractionType::ChoosePalaceBooks) {
            return $this->advancePendingInteractionQueue->execute(
                $state,
                $player,
                (string) ($interaction->context['builtHexId'] ?? ''),
            );
        }

        return $player->playerId;
    }

    /** @return list<PendingInteractionType> */
    private function supportedInteractionTypes(): array
    {
        return [
            PendingInteractionType::ChooseShippingBooks,
            PendingInteractionType::ChooseTerraformingBooks,
            PendingInteractionType::ChoosePalaceBooks,
        ];
    }
}
