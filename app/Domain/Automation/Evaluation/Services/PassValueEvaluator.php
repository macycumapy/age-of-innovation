<?php

declare(strict_types=1);

namespace App\Domain\Automation\Evaluation\Services;

use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Research\Enums\Innovation;
use App\Domain\GameEngine\Research\Enums\KnowledgeDiscipline;
use App\Domain\GameEngine\Research\Services\InnovationPurchaseCostCalculator;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use InvalidArgumentException;

final class PassValueEvaluator
{
    private const int COIN_WEIGHT = 1;

    private const int TOOL_WEIGHT = 3;

    private const int SCHOLAR_WEIGHT = 4;

    private const int BOOK_WEIGHT = 3;

    private const int POWER_WEIGHT = 1;

    private const int SPADE_WEIGHT = 3;

    public function __construct(
        private InnovationPurchaseCostCalculator $innovationPurchaseCostCalculator,
        private InnovationEvaluator $innovationEvaluator,
    ) {
    }

    public function execute(GameStateData $state, int $playerId): int
    {
        $player = collect($state->players)->firstWhere('playerId', $playerId);

        if (! $player instanceof GamePlayerStateData) {
            throw new InvalidArgumentException('Не найдено состояние игрока для оценки паса.');
        }

        $resources = $player->resources;
        $books = $resources->books;
        $power = $resources->power;
        $roundMultiplier = max(1, (int) ceil($state->round->number / 2));
        $bookCount = $books->banking + $books->law + $books->engineering + $books->medicine + $books->unassigned;
        $reservedBookCount = $this->reservedBookCount($state, $player, $bookCount);
        $spendableBookCount = $bookCount - $reservedBookCount;
        $reservedCoins = $reservedBookCount > 0 && $player->palaceId === null
            ? InnovationPurchaseCostCalculator::PALACE_COIN_SURCHARGE : 0;

        return (
            (max(0, $resources->coins - $reservedCoins) * self::COIN_WEIGHT)
            + ($resources->tools * self::TOOL_WEIGHT)
            + ($resources->scholars * self::SCHOLAR_WEIGHT)
            + ($spendableBookCount * self::BOOK_WEIGHT)
            + (($power->bowlThree + intdiv($power->bowlTwo, 2)) * self::POWER_WEIGHT)
            + ($player->unassignedSpades * self::SPADE_WEIGHT)
        ) * $roundMultiplier;
    }

    private function reservedBookCount(GameStateData $state, GamePlayerStateData $player, int $bookCount): int
    {
        if ($state->round->number >= 6 || $state->setupPool === null
            || count($player->inventionIds) >= InnovationPurchaseCostCalculator::MAX_INVENTIONS) {
            return 0;
        }

        foreach ($state->setupPool->innovations as $slotIndex => $innovation) {
            $innovation = $this->innovationType($innovation);
            if (! in_array($innovation->value, $state->availableInventionIds, true)
                || $this->innovationEvaluator->purchasePotential($state, $player, $innovation) < 100) {
                continue;
            }

            $cost = $this->innovationPurchaseCostCalculator->cost(
                $state->setupPool->playerCount,
                $slotIndex,
                count($player->inventionIds),
                $player->homeland === TerrainType::Wasteland,
                $player->palaceId !== null,
            );
            $missingRequiredBooks = 0;
            foreach (KnowledgeDiscipline::cases() as $discipline) {
                $missingRequiredBooks += max(0, $cost['requiredBooks'][$discipline->value]
                    - $player->resources->books->{$discipline->value});
            }
            $missingRequiredBooks = max(0, $missingRequiredBooks - $player->resources->books->unassigned);

            if ($bookCount >= $cost['totalBooks'] - 1 && $missingRequiredBooks <= 1) {
                return min($bookCount, $cost['totalBooks']);
            }
        }

        return 0;
    }

    private function innovationType(Innovation|string $innovation): Innovation
    {
        return $innovation instanceof Innovation ? $innovation : Innovation::from($innovation);
    }
}
