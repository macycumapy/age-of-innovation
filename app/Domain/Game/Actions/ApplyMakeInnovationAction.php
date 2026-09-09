<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\Innovation;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Enums\RoundScoringGoal;
use App\Domain\Game\Enums\RoundScoringTile;
use App\Domain\Game\Enums\TerrainType;
use App\Domain\Game\Services\InnovationPurchaseCostCalculator;
use Illuminate\Validation\ValidationException;

final class ApplyMakeInnovationAction
{
    public function __construct(
        private InnovationPurchaseCostCalculator $costCalculator,
        private ApplyInnovationRewardAction $applyInnovationReward,
        private CreateNeutralBuildingInteractionAction $createNeutralBuildingInteraction,
    ) {
    }

    /**
     * @param array<string, int> $bookCounts
     * @return array{coins: int, victoryPoints: int, totalBooks: int, reward: array<string, int>}
     */
    public function execute(
        GameStateData $state,
        GamePlayerStateData $playerState,
        Innovation $innovation,
        array $bookCounts,
    ): array {
        if (count($playerState->inventionIds) >= InnovationPurchaseCostCalculator::MAX_INVENTIONS) {
            throw ValidationException::withMessages(['innovation' => 'Можно получить не более трёх инноваций.']);
        }

        if (! in_array($innovation->value, $state->availableInventionIds, true)) {
            throw ValidationException::withMessages(['innovation' => 'Эта инновация уже недоступна.']);
        }

        $playerCount = $state->setupPool?->playerCount ?? count($state->players);
        $slotIndex = $this->costCalculator->slotIndex($state->setupPool?->innovations ?? [], $innovation);
        $cost = $this->costCalculator->cost(
            $playerCount,
            $slotIndex,
            count($playerState->inventionIds),
            $playerState->homeland === TerrainType::Wasteland,
            $this->hasPalace($state, $playerState),
        );

        $this->spendBooks($playerState, $bookCounts, $cost['requiredBooks'], $cost['totalBooks']);

        if ($playerState->resources->coins < $cost['coins']) {
            throw ValidationException::withMessages(['coins' => 'Нужно заплатить 5 монет, пока дворец не построен.']);
        }

        $playerState->resources->coins -= $cost['coins'];
        $state->availableInventionIds = array_values(array_filter(
            $state->availableInventionIds,
            static fn (string $availableId): bool => $availableId !== $innovation->value,
        ));
        $playerState->inventionIds[] = $innovation->value;
        $reward = $this->applyInnovationReward->execute($state, $playerState, $innovation);

        $roundScoringTile = RoundScoringTile::tryFrom((string) $state->round->scoringTileId);
        $roundVictoryPoints = $roundScoringTile?->goal() === RoundScoringGoal::Innovation ? 5 : 0;
        $playerState->victoryPoints += $roundVictoryPoints;
        $state->round->hasTakenMainAction = true;

        if ($reward['books'] > 0) {
            $state->pendingInteraction = new PendingInteractionData(
                PendingInteractionType::ChooseInnovationBooks,
                $playerState->playerId,
                context: [
                    'bookCount' => $reward['books'],
                    'innovation' => $innovation->value,
                    'source' => 'innovation',
                ],
            );
        }

        $neutralBuildingType = $innovation->neutralBuildingType();

        if ($neutralBuildingType !== null) {
            $this->createNeutralBuildingInteraction->execute(
                $state,
                $playerState,
                $neutralBuildingType,
                ['innovation' => $innovation->value, 'source' => 'innovation'],
            );
        }

        return [
            'coins' => $cost['coins'],
            'victoryPoints' => $reward['victoryPoints'] + $roundVictoryPoints,
            'totalBooks' => $cost['totalBooks'],
            'reward' => $reward,
        ];
    }

    /**
     * @param array<string, int> $bookCounts
     * @param array{banking: int, law: int, engineering: int, medicine: int} $requiredBooks
     */
    private function spendBooks(
        GamePlayerStateData $playerState,
        array $bookCounts,
        array $requiredBooks,
        int $totalBooks,
    ): void {
        $normalizedCounts = [];

        foreach (KnowledgeDiscipline::cases() as $bookType) {
            $bookTypeValue = $bookType->value;
            $count = (int) ($bookCounts[$bookTypeValue] ?? 0);
            $available = $playerState->resources->books->{$bookTypeValue};

            if ($count < 0 || $count > $available) {
                throw ValidationException::withMessages(['book_counts' => 'Недостаточно выбранных книг.']);
            }

            $normalizedCounts[$bookTypeValue] = $count;
        }

        if (array_sum($normalizedCounts) !== $totalBooks) {
            throw ValidationException::withMessages([
                'book_counts' => "Для инновации нужно выбрать {$totalBooks} книг.",
            ]);
        }

        foreach ($requiredBooks as $bookType => $requiredCount) {
            if ($normalizedCounts[$bookType] < $requiredCount) {
                throw ValidationException::withMessages([
                    'book_counts' => 'Выбранные книги не соответствуют цветам колонки планшета инноваций.',
                ]);
            }
        }

        foreach ($normalizedCounts as $bookType => $count) {
            $playerState->resources->books->{$bookType} -= $count;
        }
    }

    private function hasPalace(GameStateData $state, GamePlayerStateData $playerState): bool
    {
        return collect($state->board->hexes)->contains(
            static fn (BoardHexStateData $hex): bool => $hex->building?->ownerPlayerId === $playerState->playerId
                && $hex->building->type === BuildingType::Palace
                && ! $hex->building->isNeutral,
        );
    }
}
