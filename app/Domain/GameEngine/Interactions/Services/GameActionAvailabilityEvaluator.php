<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Interactions\Services;

use App\Domain\GameEngine\Board\Services\BuildWorkshopOptionFinder;
use App\Domain\GameEngine\Board\Services\PaidTerraformingOptionFinder;
use App\Domain\GameEngine\Board\Services\UpgradeBuildingOptionFinder;
use App\Domain\GameEngine\Contracts\GameActionOption;
use App\Domain\GameEngine\Economy\Services\BookActionOptionFinder;
use App\Domain\GameEngine\Economy\Services\PowerActionOptionFinder;
use App\Domain\GameEngine\Enums\GameActionOptionType;
use App\Domain\GameEngine\Interactions\Data\GameActionAvailabilityData;
use App\Domain\GameEngine\Interactions\Enums\GameActionAvailabilityReason;
use App\Domain\GameEngine\Research\Services\MakeInnovationOptionFinder;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;

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

    public function __construct(
        private GameActionOptionFinder $optionFinder,
        private BuildWorkshopOptionFinder $buildWorkshopOptionFinder,
        private UpgradeBuildingOptionFinder $upgradeBuildingOptionFinder,
        private PaidTerraformingOptionFinder $paidTerraformingOptionFinder,
        private BookActionOptionFinder $bookActionOptionFinder,
        private PowerActionOptionFinder $powerActionOptionFinder,
        private MakeInnovationOptionFinder $makeInnovationOptionFinder,
    ) {
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
        $finder = match ($type) {
            GameActionOptionType::BuildWorkshop => $this->buildWorkshopOptionFinder,
            GameActionOptionType::UpgradeBuilding => $this->upgradeBuildingOptionFinder,
            GameActionOptionType::PaidTerraforming => $this->paidTerraformingOptionFinder,
            GameActionOptionType::BookAction => $this->bookActionOptionFinder,
            GameActionOptionType::PowerAction => $this->powerActionOptionFinder,
            GameActionOptionType::MakeInnovation => $this->makeInnovationOptionFinder,
            default => null,
        };
        if ($finder !== null) {
            $reasons = [];
            $finder->execute($state, $player, $reasons);

            return $reasons;
        }

        $reasons = match ($type) {
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
            GameActionOptionType::PlaceAnnex => $player->availableAnnexes < 1
                ? [GameActionAvailabilityReason::SupplyLimitReached]
                : [],
            default => [],
        };

        return $reasons === [] ? [GameActionAvailabilityReason::NoEligibleTarget] : $reasons;
    }


}
