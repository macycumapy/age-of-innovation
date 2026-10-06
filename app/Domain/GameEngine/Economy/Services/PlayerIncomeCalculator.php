<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Economy\Services;

use App\Domain\GameEngine\Board\Data\BoardStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Economy\Data\IncomeReceiptData;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\PalaceAbility;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Research\Enums\Competency;
use App\Domain\GameEngine\Research\Enums\Innovation;
use App\Domain\GameEngine\Research\Enums\KnowledgeDiscipline;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;

final class PlayerIncomeCalculator
{
    public static function calculate(
        GamePlayerStateData $player,
        BoardStateData $board,
        bool $includeRoundBonus = true,
    ): IncomeReceiptData {
        $income = new IncomeReceiptData(
            playerId: $player->playerId,
            tools: 1,
            coins: $player->color === PlayerColor::Grey ? 2 : 0,
            scholars: 0,
            power: 0,
            books: 0,
            knowledgeSteps: 0,
            victoryPoints: 0,
        );
        $buildingCounts = self::buildingCounts($player->playerId, $board);

        $workshopCount = $buildingCounts[BuildingType::Workshop->value];
        $income->tools += $workshopCount - ($workshopCount >= 5 ? 1 : 0);
        $guildCount = $buildingCounts[BuildingType::Guild->value];
        $income->coins += $guildCount * 2;

        if ($player->color === PlayerColor::Grey && $guildCount > 0) {
            $income->coins++;
        }
        $income->power += match ($guildCount) {
            0 => 0,
            1 => 1,
            2 => 2,
            3 => 4,
            default => 6,
        };
        $income->scholars += $buildingCounts[BuildingType::School->value]
            + $buildingCounts[BuildingType::University->value];

        if ($player->faction === Faction::Omar) {
            self::addPowerCoins($income, 2, 2);
        }

        if ($includeRoundBonus) {
            self::addRoundBonusIncome($income, $player->roundBonus);
        }

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
                self::addResource($income, $resource, $amount);
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

    private static function addRoundBonusIncome(IncomeReceiptData $income, RoundBonus $roundBonus): void
    {
        match ($roundBonus) {
            RoundBonus::SendScholar => $income->scholars++,
            RoundBonus::BuildGuild => $income->power += 3,
            RoundBonus::PassPalaceUniversity => $income->tools++,
            RoundBonus::Spade, RoundBonus::Bridge => $income->books++,
            RoundBonus::Knowledge => $income->tools += 2,
            RoundBonus::PassSchool => $income->coins += 4,
            RoundBonus::PowerCoins => self::addPowerCoins($income, 4, 2),
            RoundBonus::Coins => $income->coins += 6,
            RoundBonus::RiverWorkshop => null,
        };
    }

    private static function addCompetencyIncome(IncomeReceiptData $income, Competency $competency): void
    {
        match ($competency) {
            Competency::Competency01 => self::addToolsAndKnowledge($income),
            Competency::Competency02 => self::addVictoryPointsAndCoins($income),
            Competency::Competency03 => self::addBooksAndPower($income),
            Competency::Competency10 => self::addPowerCoins($income, 2, 2),
            default => null,
        };
    }

    private static function addVictoryPointsAndCoins(IncomeReceiptData $income): void
    {
        $income->victoryPoints += 3;
        $income->coins += 2;
    }

    private static function addInnovationIncome(IncomeReceiptData $income, Innovation $innovation): void
    {
        match ($innovation) {
            Innovation::Workshop => $income->tools += 3,
            Innovation::Guild => $income->coins += 5,
            Innovation::Palace => $income->power += 4,
            Innovation::University => $income->victoryPoints += 2,
            default => null,
        };
    }

    private static function addPalaceIncome(IncomeReceiptData $income, PalaceAbility $palace): void
    {
        match ($palace) {
            PalaceAbility::Palace01 => $income->power += 5,
            PalaceAbility::Palace03, PalaceAbility::Palace04, PalaceAbility::Palace17 => $income->power += 2,
            PalaceAbility::Palace05, PalaceAbility::Palace07 => $income->power += 4,
            PalaceAbility::Palace06, PalaceAbility::Palace16 => self::addPowerAndBook($income),
            PalaceAbility::Palace08 => self::addPalaceEightIncome($income),
            PalaceAbility::Palace09 => $income->scholars++,
            PalaceAbility::Palace10 => $income->coins += 6,
            PalaceAbility::Palace11 => $income->tools++,
            PalaceAbility::Palace12 => $income->power += 8,
            PalaceAbility::Palace14, PalaceAbility::Palace15 => $income->power += 6,
            PalaceAbility::Palace02, PalaceAbility::Palace13 => null,
        };
    }

    private static function addPowerCoins(IncomeReceiptData $income, int $power, int $coins): void
    {
        $income->power += $power;
        $income->coins += $coins;
    }

    private static function addToolsAndKnowledge(IncomeReceiptData $income): void
    {
        $income->tools++;
        $income->knowledgeSteps++;
    }

    private static function addBooksAndPower(IncomeReceiptData $income): void
    {
        $income->books++;
        $income->power++;
    }

    private static function addPowerAndBook(IncomeReceiptData $income): void
    {
        $income->power += 2;
        $income->books++;
    }

    private static function addPalaceEightIncome(IncomeReceiptData $income): void
    {
        $income->power += 2;
        $income->coins += 2;
        $income->tools++;
    }

    private static function addResource(IncomeReceiptData $income, string $resource, int $amount): void
    {
        match ($resource) {
            'tools' => $income->tools += $amount,
            'coins' => $income->coins += $amount,
            'scholars' => $income->scholars += $amount,
            'power' => $income->power += $amount,
            'books' => $income->books += $amount,
            'knowledgeSteps' => $income->knowledgeSteps += $amount,
            'victoryPoints' => $income->victoryPoints += $amount,
            default => null,
        };
    }
}
