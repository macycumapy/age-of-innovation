<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Interactions\Services;

use App\Domain\GameEngine\Board\Services\BuildWorkshopOptionFinder;
use App\Domain\GameEngine\Board\Services\PaidTerraformingOptionFinder;
use App\Domain\GameEngine\Board\Services\PlaceAnnexOptionFinder;
use App\Domain\GameEngine\Board\Services\PlaceBridgeOptionFinder;
use App\Domain\GameEngine\Board\Services\PlaceNeutralBuildingOptionFinder;
use App\Domain\GameEngine\Board\Services\PlacePalaceGuildOptionFinder;
use App\Domain\GameEngine\Board\Services\SpendSpadesOptionFinder;
use App\Domain\GameEngine\Board\Services\UpgradeBuildingOptionFinder;
use App\Domain\GameEngine\Board\Services\WorkshopAfterTerraformingOptionFinder;
use App\Domain\GameEngine\Contracts\GameActionOption;
use App\Domain\GameEngine\Economy\Services\BookActionOptionFinder;
use App\Domain\GameEngine\Economy\Services\PowerActionOptionFinder;
use App\Domain\GameEngine\Economy\Services\PowerOfferOptionFinder;
use App\Domain\GameEngine\Economy\Services\ResourceConversionOptionFinder;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\PlayerAbilities\Services\ChoosePalaceOptionFinder;
use App\Domain\GameEngine\PlayerAbilities\Services\PalaceActionOptionFinder;
use App\Domain\GameEngine\PlayerAbilities\Services\PlayerSpecialActionOptionFinder;
use App\Domain\GameEngine\Research\Services\ChooseCompetencyOptionFinder;
use App\Domain\GameEngine\Research\Services\DevelopmentAdvancementOptionFinder;
use App\Domain\GameEngine\Research\Services\InnovationSpecialActionOptionFinder;
use App\Domain\GameEngine\Research\Services\MakeInnovationOptionFinder;
use App\Domain\GameEngine\Research\Services\SendScholarOptionFinder;
use App\Domain\GameEngine\Setup\Services\PlanningBundleOptionFinder;
use App\Domain\GameEngine\Setup\Services\StartingBuildingOptionFinder;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Towns\Services\ChooseTownOptionFinder;
use App\Domain\GameEngine\Towns\Services\PalaceWaterTownOptionFinder;
use App\Domain\GameEngine\Turns\Services\ChooseRoundBonusOptionFinder;
use App\Domain\GameEngine\Turns\Services\PassOptionFinder;

final class GameActionOptionFinder
{
    public function __construct(
        private PlanningBundleOptionFinder $planningBundleOptionFinder,
        private StartingBuildingOptionFinder $startingBuildingOptionFinder,
        private BookActionOptionFinder $bookActionOptionFinder,
        private PowerActionOptionFinder $powerActionOptionFinder,
        private BuildWorkshopOptionFinder $buildWorkshopOptionFinder,
        private UpgradeBuildingOptionFinder $upgradeBuildingOptionFinder,
        private PaidTerraformingOptionFinder $paidTerraformingOptionFinder,
        private DevelopmentAdvancementOptionFinder $developmentAdvancementOptionFinder,
        private SendScholarOptionFinder $sendScholarOptionFinder,
        private MakeInnovationOptionFinder $makeInnovationOptionFinder,
        private PassOptionFinder $passOptionFinder,
        private ChooseRoundBonusOptionFinder $chooseRoundBonusOptionFinder,
        private InnovationSpecialActionOptionFinder $innovationSpecialActionOptionFinder,
        private PalaceActionOptionFinder $palaceActionOptionFinder,
        private PlayerSpecialActionOptionFinder $playerSpecialActionOptionFinder,
        private ResourceConversionOptionFinder $resourceConversionOptionFinder,
        private PlaceAnnexOptionFinder $placeAnnexOptionFinder,
        private PowerOfferOptionFinder $powerOfferOptionFinder,
        private ChooseTownOptionFinder $chooseTownOptionFinder,
        private WorkshopAfterTerraformingOptionFinder $workshopAfterTerraformingOptionFinder,
        private PalaceWaterTownOptionFinder $palaceWaterTownOptionFinder,
        private ChoosePalaceOptionFinder $choosePalaceOptionFinder,
        private ChooseCompetencyOptionFinder $chooseCompetencyOptionFinder,
        private PlaceNeutralBuildingOptionFinder $placeNeutralBuildingOptionFinder,
        private PlaceBridgeOptionFinder $placeBridgeOptionFinder,
        private SpendSpadesOptionFinder $spendSpadesOptionFinder,
        private PlacePalaceGuildOptionFinder $placePalaceGuildOptionFinder,
        private RewardDistributionOptionFinder $rewardDistributionOptionFinder,
    ) {
    }

