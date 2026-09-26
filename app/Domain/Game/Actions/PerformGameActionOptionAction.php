<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Contracts\GameActionOption;
use App\Domain\Game\Data\BookActionOptionData;
use App\Domain\Game\Data\BuildWorkshopOptionData;
use App\Domain\Game\Data\ChooseCompetencyOptionData;
use App\Domain\Game\Data\ChoosePalaceOptionData;
use App\Domain\Game\Data\ChooseRoundBonusOptionData;
use App\Domain\Game\Data\ChooseTownOptionData;
use App\Domain\Game\Data\DevelopmentAdvancementOptionData;
use App\Domain\Game\Data\InnovationSpecialActionOptionData;
use App\Domain\Game\Data\MakeInnovationOptionData;
use App\Domain\Game\Data\PaidTerraformingOptionData;
use App\Domain\Game\Data\PalaceActionOptionData;
use App\Domain\Game\Data\PalaceWaterTownOptionData;
use App\Domain\Game\Data\PassOptionData;
use App\Domain\Game\Data\PlaceAnnexOptionData;
use App\Domain\Game\Data\PlaceBridgeOptionData;
use App\Domain\Game\Data\PlaceNeutralBuildingOptionData;
use App\Domain\Game\Data\PlacePalaceGuildOptionData;
use App\Domain\Game\Data\PlanningBundleOptionData;
use App\Domain\Game\Data\PlayerSpecialActionOptionData;
use App\Domain\Game\Data\PowerActionOptionData;
use App\Domain\Game\Data\PowerOfferOptionData;
use App\Domain\Game\Data\ResourceExchangeOptionData;
use App\Domain\Game\Data\RewardDistributionOptionData;
use App\Domain\Game\Data\SacrificePowerOptionData;
use App\Domain\Game\Data\SendScholarOptionData;
use App\Domain\Game\Data\SkipBridgeOptionData;
use App\Domain\Game\Data\SpendSpadesOptionData;
use App\Domain\Game\Data\StartingBuildingOptionData;
use App\Domain\Game\Data\UpgradeBuildingOptionData;
use App\Domain\Game\Data\WorkshopAfterTerraformingOptionData;
use App\Domain\Game\Enums\GameActionOptionType;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Enums\ResourceExchange;
use App\Models\Game;
use App\Models\GamePlayer;
use DomainException;

final class PerformGameActionOptionAction
{
    public function __construct(
        private ChoosePlanningBundleAction $choosePlanningBundle,
        private FinishStartingBuildingTurnAction $finishStartingBuildingTurn,
        private PerformBookActionAction $performBookAction,
        private PerformPowerActionAction $performPowerAction,
        private BuildWorkshopAction $buildWorkshop,
        private UpgradeBuildingAction $upgradeBuilding,
        private StartPaidTerraformingAction $startPaidTerraforming,
        private PerformAdvanceShippingAction $performAdvanceShipping,
        private PerformAdvanceTerraformingAction $performAdvanceTerraforming,
        private SendScholarAction $sendScholar,
        private MakeInnovationAction $makeInnovation,
        private PassAction $pass,
        private ChooseRoundBonusAction $chooseRoundBonus,
        private PerformInnovationAction $performInnovation,
        private PerformPalaceAction $performPalace,
        private PerformFactionAction $performFaction,
        private PerformCompetencyAction $performCompetency,
        private PerformRoundBonusAction $performRoundBonus,
        private ExchangeResourcesAction $exchangeResources,
        private SacrificePowerAction $sacrificePower,
        private ConfirmAnnexPlacementAction $confirmAnnexPlacement,
        private ResolvePowerOfferAction $resolvePowerOffer,
        private ChooseTownAction $chooseTown,
        private ResolveWorkshopAfterTerraformingAction $resolveWorkshopAfterTerraforming,
        private ResolvePalaceWaterTownAction $resolvePalaceWaterTown,
        private ChoosePalaceAction $choosePalace,
        private ChooseCompetencyAction $chooseCompetency,
        private PlaceNeutralInnovationBuildingAction $placeNeutralBuilding,
        private StageBridgeAction $stageBridge,
        private ConfirmBridgeAction $confirmBridge,
        private SkipBridgeAction $skipBridge,
        private SpendStartingSpadeAction $spendSpade,
        private FinishStartingSpadeAction $finishSpade,
        private PlacePalaceGuildAction $placePalaceGuild,
        private ConfirmPalaceGuildAction $confirmPalaceGuild,
        private DistributeRewardsAction $distributeRewards,
    ) {
    }

