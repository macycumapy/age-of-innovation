<?php

declare(strict_types=1);

namespace App\Domain\Automation\Evaluation\Services;

use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\PlayerAbilities\Enums\PalaceAbility;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;

final class PalaceAbilityEvaluator
{
    private const int VICTORY_POINT_WEIGHT = 10;

    private const int TOOL_WEIGHT = 30;

    private const int COIN_WEIGHT = 10;

    private const int BOOK_WEIGHT = 25;

    private const int KNOWLEDGE_STEP_WEIGHT = 10;

    private const float FUTURE_ACTION_DISCOUNT = 0.5;

    public function execute(GameStateData $state, GamePlayerStateData $player): int
    {
        $palace = PalaceAbility::tryFrom((string) $player->palaceId);
        if ($palace === null || $state->round->phase === GamePhase::Finished) {
            return 0;
        }

        $currentRoundAvailable = $state->round->phase !== GamePhase::Setup
            && $state->round->phase !== GamePhase::ScienceBonus
            && ! in_array($player->playerId, $state->passedPlayerIds, true);
        $hasUnusedSpecialAction = $currentRoundAvailable && $palace->hasSpecialAction()
            && ! in_array($palace->specialActionId(), $player->usedSpecialActionIds, true);
        $workshopCount = $this->buildingCount($state, $player->playerId, BuildingType::Workshop);
        $guildCount = $this->buildingCount($state, $player->playerId, BuildingType::Guild);
        $schoolCount = $this->buildingCount($state, $player->playerId, BuildingType::School);
        $remainingRounds = $state->round->phase === GamePhase::Setup ? 6 : max(0, 6 - $state->round->number);
        $expectedActionUses = (int) $hasUnusedSpecialAction + $remainingRounds * self::FUTURE_ACTION_DISCOUNT;
        $remainingPasses = $remainingRounds + (int) $currentRoundAvailable;

        return match ($palace) {
            PalaceAbility::Palace01 => (int) round($expectedActionUses * 2 * self::TOOL_WEIGHT),
            PalaceAbility::Palace02 => $this->hasTerraformingTarget($state, $player) ? (int) round($expectedActionUses * 60) : 0,
            PalaceAbility::Palace03 => (int) round(min($schoolCount, $expectedActionUses) * 100),
            PalaceAbility::Palace04 => (int) round(min($workshopCount, $expectedActionUses) * 90),
            PalaceAbility::Palace05,
            PalaceAbility::Palace10,
            PalaceAbility::Palace11,
            PalaceAbility::Palace15,
            PalaceAbility::Palace16,
            PalaceAbility::Palace17 => 0,
            PalaceAbility::Palace06 => (int) round($expectedActionUses * 2 * self::KNOWLEDGE_STEP_WEIGHT),
            PalaceAbility::Palace07 => $schoolCount * 3 * self::VICTORY_POINT_WEIGHT * $remainingPasses,
            PalaceAbility::Palace08 => 50,
            PalaceAbility::Palace09 => 40 + min($player->resources->scholars, $remainingRounds) * 20,
            PalaceAbility::Palace12 => min(
                max(0, BuildingType::Workshop->supplyLimit() - $workshopCount),
                $remainingRounds * 2,
            ) * 2 * self::VICTORY_POINT_WEIGHT,
            PalaceAbility::Palace13 => (int) round($expectedActionUses * (3 * self::COIN_WEIGHT + self::BOOK_WEIGHT))
                + min(
                    max(0, BuildingType::Guild->supplyLimit() - $guildCount),
                    $workshopCount,
                ) * 3 * self::VICTORY_POINT_WEIGHT,
            PalaceAbility::Palace14 => 60,
        };
    }

    private function buildingCount(GameStateData $state, int $playerId, BuildingType $buildingType): int
    {
        return count(array_filter(
            $state->board->hexes,
            static fn (BoardHexStateData $hex): bool => $hex->building?->ownerPlayerId === $playerId
                && $hex->building->type === $buildingType
                && ! $hex->building->isNeutral,
        ));
    }

    private function hasTerraformingTarget(GameStateData $state, GamePlayerStateData $player): bool
    {
        return collect($state->board->hexes)->contains(
            static fn (BoardHexStateData $hex): bool => $hex->building === null
                && $hex->terrain->isHomeland()
                && $hex->terrain !== $player->homeland,
        );
    }
}