    /** @return list<GameActionOption> */
    public function execute(GameStateData $state, int $playerId): array
    {
        $planningBundleOptions = $this->planningBundleOptionFinder->execute($state, $playerId);
        if ($planningBundleOptions !== []) {
            return $planningBundleOptions;
        }

        $player = collect($state->players)->firstWhere('playerId', $playerId);

        if (! $player instanceof GamePlayerStateData) {
            return [];
        }

        $startingBuildingOptions = $this->startingBuildingOptionFinder->execute($state, $player);
        if ($startingBuildingOptions !== []) {
            return $startingBuildingOptions;
        }

        if ($state->pendingInteraction !== null) {
            return $this->pendingOptions($state, $player);
        }

        if (! $state->round->phase->isActionPhase() || $state->round->hasTakenMainAction) {
            return [];
        }

        return [
            ...$this->resourceConversionOptionFinder->execute($player),
            ...$this->passOptionFinder->execute($state, $player),
            ...$this->buildWorkshopOptionFinder->execute($state, $player),
            ...$this->upgradeBuildingOptionFinder->execute($state, $player),
            ...$this->paidTerraformingOptionFinder->execute($state, $player),
            ...$this->developmentAdvancementOptionFinder->execute($state, $player),
            ...$this->sendScholarOptionFinder->execute($state, $player),
            ...$this->powerActionOptionFinder->execute($state, $player),
            ...$this->bookActionOptionFinder->execute($state, $player),
            ...$this->playerSpecialActionOptionFinder->execute($state, $player),
            ...$this->palaceActionOptionFinder->execute($state, $player),
            ...$this->innovationSpecialActionOptionFinder->execute($state, $player),
            ...$this->makeInnovationOptionFinder->execute($state, $player),
            ...$this->placeAnnexOptionFinder->execute($state, $player),
        ];
    }

    /** @return list<GameActionOption> */
    private function pendingOptions(GameStateData $state, GamePlayerStateData $player): array
    {
        if ($state->pendingInteraction?->playerId !== $player->playerId) {
            return [];
        }

        $interactionOptions = match ($state->pendingInteraction->type) {
            PendingInteractionType::PowerOffer => $this->powerOfferOptionFinder->execute($state, $player->playerId),
            PendingInteractionType::ChooseTown => $this->chooseTownOptionFinder->execute($state, $player->playerId),
            PendingInteractionType::ChooseRoundBonus => $this->chooseRoundBonusOptionFinder->execute($state, $player),
            PendingInteractionType::BuildWorkshopAfterTerraforming => $this->workshopAfterTerraformingOptionFinder->execute($state, $player),
            PendingInteractionType::OfferPalaceWaterTown => $this->palaceWaterTownOptionFinder->execute($state, $player->playerId),
            PendingInteractionType::ChoosePalace => $this->choosePalaceOptionFinder->execute($state, $player),
            PendingInteractionType::ChooseCompetency => $this->chooseCompetencyOptionFinder->execute($state, $player),
            PendingInteractionType::PlaceNeutralBuilding => $this->placeNeutralBuildingOptionFinder->execute($state, $player),
            PendingInteractionType::PlaceBridge => $this->placeBridgeOptionFinder->execute($state, $player),
            PendingInteractionType::SpendSpades => $this->spendSpadesOptionFinder->execute($state, $player),
            PendingInteractionType::PlacePalaceGuild => $this->placePalaceGuildOptionFinder->execute($state, $player),
            PendingInteractionType::ChooseTownBooks,
            PendingInteractionType::ChooseFelineTownBonus,
            PendingInteractionType::ChooseScienceBonusBooks,
            PendingInteractionType::ChooseShippingBooks,
            PendingInteractionType::ChooseTerraformingBooks,
            PendingInteractionType::ChoosePalaceBooks,
            PendingInteractionType::ChooseInnovationReward,
            PendingInteractionType::ChooseStartingResources => $this->rewardDistributionOptionFinder->execute($state, $player),
        };
        $resourceOptions = $state->round->phase->isActionPhase()
            ? $this->resourceConversionOptionFinder->execute($player)
            : [];

        return [
            ...$interactionOptions,
            ...$resourceOptions,
            ...$this->paidTerraformingOptionFinder->execute($state, $player),
        ];
    }
}
