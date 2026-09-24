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
use App\Domain\Game\Data\PlayerSpecialActionOptionData;
use App\Domain\Game\Data\PowerActionOptionData;
use App\Domain\Game\Data\PowerOfferOptionData;
use App\Domain\Game\Data\ResourceExchangeOptionData;
use App\Domain\Game\Data\RewardDistributionOptionData;
use App\Domain\Game\Data\SacrificePowerOptionData;
use App\Domain\Game\Data\SendScholarOptionData;
use App\Domain\Game\Data\SpendSpadesOptionData;
use App\Domain\Game\Data\UpgradeBuildingOptionData;
use App\Domain\Game\Data\WorkshopAfterTerraformingOptionData;
use App\Domain\Game\Enums\GameActionOptionType;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Enums\ResourceExchange;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

final class PerformGameActionOptionAction
{
    public function __construct(
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
        private SpendStartingSpadeAction $spendSpade,
        private FinishStartingSpadeAction $finishSpade,
        private PlacePalaceGuildAction $placePalaceGuild,
        private ConfirmPalaceGuildAction $confirmPalaceGuild,
        private DistributeRewardsAction $distributeRewards,
    ) {
    }

    public function execute(Game $game, GamePlayer $player, GameActionOption $option): Game
    {
        if ($option instanceof BookActionOptionData) {
            return $this->performBookAction->execute(
                $game,
                $player,
                $option->action,
                $option->payment->counts(),
                $option->discipline,
                $option->hexId,
            );
        }

        if ($option instanceof PowerOfferOptionData) {
            return $this->resolvePowerOffer->execute($game, $player, $option->accept);
        }

        if ($option instanceof ChoosePalaceOptionData) {
            return $this->choosePalace->execute($game, $player, $option->palace);
        }

        if ($option instanceof PowerActionOptionData) {
            return $this->performPowerAction->execute(
                $game,
                $player,
                $option->action,
                $option->sacrificeAmount,
            );
        }

        if ($option instanceof BuildWorkshopOptionData) {
            return $this->buildWorkshop->execute($game, $player, $option->hexId);
        }

        $user = $player->user()->firstOrFail();

        return DB::transaction(fn (): Game => match (true) {
            $option instanceof UpgradeBuildingOptionData => $this->upgradeBuilding->execute(
                $game,
                $user,
                $option->hexId,
                $option->target,
            ),
            $option instanceof PaidTerraformingOptionData => $this->performPaidTerraforming($game, $user, $option),
            $option instanceof DevelopmentAdvancementOptionData => $this->performDevelopmentAdvancement($game, $user, $option),
            $option instanceof SendScholarOptionData => $this->sendScholar->execute(
                $game,
                $user,
                $option->discipline,
                $option->place,
            ),
            $option instanceof MakeInnovationOptionData => $this->makeInnovation->execute(
                $game,
                $user,
                $option->innovation,
                $option->payment->counts(),
            ),
            $option instanceof PassOptionData => $this->pass->execute($game, $user, $option->knowledgeDisciplines),
            $option instanceof ChooseRoundBonusOptionData => $this->chooseRoundBonus->execute($game, $user, $option->roundBonus),
            $option instanceof InnovationSpecialActionOptionData => $this->performInnovation->execute($game, $user, $option->innovation),
            $option instanceof PalaceActionOptionData => $this->performPalace->execute(
                $game,
                $user,
                $option->discipline,
                $option->knowledgeDisciplines,
                $option->hexId,
            ),
            $option instanceof PlayerSpecialActionOptionData => $this->performPlayerSpecialAction($game, $user, $option),
            $option instanceof ResourceExchangeOptionData => $this->exchangeResources->execute(
                $game,
                $user,
                $this->resourceExchanges($option),
            ),
            $option instanceof SacrificePowerOptionData => $this->sacrificePower->execute($game, $user, $option->amount),
            $option instanceof PlaceAnnexOptionData => $this->confirmAnnexPlacement->execute($game, $user, $option->hexId),
            $option instanceof ChooseTownOptionData => $this->chooseTown->execute($game, $user, $option->townTile),
            $option instanceof WorkshopAfterTerraformingOptionData => $this->resolveWorkshopAfterTerraforming->execute(
                $game,
                $user,
                $option->build,
                $option->hexId,
            ),
            $option instanceof PalaceWaterTownOptionData => $this->resolvePalaceWaterTown->execute(
                $game,
                $user,
                $option->accept,
                $option->waterHexId,
            ),
            $option instanceof ChooseCompetencyOptionData => $this->chooseCompetency->execute($game, $user, $option->competency),
            $option instanceof PlaceNeutralBuildingOptionData => $this->placeNeutralBuilding->execute($game, $user, $option->hexId),
            $option instanceof PlaceBridgeOptionData => $this->performBridgePlacement($game, $user, $option),
            $option instanceof SpendSpadesOptionData => $this->performSpadeSpending($game, $user, $option),
            $option instanceof PlacePalaceGuildOptionData => $this->performPalaceGuildPlacement($game, $user, $option),
            $option instanceof RewardDistributionOptionData => $this->distributeRewards->execute(
                $game,
                $user,
                $option->bookCounts,
                $option->knowledgeCounts,
            ),
            default => throw new DomainException("Исполнение действия {$option->type()->value} ещё не поддерживается."),
        });
    }

