<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\PendingInteractionType;
use Illuminate\Validation\ValidationException;

final class ApplyChooseTownBooksAction
{
    public function __construct(
        private ApplyBookDistributionAction $applyBookDistribution,
        private StartFelineTownBonusAction $startFelineTownBonus,
        private StartLizardTownBonusAction $startLizardTownBonus,
    ) {
    }

    /** @param array<string, int> $bookCounts */
    public function execute(
        GameStateData $state,
        GamePlayerStateData $player,
        array $bookCounts,
    ): int {
        $interaction = $state->pendingInteraction;
        $bookCount = (int) ($interaction?->context['bookCount'] ?? 0);

        if ($interaction?->type !== PendingInteractionType::ChooseTownBooks
            || $interaction->playerId !== $player->playerId) {
            throw ValidationException::withMessages(['book_counts' => 'Нельзя распределить книги города.']);
        }

        $this->applyBookDistribution->execute($player, $bookCounts, $bookCount);
        $state->pendingInteraction = null;

        if (($interaction->context['felineBonusPending'] ?? false) === true) {
            $this->startFelineTownBonus->execute($state, $player, [
                'builtHexId' => (string) ($interaction->context['builtHexId'] ?? ''),
                'queuedBuiltHexIds' => $interaction->context['queuedBuiltHexIds'] ?? [],
            ]);
        } elseif (($interaction->context['lizardBonusPending'] ?? false) === true
            && $player->faction === Faction::Lizards) {
            $this->startLizardTownBonus->execute($state, $player);
        }

        return $player->playerId;
    }
}
