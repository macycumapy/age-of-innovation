<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Data\SpendSpadesOptionData;
use App\Domain\Game\Data\SpendSpadesResultData;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\RoundScoringGoal;
use App\Domain\Game\Enums\RoundScoringTile;
use App\Domain\Game\Enums\TerrainType;
use App\Domain\Game\Services\SpendSpadesOptionFinder;
use Illuminate\Validation\ValidationException;

final class ApplySpendSpadesAction
{
    public function __construct(
        private SpendSpadesOptionFinder $optionFinder,
        private FindEligibleTerraformHexesAction $findEligibleTerraformHexes,
        private FindEligibleMoleTunnelHexesAction $findEligibleMoleTunnelHexes,
        private FindEligiblePalaceFlightHexesAction $findEligiblePalaceFlightHexes,
        private OfferWorkshopAfterTerraformingAction $offerWorkshopAfterTerraforming,
        private StartFelineTownBonusAction $startFelineTownBonus,
        private StartLizardTownBonusAction $startLizardTownBonus,
    ) {
    }

    public function execute(
        GameStateData $state,
        GamePlayerStateData $player,
        SpendSpadesOptionData $option,
        bool $requireActionPhase = true,
    ): SpendSpadesResultData {
        $matchingOption = collect($this->optionFinder->execute($state, $player, $requireActionPhase))->first(
            static fn (SpendSpadesOptionData $candidate): bool => $candidate->hexId === $option->hexId
                && $candidate->spadeCount === $option->spadeCount,
        );
        if (! $matchingOption instanceof SpendSpadesOptionData) {
            throw ValidationException::withMessages(['hex_id' => 'Эта клетка недоступна для преобразования.']);
        }

        $interaction = $state->pendingInteraction;
        if (! $interaction instanceof PendingInteractionData) {
            throw ValidationException::withMessages(['game' => 'Нет доступных лопат.']);
        }

        [$terrainBefore, $terrainAfter] = $this->stageSelection($state, $interaction, $matchingOption);
        $spentSpades = $matchingOption->spadeCount;
        $player->unassignedSpades -= $spentSpades;
        $bonusCoins = $player->faction === Faction::Goblins ? $spentSpades * 2 : 0;
        $player->resources->coins += $bonusCoins;
        $remainingSpades = max(0, (int) ($interaction->context['remainingSpades'] ?? 1) - $spentSpades);
        $roundScoringTile = RoundScoringTile::tryFrom((string) $state->round->scoringTileId);
        $victoryPoints = $roundScoringTile?->goal() === RoundScoringGoal::Spade ? $spentSpades * 2 : 0;
        $player->victoryPoints += $victoryPoints;
        $buildableHexIds = array_values(array_filter(
            (array) ($interaction->context['buildableHexIds'] ?? []),
            'is_string',
        ));

        if ($terrainAfter === $player->homeland) {
            $buildableHexIds[] = $matchingOption->hexId;
        }
        if ((int) ($interaction->context['tunnelTools'] ?? 0) > 0) {
            $interaction->context['tunnelUsed'] = true;
        }
        if ((int) ($interaction->context['flightScholarCost'] ?? 0) > 0) {
            $interaction->context['flightUsed'] = true;
        }

        $this->clearSelectionContext($interaction);
        $interaction->context['remainingSpades'] = $remainingSpades;
        $interaction->context['buildableHexIds'] = array_values(array_unique($buildableHexIds));
        $buildOffered = false;

        if ($remainingSpades > 0 && $this->continueSpending($state, $player, $interaction)) {
            $state->pendingInteraction = $interaction;
        } else {
            $buildOffered = $this->continueAfterSpades($state, $player, $buildableHexIds, $interaction);
        }

        return new SpendSpadesResultData(
            $player->userId,
            $matchingOption->hexId,
            $terrainBefore->value,
            $terrainAfter->value,
            $spentSpades,
            $remainingSpades,
            array_values(array_unique($buildableHexIds)),
            $buildOffered,
            $bonusCoins,
            $victoryPoints,
        );
    }

