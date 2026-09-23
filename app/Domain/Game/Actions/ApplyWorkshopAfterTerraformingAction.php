<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\BuildingStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Data\WorkshopAfterTerraformingResultData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\PendingInteractionType;
use Illuminate\Validation\ValidationException;

final class ApplyWorkshopAfterTerraformingAction
{
    public function __construct(
        private CreateBuildingFollowUpInteractionAction $createBuildingFollowUpInteraction,
        private CreatePowerOffersAfterBuildingAction $createPowerOffersAfterBuilding,
        private ApplyBuildingBonusesAction $applyBuildingBonuses,
        private StartFelineTownBonusAction $startFelineTownBonus,
    ) {
    }

    public function execute(
        GameStateData $state,
        GamePlayerStateData $player,
        bool $build,
        ?string $hexId,
    ): WorkshopAfterTerraformingResultData {
        $interaction = $state->pendingInteraction;

        if ($interaction?->type !== PendingInteractionType::BuildWorkshopAfterTerraforming
            || $interaction->playerId !== $player->playerId
            || ($build && (! is_string($hexId) || ! in_array($hexId, $interaction->optionIds, true)))) {
            throw ValidationException::withMessages(['game' => 'Сейчас нельзя подтвердить строительство дома.']);
        }

        $bonuses = ['victoryPoints' => 0, 'coins' => 0, 'sources' => []];
        $felineBonusPending = ($interaction->context['felineBonusPending'] ?? false) === true;
        $toolCost = max(0, (int) ($interaction->context['toolCost'] ?? 1));
        $coinCost = max(0, (int) ($interaction->context['coinCost'] ?? 2));

        if ($build) {
            $hex = collect($state->board->hexes)->firstWhere('id', $hexId);
            $workshopsOnMap = count(array_filter(
                $state->board->hexes,
                static fn (BoardHexStateData $boardHex): bool => $boardHex->building?->ownerPlayerId === $player->playerId
                    && $boardHex->building->type === BuildingType::Workshop
                    && ! $boardHex->building->isNeutral,
            ));

            if (! $hex instanceof BoardHexStateData
                || $hex->building !== null
                || $hex->terrain !== $player->homeland
                || $player->resources->tools < $toolCost
                || $player->resources->coins < $coinCost
                || $workshopsOnMap >= BuildingType::Workshop->supplyLimit()) {
                throw ValidationException::withMessages(['game' => 'Дом нельзя построить на выбранной клетке.']);
            }

            $player->resources->tools -= $toolCost;
            $player->resources->coins -= $coinCost;
            $hex->building = new BuildingStateData(BuildingType::Workshop, $player->playerId);
            $bonuses = $this->applyBuildingBonuses->execute($state, $player, $hex, BuildingType::Workshop);
        }

        $state->pendingInteraction = null;
        $state->round->hasTakenMainAction = true;

        if ($felineBonusPending) {
            $nextActiveUserId = $build
                ? $this->createPowerOffersAfterBuilding->execute($state, $player->playerId, (string) $hexId)
                : null;
            $powerOffer = $this->powerOffer($state);

            if ($nextActiveUserId !== null && $powerOffer !== null) {
                $powerOffer->context['felineBonusPending'] = true;
            } else {
                $this->startFelineTownBonus->execute($state, $player, [
                    ...($build ? ['continueBuildingAfterPowerHexId' => (string) $hexId] : []),
                ]);
                $nextActiveUserId = $player->userId;
            }
        } else {
            $nextActiveUserId = $build
                ? $this->createBuildingFollowUpInteraction->execute(
                    $state,
                    $player,
                    (string) $hexId,
                    BuildingType::Workshop,
                )
                : null;
        }

        return new WorkshopAfterTerraformingResultData(
            $nextActiveUserId ?? $player->userId,
            $bonuses['victoryPoints'],
            $bonuses['coins'],
            $bonuses['sources'],
            $felineBonusPending,
            $toolCost,
            $coinCost,
        );
    }

    private function powerOffer(GameStateData $state): ?PendingInteractionData
    {
        return $state->pendingInteraction?->type === PendingInteractionType::PowerOffer
            ? $state->pendingInteraction
            : null;
    }
}
