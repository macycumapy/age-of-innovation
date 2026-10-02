<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Research\Services;

use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Data\BookPaymentData;
use App\Domain\GameEngine\Interactions\Enums\GameActionAvailabilityReason;
use App\Domain\GameEngine\Research\Data\MakeInnovationOptionData;
use App\Domain\GameEngine\Research\Enums\Innovation;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;

final class MakeInnovationOptionFinder
{
    public function __construct(private InnovationPurchaseCostCalculator $costCalculator)
    {
    }

    /**
     * @param list<GameActionAvailabilityReason> $reasons
     * @return list<MakeInnovationOptionData>
     */
    public function execute(GameStateData $state, GamePlayerStateData $player, array &$reasons = []): array
    {
        $reasons = [];
        if (! $state->round->phase->isActionPhase()
            || $state->pendingInteraction !== null
            || $state->round->hasTakenMainAction
            || $state->setupPool === null
            || count($player->inventionIds) >= InnovationPurchaseCostCalculator::MAX_INVENTIONS) {
            $reasons[] = $state->setupPool === null
                ? GameActionAvailabilityReason::GameSetupUnavailable
                : (count($player->inventionIds) >= InnovationPurchaseCostCalculator::MAX_INVENTIONS
                    ? GameActionAvailabilityReason::SupplyLimitReached
                    : GameActionAvailabilityReason::ActionUnavailable);
            return [];
        }

        $options = [];
        foreach ($state->setupPool->innovations as $slotIndex => $innovationValue) {
            $innovation = $this->innovation($innovationValue);
            if (! in_array($innovation->value, $state->availableInventionIds, true)) {
                continue;
            }

            $cost = $this->costCalculator->cost(
                $state->setupPool->playerCount,
                $slotIndex,
                count($player->inventionIds),
                $player->homeland === TerrainType::Wasteland,
                $this->hasPalace($state, $player),
            );

            if ($player->resources->coins < $cost['coins']) {
                $reasons[] = GameActionAvailabilityReason::InsufficientCoins;
                continue;
            }

            $payments = $this->payments($player, $cost['requiredBooks'], $cost['totalBooks']);
            if ($payments === []) {
                $reasons[] = GameActionAvailabilityReason::InsufficientBooks;
            }
            foreach ($payments as $payment) {
                $options[] = new MakeInnovationOptionData(
                    $innovation,
                    $payment,
                    $cost['coins'],
                    $cost['totalBooks'],
                );
            }
        }

        $reasons = $options !== [] ? [] : array_values(array_unique($reasons, SORT_REGULAR));
        if ($options === [] && $reasons === []) {
            $reasons[] = GameActionAvailabilityReason::SupplyLimitReached;
        }

        return $options;
    }

    private function innovation(mixed $value): Innovation
    {
        return $value instanceof Innovation ? $value : Innovation::from((string) $value);
    }

    /**
     * @param array{banking: int, law: int, engineering: int, medicine: int} $required
     * @return list<BookPaymentData>
     */
    private function payments(GamePlayerStateData $player, array $required, int $total): array
    {
        $available = $player->resources->books;
        $payments = [];

        for ($banking = $required['banking']; $banking <= min($available->banking, $total); $banking++) {
            for ($law = $required['law']; $law <= min($available->law, $total - $banking); $law++) {
                for ($engineering = $required['engineering']; $engineering <= min($available->engineering, $total - $banking - $law); $engineering++) {
                    $medicine = $total - $banking - $law - $engineering;
                    if ($medicine >= $required['medicine'] && $medicine <= $available->medicine) {
                        $payments[] = new BookPaymentData($banking, $law, $engineering, $medicine);
                    }
                }
            }
        }

        return $payments;
    }

    private function hasPalace(GameStateData $state, GamePlayerStateData $player): bool
    {
        return collect($state->board->hexes)->contains(
            static fn (BoardHexStateData $hex): bool => $hex->building?->ownerPlayerId === $player->playerId
                && $hex->building->type === BuildingType::Palace
                && ! $hex->building->isNeutral,
        );
    }
}
