<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Research\Actions;

use App\Domain\GameEngine\Board\Actions\CreateNeutralBuildingInteractionAction;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Interactions\Data\PendingInteractionData;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\Research\Data\InnovationRewardData;
use App\Domain\GameEngine\Research\Data\MakeInnovationResultData;
use App\Domain\GameEngine\Research\Enums\Innovation;
use App\Domain\GameEngine\Research\Enums\KnowledgeDiscipline;
use App\Domain\GameEngine\Research\Services\InnovationPurchaseCostCalculator;
use App\Domain\GameEngine\Scoring\Enums\RoundScoringGoal;
use App\Domain\GameEngine\Scoring\Enums\RoundScoringTile;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use Illuminate\Validation\ValidationException;

final class ApplyMakeInnovationAction
{
    public function __construct(
        private InnovationPurchaseCostCalculator $costCalculator,
        private ApplyInnovationRewardAction $applyInnovationReward,
        private CreateNeutralBuildingInteractionAction $createNeutralBuildingInteraction,
    ) {
    }

    /** @param array<string, int> $bookCounts */
    public function execute(
        GameStateData $state,
        GamePlayerStateData $playerState,
        Innovation $innovation,
        array $bookCounts,
    ): MakeInnovationResultData {
        if (count($playerState->inventionIds) >= InnovationPurchaseCostCalculator::MAX_INVENTIONS) {
            throw ValidationException::withMessages(['innovation' => 'Можно получить не более трёх инноваций.']);
        }

        if (! in_array($innovation->value, $state->availableInventionIds, true)) {
            throw ValidationException::withMessages(['innovation' => 'Эта инновация уже недоступна.']);
        }

        $playerCount = $state->setupPool->playerCount;
        $slotIndex = $this->costCalculator->slotIndex($state->setupPool->innovations, $innovation);
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

        $unassignedKnowledgeStepCount = $innovation === Innovation::Architecture ? $reward['knowledgeSteps'] : 0;

        if ($reward['books'] > 0 || $unassignedKnowledgeStepCount > 0) {
            $state->pendingInteraction = new PendingInteractionData(
                PendingInteractionType::ChooseInnovationReward,
                $playerState->playerId,
                context: [
                    'bookCount' => $reward['books'],
                    'knowledgeStepCount' => $unassignedKnowledgeStepCount,
                    'innovation' => $innovation->value,
                    'source' => 'innovation',
                ],
            );
        }

        $neutralBuildingType = $innovation->neutralBuildingType();

        if ($neutralBuildingType !== null) {
            $neutralBuildingInteractionCreated = $this->createNeutralBuildingInteraction->execute(
                $state,
                $playerState,
                $neutralBuildingType,
                ['innovation' => $innovation->value, 'source' => 'innovation'],
            );

            if ($innovation === Innovation::School && ! $neutralBuildingInteractionCreated) {
                $state->pendingInteraction = new PendingInteractionData(
                    PendingInteractionType::ChooseCompetency,
                    $playerState->playerId,
                    array_values(array_unique(array_filter(
                        $state->availableCompetencyIds,
                        static fn (string $competencyId): bool => ! in_array(
                            $competencyId,
                            $playerState->competencyIds,
                            true,
                        ),
                    ))),
                    [
                        'reason' => 'innovation',
                        'innovation' => $innovation->value,
                    ],
                );
            }
        }

        return new MakeInnovationResultData(
            $cost['coins'],
            $reward['victoryPoints'] + $roundVictoryPoints,
            $cost['totalBooks'],
            new InnovationRewardData(...$reward),
        );
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