    private function performDevelopmentAdvancement(
        Game $game,
        User $user,
        DevelopmentAdvancementOptionData $option,
    ): Game {
        return match ($option->action) {
            GameActionType::AdvanceShipping => $this->performAdvanceShipping->execute($game, $user),
            GameActionType::AdvanceTerraforming => $this->performAdvanceTerraforming->execute($game, $user),
            default => throw new DomainException("Исполнение действия {$option->action->value} ещё не поддерживается."),
        };
    }

    private function performPlayerSpecialAction(
        Game $game,
        User $user,
        PlayerSpecialActionOptionData $option,
    ): Game {
        return match ($option->actionType) {
            GameActionOptionType::UseFactionAction => $this->performFaction->execute($game, $user, $option->discipline),
            GameActionOptionType::UseCompetencyAction => $this->performCompetency->execute($game, $user),
            GameActionOptionType::UseRoundBonusAction => $this->performRoundBonus->execute($game, $user, $option->discipline),
            default => throw new DomainException("Исполнение действия {$option->actionType->value} ещё не поддерживается."),
        };
    }

    private function performPaidTerraforming(Game $game, User $user, PaidTerraformingOptionData $option): Game
    {
        $game = $this->startPaidTerraforming->execute(
            $game,
            $user,
            $option->hexId,
            $option->useAvailable,
            $option->useTunnel,
            $option->useFlight,
        );

        return $this->performSpadeSpending(
            $game,
            $user,
            new SpendSpadesOptionData($option->hexId, $option->spadeCount),
        );
    }

    private function performBridgePlacement(Game $game, User $user, PlaceBridgeOptionData $option): Game
    {
        $interaction = $game->state->pendingInteraction;
        $isStaged = ($interaction?->context['selectedFromHexId'] ?? null) === $option->fromHexId
            && ($interaction?->context['selectedToHexId'] ?? null) === $option->toHexId;

        if (! $isStaged) {
            $game = $this->stageBridge->execute($game, $user, $option->fromHexId, $option->toHexId);
        }

        return $this->confirmBridge->execute($game, $user);
    }

    private function performSpadeSpending(Game $game, User $user, SpendSpadesOptionData $option): Game
    {
        $selectedHexId = $game->state->pendingInteraction?->context['selectedHexId'] ?? null;

        if ($selectedHexId !== $option->hexId) {
            $game = $this->spendSpade->execute($game, $user, $option->hexId);
        }

        return $this->finishSpade->execute($game, $user);
    }

    private function performPalaceGuildPlacement(
        Game $game,
        User $user,
        PlacePalaceGuildOptionData $option,
    ): Game {
        $selectedHexId = $game->state->pendingInteraction?->context['selectedHexId'] ?? null;

        if ($selectedHexId !== $option->hexId) {
            $game = $this->placePalaceGuild->execute($game, $user, $option->hexId);
        }

        return $this->confirmPalaceGuild->execute($game, $user);
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
