<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Data\BoardStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\Competency;
use App\Domain\Game\Enums\Innovation;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Enums\PalaceAbility;
use App\Domain\Game\Enums\RoundBonus;
use App\Domain\Game\Enums\TerrainType;

final class PlayerIncomeCalculator
{
    /** @return array{tools: int, coins: int, scholars: int, power: int, books: int, knowledgeSteps: int} */
    public static function calculate(GamePlayerStateData $player, BoardStateData $board): array
    {
        $income = [
            'tools' => 1,
            'coins' => $player->homeland === TerrainType::Mountain ? 2 : 0,
            'scholars' => 0,
            'power' => 0,
            'books' => 0,
            'knowledgeSteps' => 0,
            'victoryPoints' => 0,
        ];
        $buildingCounts = self::buildingCounts($player->playerId, $board);

        $workshopCount = $buildingCounts[BuildingType::Workshop->value];
        $income['tools'] += $workshopCount - ($workshopCount >= 5 ? 1 : 0);
        $income['coins'] += $buildingCounts[BuildingType::Guild->value] * 2;
        $income['power'] += match ($buildingCounts[BuildingType::Guild->value]) {
            0 => 0,
            1 => 1,
            2 => 2,
            3 => 4,
            default => 6,
        };
        $income['scholars'] += $buildingCounts[BuildingType::School->value]
            + $buildingCounts[BuildingType::University->value];
        $income['coins'] += $buildingCounts[BuildingType::Tower->value] * 2;
        $income['power'] += $buildingCounts[BuildingType::Tower->value] * 2;

        self::addRoundBonusIncome($income, $player->roundBonus);

        foreach ($player->competencyIds as $competencyId) {
            self::addCompetencyIncome($income, Competency::from($competencyId));
        }

        foreach ($player->inventionIds as $innovationId) {
            self::addInnovationIncome($income, Innovation::from($innovationId));
        }

        if ($player->palaceId !== null) {
            self::addPalaceIncome($income, PalaceAbility::from($player->palaceId));
        }

        foreach (KnowledgeDiscipline::cases() as $discipline) {
            if ($player->knowledge->{$discipline->value} < 9) {
                continue;
            }

            foreach ($discipline->highLevelIncome() as $resource => $amount) {
                $income[$resource] += $amount;
            }
        }

        return $income;
    }

    /**
     * @return array<string, int>
     */
    private static function buildingCounts(int $playerId, BoardStateData $board): array
    {
        $counts = array_fill_keys(array_map(
            static fn (BuildingType $buildingType): string => $buildingType->value,
            BuildingType::cases(),
        ), 0);

        foreach ($board->hexes as $hex) {
            if ($hex->building?->ownerPlayerId !== $playerId) {
                continue;
            }

            if (! $hex->building->isNeutral || $hex->building->type === BuildingType::Tower) {
                $counts[$hex->building->type->value]++;
            }
        }

        return $counts;
    }

    /** @param array{tools: int, coins: int, scholars: int, power: int, books: int, knowledgeSteps: int} $income */
    private static function addRoundBonusIncome(array &$income, RoundBonus $roundBonus): void
    {
        match ($roundBonus) {
            RoundBonus::SendScholar => $income['scholars']++,
            RoundBonus::BuildGuild => $income['power'] += 3,
            RoundBonus::PassPalaceUniversity => $income['tools']++,
            RoundBonus::Spade, RoundBonus::Bridge => $income['books']++,
            RoundBonus::Knowledge => $income['tools'] += 2,
            RoundBonus::PassSchool => $income['coins'] += 4,
            RoundBonus::PowerCoins => self::addPowerCoins($income, 4, 2),
            RoundBonus::Coins => $income['coins'] += 6,
            RoundBonus::RiverWorkshop => null,
        };
    }

    /** @param array{tools: int, coins: int, scholars: int, power: int, books: int, knowledgeSteps: int} $income */
    private static function addCompetencyIncome(array &$income, Competency $competency): void
    {
        match ($competency) {
            Competency::Competency01 => self::addToolsAndKnowledge($income),
            Competency::Competency02 => $income['coins'] += 2,
            Competency::Competency03 => self::addBooksAndPower($income),
            default => null,
        };
    }

    /** @param array{tools: int, coins: int, scholars: int, power: int, books: int, knowledgeSteps: int} $income */
    private static function addInnovationIncome(array &$income, Innovation $innovation): void
    {
        match ($innovation) {
            Innovation::Workshop => $income['tools'] += 3,
            Innovation::Guild => $income['coins'] += 5,
            Innovation::Palace => $income['power'] += 4,
            default => null,
        };
    }

    /** @param array{tools: int, coins: int, scholars: int, power: int, books: int, knowledgeSteps: int} $income */
    private static function addPalaceIncome(array &$income, PalaceAbility $palace): void
    {
        match ($palace) {
            PalaceAbility::Palace01 => $income['power'] += 5,
            PalaceAbility::Palace03, PalaceAbility::Palace04, PalaceAbility::Palace17 => $income['power'] += 2,
            PalaceAbility::Palace05, PalaceAbility::Palace07 => $income['power'] += 4,
            PalaceAbility::Palace06, PalaceAbility::Palace16 => self::addPowerAndBook($income),
            PalaceAbility::Palace08 => self::addPalaceEightIncome($income),
            PalaceAbility::Palace09 => $income['scholars']++,
            PalaceAbility::Palace10 => $income['coins'] += 6,
            PalaceAbility::Palace11 => $income['tools']++,
            PalaceAbility::Palace12 => $income['power'] += 8,
            PalaceAbility::Palace14, PalaceAbility::Palace15 => $income['power'] += 6,
            PalaceAbility::Palace02, PalaceAbility::Palace13 => null,
        };
    }

    /** @param array{tools: int, coins: int, scholars: int, power: int, books: int, knowledgeSteps: int} $income */
    private static function addPowerCoins(array &$income, int $power, int $coins): void
    {
        $income['power'] += $power;
        $income['coins'] += $coins;
    }

    /** @param array{tools: int, coins: int, scholars: int, power: int, books: int, knowledgeSteps: int} $income */
    private static function addToolsAndKnowledge(array &$income): void
    {
        $income['tools']++;
        $income['knowledgeSteps']++;
    }

    /** @param array{tools: int, coins: int, scholars: int, power: int, books: int, knowledgeSteps: int} $income */
    private static function addBooksAndPower(array &$income): void
    {
        $income['books']++;
        $income['power']++;
    }

    /** @param array{tools: int, coins: int, scholars: int, power: int, books: int, knowledgeSteps: int} $income */
    private static function addPowerAndBook(array &$income): void
    {
        $income['power'] += 2;
        $income['books']++;
    }

    /** @param array{tools: int, coins: int, scholars: int, power: int, books: int, knowledgeSteps: int} $income */
    private static function addPalaceEightIncome(array &$income): void
    {
        $income['power'] += 2;
        $income['coins'] += 2;
        $income['tools']++;
    }
}
