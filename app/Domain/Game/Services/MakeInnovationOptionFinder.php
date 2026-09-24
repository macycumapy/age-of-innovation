<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\BookPaymentData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\MakeInnovationOptionData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\Innovation;
use App\Domain\Game\Enums\TerrainType;

final class MakeInnovationOptionFinder
{
    public function __construct(private InnovationPurchaseCostCalculator $costCalculator)
    {
    }

    /** @return list<MakeInnovationOptionData> */
    public function execute(GameStateData $state, GamePlayerStateData $player): array
    {
        if (! $state->round->phase->isActionPhase()
            || $state->pendingInteraction !== null
            || $state->round->hasTakenMainAction
            || $state->setupPool === null
            || count($player->inventionIds) >= InnovationPurchaseCostCalculator::MAX_INVENTIONS) {
            return [];
        }

        $options = [];
        foreach ($state->setupPool->innovations as $slotIndex => $innovationValue) {
            $innovation = $innovationValue instanceof Innovation
                ? $innovationValue
                : Innovation::from((string) $innovationValue);

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
                continue;
            }

            foreach ($this->payments($player, $cost['requiredBooks'], $cost['totalBooks']) as $payment) {
                $options[] = new MakeInnovationOptionData(
                    $innovation,
                    $payment,
                    $cost['coins'],
                    $cost['totalBooks'],
                );
            }
        }

        return $options;
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
