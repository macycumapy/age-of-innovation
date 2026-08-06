<?php

declare(strict_types=1);

namespace App\Domain\Game\Factories;

use App\Domain\Game\Data\GameSetupPoolData;
use App\Domain\Game\Data\PlanningBundleData;
use App\Domain\Game\Data\RoundBonusOfferData;
use App\Domain\Game\Enums\BookAction;
use App\Domain\Game\Enums\Competency;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\Innovation;
use App\Domain\Game\Enums\MapVariant;
use App\Domain\Game\Enums\PalaceAbility;
use App\Domain\Game\Enums\RoundBonus;
use App\Domain\Game\Enums\RoundScoringGoal;
use App\Domain\Game\Enums\RoundScoringTile;
use App\Domain\Game\Enums\TerrainType;
use App\Domain\Game\Enums\TownTile;
use InvalidArgumentException;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use RuntimeException;

final class GameSetupPoolFactory
{
    private Randomizer $randomizer;

    public function __construct(?Randomizer $randomizer = null)
    {
        $this->randomizer = $randomizer ?? new Randomizer();
    }

    public function create(int $playerCount, ?MapVariant $mapVariant = null): GameSetupPoolData
    {
        if ($playerCount < 2 || $playerCount > 5) {
            throw new InvalidArgumentException('Число игроков должно быть от 2 до 5.');
        }

        $roundScoringTiles = $this->roundScoringTiles();
        [$planningBundles, $availableRoundBonuses] = $this->planningBundles();

        return new GameSetupPoolData(
            playerCount: $playerCount,
            mapVariant: $mapVariant ?? $this->defaultMapVariant($playerCount),
            firstPlayerIndex: $this->randomizer->getInt(0, $playerCount - 1),
            roundScoringTiles: $roundScoringTiles,
            additionalFinalRoundGoal: $this->additionalFinalRoundGoal($roundScoringTiles[5]),
            bookActions: array_slice($this->shuffledCases(BookAction::class), 0, 3),
            competencies: $this->shuffledCases(Competency::class),
            innovations: array_slice(
                $this->shuffledCases(Innovation::class),
                0,
                $this->innovationSlotCount($playerCount),
            ),
            palaces: $this->palaces($playerCount),
            planningBundles: $planningBundles,
            availableRoundBonuses: $availableRoundBonuses,
            townTiles: TownTile::cases(),
            twoPlayerAreaTile: $playerCount === 2 ? $this->randomizer->getInt(1, 4) : null,
        );
    }

    public function createFromSeed(
        int $playerCount,
        string $seed,
        ?MapVariant $mapVariant = null,
    ): GameSetupPoolData {
        $factory = clone $this;
        $factory->randomizer = new Randomizer(
            new Xoshiro256StarStar(hash('sha256', $seed, true)),
        );

        return $factory->create($playerCount, $mapVariant);
    }

    private function defaultMapVariant(int $playerCount): MapVariant
    {
        return $playerCount <= 2
            ? MapVariant::OneToThreePlayers
            : MapVariant::ThreeToFivePlayers;
    }

    /** @return list<RoundScoringTile> */
    private function roundScoringTiles(): array
    {
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $tiles = array_slice($this->shuffledCases(RoundScoringTile::class), 0, 6);

            if ($tiles[4] === RoundScoringTile::SpadeEngineering
                || $tiles[5] === RoundScoringTile::SpadeEngineering) {
                continue;
            }

            $disciplineCounts = [];

            foreach (array_slice($tiles, 0, 5) as $tile) {
                $discipline = $tile->knowledgeDiscipline()->value;
                $disciplineCounts[$discipline] = ($disciplineCounts[$discipline] ?? 0) + 1;
            }

            if (max($disciplineCounts) < 3) {
                return $tiles;
            }
        }

        throw new RuntimeException('Не удалось сформировать корректные цели раундов.');
    }

    private function additionalFinalRoundGoal(RoundScoringTile $sixthRoundTile): RoundScoringGoal
    {
        $goals = array_values(array_filter(
            RoundScoringGoal::cases(),
            fn (RoundScoringGoal $goal): bool => ! $this->sameBuildingGoal($goal, $sixthRoundTile->goal()),
        ));

        return $goals[$this->randomizer->getInt(0, count($goals) - 1)];
    }

    private function sameBuildingGoal(RoundScoringGoal $first, RoundScoringGoal $second): bool
    {
        $buildingGoals = [
            RoundScoringGoal::Workshop,
            RoundScoringGoal::EdgeWorkshop,
            RoundScoringGoal::Guild,
            RoundScoringGoal::School,
            RoundScoringGoal::PalaceOrUniversity,
        ];

        if (! in_array($first, $buildingGoals, true) || ! in_array($second, $buildingGoals, true)) {
            return false;
        }

        return match ($first) {
            RoundScoringGoal::Workshop, RoundScoringGoal::EdgeWorkshop => in_array(
                $second,
                [RoundScoringGoal::Workshop, RoundScoringGoal::EdgeWorkshop],
                true,
            ),
            default => $first === $second,
        };
    }

    /** @return array{list<PlanningBundleData>, list<RoundBonusOfferData>} */
    private function planningBundles(): array
    {
        $factions = $this->shuffledCases(Faction::class);
        $roundBonuses = $this->shuffledCases(RoundBonus::class);
        $planningBundles = [];

        foreach (TerrainType::cases() as $index => $homeland) {
            $planningBundles[] = new PlanningBundleData(
                homeland: $homeland,
                faction: $factions[$index],
                roundBonus: $roundBonuses[$index],
            );
        }

        $availableRoundBonuses = array_map(
            static fn (RoundBonus $roundBonus): RoundBonusOfferData => new RoundBonusOfferData($roundBonus),
            array_slice($roundBonuses, 7),
        );

        return [$planningBundles, $availableRoundBonuses];
    }

    /** @return list<PalaceAbility> */
    private function palaces(int $playerCount): array
    {
        $randomPalaces = array_filter(
            PalaceAbility::cases(),
            static fn (PalaceAbility $palace): bool => $palace !== PalaceAbility::Palace17,
        );

        return [
            PalaceAbility::Palace17,
            ...array_slice($this->randomizer->shuffleArray($randomPalaces), 0, $playerCount + 1),
        ];
    }

    private function innovationSlotCount(int $playerCount): int
    {
        return ($playerCount * 2) + 2;
    }

    /**
     * @template T of \BackedEnum
     * @param class-string<T> $enum
     * @return list<T>
     */
    private function shuffledCases(string $enum): array
    {
        return $this->randomizer->shuffleArray($enum::cases());
    }
}
