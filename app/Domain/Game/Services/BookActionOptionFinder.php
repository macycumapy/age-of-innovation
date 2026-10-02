<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\BookActionOptionData;
use App\Domain\Game\Data\BookPaymentData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Enums\BookAction;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\GameActionAvailabilityReason;
use App\Domain\Game\Enums\KnowledgeDiscipline;

final class BookActionOptionFinder
{
    /**
     * @param list<GameActionAvailabilityReason> $reasons
     * @return list<BookActionOptionData>
     */
    public function execute(GameStateData $state, GamePlayerStateData $player, array &$reasons = []): array
    {
        $reasons = [];
        if ($state->setupPool === null) {
            $reasons[] = GameActionAvailabilityReason::GameSetupUnavailable;
            return [];
        }

        $options = [];

        foreach ($state->setupPool->bookActions as $actionValue) {
            $action = $this->bookAction($actionValue);

            if (in_array($action->value, $state->round->usedBookActionIds, true)) {
                $reasons[] = GameActionAvailabilityReason::SharedActionsUnavailable;
                continue;
            }

            $payments = $this->payments($player, $action->cost());
            if ($payments === []) {
                $reasons[] = GameActionAvailabilityReason::InsufficientBooks;
            }
            foreach ($payments as $payment) {
                $actionOptions = $this->actionOptions($state, $player, $action, $payment);
                if ($actionOptions === []) {
                    $reasons[] = $this->buildingCount($state, $player->playerId, BuildingType::Guild) >= BuildingType::Guild->supplyLimit()
                        ? GameActionAvailabilityReason::SupplyLimitReached
                        : GameActionAvailabilityReason::NoEligibleTarget;
                }
                foreach ($actionOptions as $option) {
                    $options[] = $option;
                }
            }
        }

        $reasons = $options !== [] ? [] : array_values(array_unique($reasons, SORT_REGULAR));
        if ($options === [] && $reasons === []) {
            $reasons[] = GameActionAvailabilityReason::SharedActionsUnavailable;
        }

        return $options;
    }

    private function bookAction(mixed $action): BookAction
    {
        return $action instanceof BookAction ? $action : BookAction::from((string) $action);
    }

    /** @return list<BookPaymentData> */
    private function payments(GamePlayerStateData $player, int $cost): array
    {
        $payments = [];

        for ($banking = 0; $banking <= min($cost, $player->resources->books->banking); $banking++) {
            for ($law = 0; $law <= min($cost - $banking, $player->resources->books->law); $law++) {
                for ($engineering = 0; $engineering <= min($cost - $banking - $law, $player->resources->books->engineering); $engineering++) {
                    $medicine = $cost - $banking - $law - $engineering;

                    if ($medicine < 0 || $medicine > $player->resources->books->medicine) {
                        continue;
                    }

                    $payments[] = new BookPaymentData($banking, $law, $engineering, $medicine);
                }
            }
        }

        return $payments;
    }

    /** @return list<BookActionOptionData> */
    private function actionOptions(
        GameStateData $state,
        GamePlayerStateData $player,
        BookAction $action,
        BookPaymentData $payment,
    ): array {
        if ($action === BookAction::AdvanceKnowledge) {
            return array_map(
                static fn (KnowledgeDiscipline $discipline): BookActionOptionData => new BookActionOptionData(
                    $action,
                    $payment,
                    discipline: $discipline,
                ),
                KnowledgeDiscipline::cases(),
            );
        }

        if ($action === BookAction::UpgradeToGuild) {
            if ($this->buildingCount($state, $player->playerId, BuildingType::Guild) >= BuildingType::Guild->supplyLimit()) {
                return [];
            }

            return array_values(array_map(
                static fn (BoardHexStateData $hex): BookActionOptionData => new BookActionOptionData(
                    $action,
                    $payment,
                    hexId: $hex->id,
                ),
                array_filter(
                    $state->board->hexes,
                    static fn (BoardHexStateData $hex): bool => $hex->building?->ownerPlayerId === $player->playerId
                        && ! $hex->building->isNeutral
                        && $hex->building->type === BuildingType::Workshop,
                ),
            ));
        }

        return [new BookActionOptionData($action, $payment)];
    }

    private function buildingCount(GameStateData $state, int $playerId, BuildingType $type): int
    {
        return count(array_filter(
            $state->board->hexes,
            static fn (BoardHexStateData $hex): bool => $hex->building?->ownerPlayerId === $playerId
                && ! $hex->building->isNeutral
                && $hex->building->type === $type,
        ));
    }
}
