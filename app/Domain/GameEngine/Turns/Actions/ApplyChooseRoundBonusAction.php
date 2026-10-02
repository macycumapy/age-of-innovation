<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Turns\Actions;

use App\Domain\GameEngine\PlayerAbilities\Data\RoundBonusOfferData;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Turns\Data\ChooseRoundBonusOptionData;
use App\Domain\GameEngine\Turns\Data\ChooseRoundBonusResultData;
use App\Domain\GameEngine\Turns\Services\ChooseRoundBonusOptionFinder;
use App\Models\GamePlayer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

final class ApplyChooseRoundBonusAction
{
    public function __construct(
        private ChooseRoundBonusOptionFinder $optionFinder,
        private CompletePassTurnAction $completePassTurn,
    ) {
    }

    /** @param Collection<int, GamePlayer> $players */
    public function execute(
        GameStateData $state,
        GamePlayerStateData $player,
        ChooseRoundBonusOptionData $option,
        Collection $players,
    ): ChooseRoundBonusResultData {
        $matchingOption = collect($this->optionFinder->execute($state, $player))->first(
            static fn (ChooseRoundBonusOptionData $candidate): bool => $candidate->roundBonus === $option->roundBonus,
        );
        $offerIndex = collect($state->setupPool->availableRoundBonuses)->search(
            static fn (RoundBonusOfferData $offer): bool => $offer->roundBonus === $option->roundBonus,
        );

        if (! $matchingOption instanceof ChooseRoundBonusOptionData || ! is_int($offerIndex)) {
            throw ValidationException::withMessages(['round_bonus' => 'Этот жетон бонуса раунда недоступен.']);
        }

        $oldRoundBonus = $player->roundBonus;
        $offer = $state->setupPool->availableRoundBonuses[$offerIndex];
        array_splice($state->setupPool->availableRoundBonuses, $offerIndex, 1);
        $state->setupPool->availableRoundBonuses[] = new RoundBonusOfferData($oldRoundBonus, 0);
        $player->roundBonus = $offer->roundBonus;
        $player->resources->coins += $offer->coins;
        $completion = $this->completePassTurn->execute($state, $player->playerId, $players);

        return new ChooseRoundBonusResultData(
            $oldRoundBonus,
            $offer->roundBonus,
            $offer->coins,
            $completion['nextActivePlayerId'],
            $completion['phase'],
            $completion['nextRoundStarted'],
            $completion['incomeReceipts'],
            $completion['finalScoring'],
            $completion['scienceBonusReceipts'] ?? [],
        );
    }
}
