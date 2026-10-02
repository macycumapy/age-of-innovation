<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Contracts\GameActionOption;
use App\Domain\Game\Data\GameActionAvailabilityData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Enums\BookAction;
use App\Domain\Game\Enums\GameActionAvailabilityReason;
use App\Domain\Game\Enums\GameActionOptionType;
use App\Domain\Game\Enums\PlayerColor;
use App\Domain\Game\Enums\PowerAction;

final class GameActionAvailabilityEvaluator
{
    /** @var list<GameActionOptionType> */
    private const array TRACKED_TYPES = [
        GameActionOptionType::BuildWorkshop,
        GameActionOptionType::UpgradeBuilding,
        GameActionOptionType::PaidTerraforming,
        GameActionOptionType::AdvanceShipping,
        GameActionOptionType::AdvanceTerraforming,
        GameActionOptionType::SendScholar,
        GameActionOptionType::BookAction,
        GameActionOptionType::PowerAction,
        GameActionOptionType::MakeInnovation,
        GameActionOptionType::PlaceAnnex,
    ];

    public function __construct(private GameActionOptionFinder $optionFinder)
    {
    }

    /** @return list<GameActionAvailabilityData> */
    public function execute(GameStateData $state, GamePlayerStateData $player): array
    {
        $options = $this->optionFinder->execute($state, $player->playerId);

        return array_map(
            function (GameActionOptionType $type) use ($state, $player, $options): GameActionAvailabilityData {
                $optionCount = count(array_filter(
                    $options,
                    static fn (GameActionOption $option): bool => $option->type() === $type,
                ));

                return new GameActionAvailabilityData(
                    $type,
                    $optionCount,
                    $optionCount > 0 ? [] : $this->unavailableReasons($type, $state, $player),
                );
            },
            self::TRACKED_TYPES,
        );
    }

    /** @return list<GameActionAvailabilityReason> */
    private function unavailableReasons(
        GameActionOptionType $type,
        GameStateData $state,
        GamePlayerStateData $player,
    ): array {
        $reasons = match ($type) {
            GameActionOptionType::BuildWorkshop, GameActionOptionType::UpgradeBuilding => [
                ...($player->resources->tools < 1 ? [GameActionAvailabilityReason::InsufficientTools] : []),
                ...($player->resources->coins < 2 ? [GameActionAvailabilityReason::InsufficientCoins] : []),
            ],
            GameActionOptionType::PaidTerraforming => $player->resources->tools < 1
                && $player->unassignedSpades < 1
                    ? [GameActionAvailabilityReason::InsufficientTools]
                    : [],
            GameActionOptionType::AdvanceShipping => [
                ...($player->shippingLevel >= 3 ? [GameActionAvailabilityReason::DevelopmentLimitReached] : []),
                ...($player->resources->coins < 4 ? [GameActionAvailabilityReason::InsufficientCoins] : []),
                ...($player->resources->scholars < 1 ? [GameActionAvailabilityReason::InsufficientScholars] : []),
            ],
            GameActionOptionType::AdvanceTerraforming => [
                ...($player->terraformingLevel >= 2 ? [GameActionAvailabilityReason::DevelopmentLimitReached] : []),
                ...($player->resources->coins < ($player->color === PlayerColor::Brown ? 1 : 5)
                    ? [GameActionAvailabilityReason::InsufficientCoins] : []),
                ...($player->resources->tools < 1 ? [GameActionAvailabilityReason::InsufficientTools] : []),
                ...($player->resources->scholars < 1 ? [GameActionAvailabilityReason::InsufficientScholars] : []),
            ],
            GameActionOptionType::SendScholar => $player->resources->scholars < 1
                ? [GameActionAvailabilityReason::InsufficientScholars]
                : [],
            GameActionOptionType::BookAction => $this->bookActionReasons($state, $player),
            GameActionOptionType::PowerAction => $this->availablePower($player) < $this->minimumPowerCost($player)
                ? [GameActionAvailabilityReason::InsufficientPower]
                : [GameActionAvailabilityReason::SharedActionsUnavailable],
            GameActionOptionType::MakeInnovation => [
                ...($state->setupPool === null ? [GameActionAvailabilityReason::GameSetupUnavailable] : []),
                ...($player->resources->coins < 1 ? [GameActionAvailabilityReason::InsufficientCoins] : []),
                ...($this->bookCount($player) < 1 ? [GameActionAvailabilityReason::InsufficientBooks] : []),
            ],
            GameActionOptionType::PlaceAnnex => $player->availableAnnexes < 1
                ? [GameActionAvailabilityReason::SupplyLimitReached]
                : [],
            default => [],
        };

        return $reasons === [] ? [GameActionAvailabilityReason::NoEligibleTarget] : $reasons;
    }

    private function bookCount(GamePlayerStateData $player): int
    {
        return array_sum($player->resources->books->toArray());
    }

    /** @return list<GameActionAvailabilityReason> */
    private function bookActionReasons(GameStateData $state, GamePlayerStateData $player): array
    {
        if ($state->setupPool === null) {
            return [GameActionAvailabilityReason::GameSetupUnavailable];
        }

        $availableActions = array_filter(
            $state->setupPool->bookActions,
            static fn (BookAction $action): bool => ! in_array(
                $action->value,
                $state->round->usedBookActionIds,
                true,
            ),
        );

        if ($availableActions === []) {
            return [GameActionAvailabilityReason::SharedActionsUnavailable];
        }

        $minimumCost = min(array_map(
            static fn (BookAction $action): int => $action->cost(),
            $availableActions,
        ));

        return $this->bookCount($player) < $minimumCost
            ? [GameActionAvailabilityReason::InsufficientBooks]
            : [];
    }

    private function availablePower(GamePlayerStateData $player): int
    {
        return $player->resources->power->bowlThree + intdiv($player->resources->power->bowlTwo, 2);
    }

    private function minimumPowerCost(GamePlayerStateData $player): int
    {
        return min(array_map(
            static fn (PowerAction $action): int => $action->cost($player->faction),
            PowerAction::cases(),
        ));
    }
}