    public function execute(Game $game, GamePlayer $player, GameActionOption $option): Game
    {
        return match (true) {
            $option instanceof PlanningBundleOptionData => $this->choosePlanningBundle->execute(
                $game,
                $player,
                $option->homeland,
            ),
            $option instanceof StartingBuildingOptionData => $this->finishStartingBuildingTurn->execute(
                $game,
                $player,
                $option->hexId,
            ),
            $option instanceof BookActionOptionData => $this->performBookAction->execute(
                $game,
                $player,
                $option->action,
                $option->payment->counts(),
                $option->discipline,
                $option->hexId,
            ),
            $option instanceof PowerOfferOptionData => $this->resolvePowerOffer->execute(
                $game,
                $player,
                $option->accept,
            ),
            $option instanceof ChoosePalaceOptionData => $this->choosePalace->execute(
                $game,
                $player,
                $option->palace,
            ),
            $option instanceof PowerActionOptionData => $this->performPowerAction->execute(
                $game,
                $player,
                $option->action,
                $option->sacrificeAmount,
            ),
            $option instanceof BuildWorkshopOptionData => $this->buildWorkshop->execute(
                $game,
                $player,
                $option->hexId,
            ),
            $option instanceof ChooseTownOptionData => $this->chooseTown->execute(
                $game,
                $player,
                $option->townTile,
            ),
            $option instanceof UpgradeBuildingOptionData => $this->upgradeBuilding->execute(
                $game,
                $player,
                $option->hexId,
                $option->target,
            ),
            $option instanceof PaidTerraformingOptionData => $this->performPaidTerraforming($game, $player, $option),
            $option instanceof SpendSpadesOptionData => $this->performSpadeSpending($game, $player, $option),
            $option instanceof DevelopmentAdvancementOptionData => $this->performDevelopmentAdvancement($game, $player, $option),
            $option instanceof SendScholarOptionData => $this->sendScholar->execute(
                $game,
                $player,
                $option->discipline,
                $option->place,
            ),
            $option instanceof MakeInnovationOptionData => $this->makeInnovation->execute(
                $game,
                $player,
                $option->innovation,
                $option->payment->counts(),
            ),
            $option instanceof PassOptionData => $this->pass->execute($game, $player, $option->knowledgeDisciplines),
            $option instanceof ChooseRoundBonusOptionData => $this->chooseRoundBonus->execute($game, $player, $option->roundBonus),
            $option instanceof InnovationSpecialActionOptionData => $this->performInnovation->execute($game, $player, $option->innovation),
            $option instanceof PalaceActionOptionData => $this->performPalace->execute(
                $game,
                $player,
                $option->discipline,
                $option->knowledgeDisciplines,
                $option->hexId,
            ),
            $option instanceof PlayerSpecialActionOptionData => $this->performPlayerSpecialAction($game, $player, $option),
            $option instanceof ResourceExchangeOptionData => $this->exchangeResources->execute(
                $game,
                $player,
                $this->resourceExchanges($option),
            ),
            $option instanceof SacrificePowerOptionData => $this->sacrificePower->execute($game, $player, $option->amount),
            $option instanceof PlaceAnnexOptionData => $this->confirmAnnexPlacement->execute($game, $player, $option->hexId),
            $option instanceof WorkshopAfterTerraformingOptionData => $this->resolveWorkshopAfterTerraforming->execute(
                $game,
                $player,
                $option->build,
                $option->hexId,
            ),
            $option instanceof PalaceWaterTownOptionData => $this->resolvePalaceWaterTown->execute(
                $game,
                $player,
                $option->accept,
                $option->waterHexId,
            ),
            $option instanceof ChooseCompetencyOptionData => $this->chooseCompetency->execute($game, $player, $option->competency),
            $option instanceof PlaceNeutralBuildingOptionData => $this->placeNeutralBuilding->execute($game, $player, $option->hexId),
            $option instanceof PlaceBridgeOptionData => $this->performBridgePlacement($game, $player, $option),
            $option instanceof SkipBridgeOptionData => $this->skipBridge->execute($game, $player),
            $option instanceof PlacePalaceGuildOptionData => $this->performPalaceGuildPlacement($game, $player, $option),
            $option instanceof RewardDistributionOptionData => $this->distributeRewards->execute(
                $game,
                $player,
                $option->bookCounts,
                $option->knowledgeCounts,
            ),
            default => throw new DomainException("Исполнение действия {$option->type()->value} ещё не поддерживается."),
        };
    }

