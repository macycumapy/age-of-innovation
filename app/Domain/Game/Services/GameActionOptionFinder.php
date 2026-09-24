<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Contracts\GameActionOption;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Enums\PendingInteractionType;

final class GameActionOptionFinder
{
    public function __construct(
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
        $player = collect($state->players)->firstWhere('playerId', $playerId);

        if (! $player instanceof GamePlayerStateData) {
            return [];
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
