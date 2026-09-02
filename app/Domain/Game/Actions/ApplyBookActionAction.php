<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Enums\BookAction;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Enums\PendingInteractionType;
use Illuminate\Validation\ValidationException;

final class ApplyBookActionAction
{
    public function __construct(
        private AdvanceKnowledgeAction $advanceKnowledge,
        private ApplyBuildingBonusesAction $applyBuildingBonuses,
        private CreateBuildingFollowUpInteractionAction $createBuildingFollowUpInteraction,
        private FindEligibleTerraformHexesAction $findEligibleTerraformHexes,
        private GainPowerAction $gainPower,
    ) {
    }

    /**
     * @param array<string, int> $bookCounts
     * @return array{nextActiveUserId: int, victoryPoints: int, buildingBonusPoints: int, buildingBonusCoins: int}
     */
    public function execute(
        GameStateData $state,
        GamePlayerStateData $playerState,
        BookAction $action,
        array $bookCounts,
        ?KnowledgeDiscipline $discipline,
        ?string $hexId,
    ): array {
        $availableActionIds = array_map(
            static fn (BookAction|string $availableAction): string => $availableAction instanceof BookAction
                ? $availableAction->value
                : $availableAction,
            $state->setupPool?->bookActions ?? [],
        );

        if (! in_array($action->value, $availableActionIds, true)
            || in_array($action->value, $state->round->usedBookActionIds, true)) {
            throw ValidationException::withMessages(['action' => 'Это действие за книги недоступно.']);
        }

        $this->spendBooks($playerState, $action, $bookCounts);
        $victoryPoints = 0;
        $buildingBonusPoints = 0;
        $buildingBonusCoins = 0;
        $nextActiveUserId = $playerState->userId;

        if ($action === BookAction::GainPower) {
            $this->gainPower->execute($playerState, 5);
        } elseif ($action === BookAction::AdvanceKnowledge) {
            if ($discipline === null) {
                throw ValidationException::withMessages(['discipline' => 'Выберите дисциплину знаний.']);
            }

            $this->advanceKnowledge->execute($state, $playerState, $discipline, 2);
        } elseif ($action === BookAction::GainCoins) {
            $playerState->resources->coins += 6;
        } elseif ($action === BookAction::UpgradeToGuild) {
            $hex = collect($state->board->hexes)->firstWhere('id', $hexId);
            $guildCount = count(array_filter(
                $state->board->hexes,
                static fn (BoardHexStateData $candidate): bool => $candidate->building?->ownerPlayerId === $playerState->playerId
                    && $candidate->building->type === BuildingType::Guild
                    && ! $candidate->building->isNeutral,
            ));

            if (! $hex instanceof BoardHexStateData
                || $hex->building?->ownerPlayerId !== $playerState->playerId
                || $hex->building->isNeutral
                || $hex->building->type !== BuildingType::Workshop
                || $guildCount >= BuildingType::Guild->supplyLimit()) {
                throw ValidationException::withMessages(['hex_id' => 'Выберите свою мастерскую.']);
            }

            $hex->building->type = BuildingType::Guild;
            $bonuses = $this->applyBuildingBonuses->execute($state, $playerState, $hex, BuildingType::Guild);
            $buildingBonusPoints = $bonuses['victoryPoints'];
            $buildingBonusCoins = $bonuses['coins'];
            $nextActiveUserId = $this->createBuildingFollowUpInteraction->execute(
                $state,
                $playerState,
                $hex->id,
                BuildingType::Guild,
            );
        } elseif ($action === BookAction::ScoreGuilds) {
            $guildCount = count(array_filter(
                $state->board->hexes,
                static fn (BoardHexStateData $hex): bool => $hex->building?->ownerPlayerId === $playerState->playerId
                    && $hex->building->type === BuildingType::Guild,
            ));
            $victoryPoints = $guildCount * 2;
            $playerState->victoryPoints += $victoryPoints;
        } elseif ($action === BookAction::TerraformThreeSpades) {
            $playerState->unassignedSpades += 3;
            $eligibleHexIds = $this->findEligibleTerraformHexes->execute(
                $state,
                $playerState,
                $playerState->homeland,
            );

            if ($eligibleHexIds !== []) {
                $state->pendingInteraction = new PendingInteractionData(
                    PendingInteractionType::SpendSpades,
                    $playerState->playerId,
                    $eligibleHexIds,
                    [
                        'phase' => GamePhase::Actions->value,
                        'spadeCount' => 3,
                        'remainingSpades' => 3,
                        'targetTerrain' => $playerState->homeland->value,
                    ],
                );
            }
        }

        $state->round->usedBookActionIds[] = $action->value;
        $state->round->hasTakenMainAction = true;

        return [
            'nextActiveUserId' => $nextActiveUserId,
            'victoryPoints' => $victoryPoints,
            'buildingBonusPoints' => $buildingBonusPoints,
            'buildingBonusCoins' => $buildingBonusCoins,
        ];
    }

    /** @param array<string, int> $bookCounts */
    private function spendBooks(
        GamePlayerStateData $playerState,
        BookAction $action,
        array $bookCounts,
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

        if (array_sum($normalizedCounts) !== $action->cost()) {
            throw ValidationException::withMessages([
                'book_counts' => "Для действия нужно выбрать {$action->cost()} книг.",
            ]);
        }

        foreach ($normalizedCounts as $bookType => $count) {
            $playerState->resources->books->{$bookType} -= $count;
        }
    }
}
