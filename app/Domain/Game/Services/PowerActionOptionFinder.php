<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Actions\FindEligibleBridgePairsAction;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PowerActionOptionData;
use App\Domain\Game\Enums\GameActionAvailabilityReason;
use App\Domain\Game\Enums\PowerAction;

final class PowerActionOptionFinder
{
    public function __construct(
        private FindEligibleBridgePairsAction $findEligibleBridgePairs,
        private BridgeSupply $bridgeSupply,
    ) {
    }

    /**
     * @param list<GameActionAvailabilityReason> $reasons
     * @return list<PowerActionOptionData>
     */
    public function execute(GameStateData $state, GamePlayerStateData $player, array &$reasons = []): array
    {
        $reasons = [];
        $options = [];

        foreach (PowerAction::cases() as $action) {
            if (in_array($action->value, $state->round->usedSharedActionIds, true)) {
                $reasons[] = GameActionAvailabilityReason::SharedActionsUnavailable;
            } elseif ($action === PowerAction::GainScholar && $player->resources->scholars >= $player->scholarPoolSize) {
                $reasons[] = GameActionAvailabilityReason::SupplyLimitReached;
            } elseif ($action === PowerAction::BuildBridge && $this->bridgeSupply->remaining($state, $player) === 0) {
                $reasons[] = GameActionAvailabilityReason::SupplyLimitReached;
            } elseif ($action === PowerAction::BuildBridge && $this->findEligibleBridgePairs->execute($state, $player->playerId) === []) {
                $reasons[] = GameActionAvailabilityReason::NoEligibleTarget;
            }
            if (in_array($action->value, $state->round->usedSharedActionIds, true)
                || ($action === PowerAction::GainScholar && $player->resources->scholars >= $player->scholarPoolSize)
                || ($action === PowerAction::BuildBridge && (
                    $this->bridgeSupply->remaining($state, $player) === 0
                    || $this->findEligibleBridgePairs->execute($state, $player->playerId) === []
                ))) {
                continue;
            }

            $sacrificeAmount = max(0, $action->cost($player->faction) - $player->resources->power->bowlThree);

            if ($sacrificeAmount * 2 <= $player->resources->power->bowlTwo) {
                $options[] = new PowerActionOptionData($action, $sacrificeAmount);
            } else {
                $reasons[] = GameActionAvailabilityReason::InsufficientPower;
            }
        }

        $reasons = $options !== [] ? [] : array_values(array_unique($reasons, SORT_REGULAR));

        return $options;
    }
}