    /** @return array{TerrainType, TerrainType} */
    private function stageSelection(
        GameStateData $state,
        PendingInteractionData $interaction,
        SpendSpadesOptionData $option,
    ): array {
        $selectedHexId = $interaction->context['selectedHexId'] ?? null;
        if (is_string($selectedHexId)) {
            $terrainBefore = TerrainType::tryFrom((string) ($interaction->context['terrainBefore'] ?? ''));
            $terrainAfter = TerrainType::tryFrom((string) ($interaction->context['terrainAfter'] ?? ''));
            if ($selectedHexId !== $option->hexId || $terrainBefore === null || $terrainAfter === null) {
                throw ValidationException::withMessages(['hex_id' => 'Некорректное преобразование клетки.']);
            }

            return [$terrainBefore, $terrainAfter];
        }

        $targetTerrain = TerrainType::tryFrom((string) ($interaction->context['targetTerrain'] ?? ''));
        $hex = collect($state->board->hexes)->firstWhere('id', $option->hexId);
        if ($targetTerrain === null || ! $hex instanceof BoardHexStateData) {
            throw ValidationException::withMessages(['hex_id' => 'Клетка карты не найдена.']);
        }

        $terrainBefore = $hex->terrain;
        $terrainAfter = $terrainBefore;
        for ($step = 0; $step < $option->spadeCount && $terrainAfter !== $targetTerrain; $step++) {
            $terrainAfter = $terrainAfter->stepTowards($targetTerrain);
        }
        $hex->terrain = $terrainAfter;

        return [$terrainBefore, $terrainAfter];
    }

    private function continueSpending(
        GameStateData $state,
        GamePlayerStateData $player,
        PendingInteractionData $interaction,
    ): bool {
        $targetTerrain = TerrainType::from((string) $interaction->context['targetTerrain']);
        $interaction->optionIds = $this->findEligibleTerraformHexes->execute($state, $player, $targetTerrain);
        if (! ($interaction->context['tunnelUsed'] ?? false)) {
            $interaction->optionIds = array_values(array_unique([
                ...$interaction->optionIds,
                ...$this->findEligibleMoleTunnelHexes->execute($state, $player),
            ]));
        }
        if (! ($interaction->context['flightUsed'] ?? false)) {
            $interaction->optionIds = array_values(array_unique([
                ...$interaction->optionIds,
                ...$this->findEligiblePalaceFlightHexes->execute($state, $player),
            ]));
        }

        return $interaction->optionIds !== [];
    }

    /** @param list<string> $buildableHexIds */
    private function continueAfterSpades(
        GameStateData $state,
        GamePlayerStateData $player,
        array $buildableHexIds,
        PendingInteractionData $interaction,
    ): bool {
        if (($interaction->context['lizardBonusPending'] ?? false) === true) {
            $this->startLizardTownBonus->execute($state, $player);

            return false;
        }
        if (($interaction->context['lizardFreeWorkshop'] ?? false) === true) {
            return $this->offerWorkshopAfterTerraforming->execute(
                $state,
                $player,
                $buildableHexIds,
                ['toolCost' => 0, 'coinCost' => 0, 'lizardFreeWorkshop' => true],
            );
        }
        if (($interaction->context['felineBonusPending'] ?? false) === true) {
            $buildOffered = $this->offerWorkshopAfterTerraforming->execute(
                $state,
                $player,
                $buildableHexIds,
                ['felineBonusPending' => true],
            );
            if ($buildOffered) {
                return true;
            }

            $this->startFelineTownBonus->execute($state, $player);

            return false;
        }

        return $this->offerWorkshopAfterTerraforming->execute($state, $player, $buildableHexIds);
    }

    private function clearSelectionContext(PendingInteractionData $interaction): void
    {
        unset(
            $interaction->context['selectedHexId'],
            $interaction->context['terrainBefore'],
            $interaction->context['terrainAfter'],
            $interaction->context['paidTools'],
            $interaction->context['paidSpadeCount'],
            $interaction->context['spadesToSpend'],
            $interaction->context['spentSpades'],
            $interaction->context['tunnelTools'],
            $interaction->context['tunnelVictoryPoints'],
            $interaction->context['flightScholarCost'],
            $interaction->context['flightVictoryPoints'],
            $interaction->context['optionIdsBeforeSelection'],
        );
    }
}