    private function performDevelopmentAdvancement(
        Game $game,
        GamePlayer $player,
        DevelopmentAdvancementOptionData $option,
    ): Game {
        return match ($option->action) {
            GameActionType::AdvanceShipping => $this->performAdvanceShipping->execute($game, $player),
            GameActionType::AdvanceTerraforming => $this->performAdvanceTerraforming->execute($game, $player),
            default => throw new DomainException("Исполнение действия {$option->action->value} ещё не поддерживается."),
        };
    }

    private function performPlayerSpecialAction(
        Game $game,
        GamePlayer $player,
        PlayerSpecialActionOptionData $option,
    ): Game {
        return match ($option->actionType) {
            GameActionOptionType::UseFactionAction => $this->performFaction->execute($game, $player, $option->discipline),
            GameActionOptionType::UseCompetencyAction => $this->performCompetency->execute($game, $player),
            GameActionOptionType::UseRoundBonusAction => $this->performRoundBonus->execute($game, $player, $option->discipline),
            default => throw new DomainException("Исполнение действия {$option->actionType->value} ещё не поддерживается."),
        };
    }

    private function performPaidTerraforming(Game $game, GamePlayer $player, PaidTerraformingOptionData $option): Game
    {
        $game = $this->startPaidTerraforming->execute(
            $game,
            $player,
            $option->hexId,
            $option->useAvailable,
            $option->useTunnel,
            $option->useFlight,
        );

        return $this->performSpadeSpending(
            $game,
            $player,
            new SpendSpadesOptionData($option->hexId, $option->spadeCount),
        );
    }

    private function performBridgePlacement(Game $game, GamePlayer $player, PlaceBridgeOptionData $option): Game
    {
        $interaction = $game->state->pendingInteraction;
        $isStaged = ($interaction?->context['selectedFromHexId'] ?? null) === $option->fromHexId
            && ($interaction?->context['selectedToHexId'] ?? null) === $option->toHexId;

        if (! $isStaged) {
            $game = $this->stageBridge->execute($game, $player, $option->fromHexId, $option->toHexId);
        }

        return $this->confirmBridge->execute($game, $player);
    }

    private function performSpadeSpending(Game $game, GamePlayer $player, SpendSpadesOptionData $option): Game
    {
        $selectedHexId = $game->state->pendingInteraction?->context['selectedHexId'] ?? null;

        if ($selectedHexId !== $option->hexId) {
            $game = $this->spendSpade->execute($game, $player, $option->hexId);
        }

        return $this->finishSpade->execute($game, $player);
    }

    private function performPalaceGuildPlacement(
        Game $game,
        GamePlayer $player,
        PlacePalaceGuildOptionData $option,
    ): Game {
        $selectedHexId = $game->state->pendingInteraction?->context['selectedHexId'] ?? null;

        if ($selectedHexId !== $option->hexId) {
            $game = $this->placePalaceGuild->execute($game, $player, $option->hexId);
        }

        return $this->confirmPalaceGuild->execute($game, $player);
    }

    /** @return array<string, int|array<string, int>> */
    private function resourceExchanges(ResourceExchangeOptionData $option): array
    {
        $bookCounts = array_fill_keys(array_column(KnowledgeDiscipline::cases(), 'value'), 0);
        $exchanges = array_fill_keys(array_column(ResourceExchange::cases(), 'value'), 0);
        $exchanges[ResourceExchange::PowerToBook->value] = $bookCounts;
        $exchanges[ResourceExchange::BookToCoin->value] = $bookCounts;

        if ($option->discipline instanceof KnowledgeDiscipline) {
            $disciplineCounts = $bookCounts;
            $disciplineCounts[$option->discipline->value] = 1;
            $exchanges[$option->exchange->value] = $disciplineCounts;
        } else {
            $exchanges[$option->exchange->value] = 1;
        }

        return $exchanges;
    }
}
