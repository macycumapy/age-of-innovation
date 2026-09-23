<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\ChooseFelineTownBonusResultData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Enums\PendingInteractionType;
use Illuminate\Validation\ValidationException;

final class ApplyChooseFelineTownBonusAction
{
    public function __construct(
        private AdvanceKnowledgeAction $advanceKnowledge,
        private CreateTownChoiceAfterBuildingAction $createTownChoiceAfterBuilding,
    ) {
    }

    /**
     * @param array<string, int> $bookCounts
     * @param array<string, int> $knowledgeCounts
     */
    public function execute(
        GameStateData $state,
        GamePlayerStateData $player,
        array $bookCounts,
        array $knowledgeCounts,
        ?int $turnStartVersion = null,
    ): ChooseFelineTownBonusResultData {
        $interaction = $state->pendingInteraction;
        $bookCount = (int) ($interaction?->context['bookCount'] ?? 0);
        $knowledgeStepCount = (int) ($interaction?->context['knowledgeStepCount'] ?? 0);

        if ($interaction?->type !== PendingInteractionType::ChooseFelineTownBonus
            || $interaction->playerId !== $player->playerId
            || ! $this->hasOnlyDisciplines($bookCounts)
            || ! $this->hasOnlyDisciplines($knowledgeCounts)
            || array_sum($bookCounts) !== $bookCount
            || array_sum($knowledgeCounts) !== $knowledgeStepCount
            || $player->resources->books->unassigned < $bookCount) {
            throw ValidationException::withMessages(['book_counts' => 'Нельзя распределить бонус Кошачьих.']);
        }

        if ($state->turnStartSnapshot === null) {
            $state->turnStartSnapshot = $state->toArray();
            $state->round->turnStartVersion = $turnStartVersion;
        }

        foreach ($bookCounts as $discipline => $count) {
            $player->resources->books->{$discipline} += $count;
        }
        $player->resources->books->unassigned -= $bookCount;
        $gainedPower = 0;
        $victoryPoints = 0;

        foreach ($knowledgeCounts as $discipline => $count) {
            $knowledgeAdvance = $this->advanceKnowledge->execute(
                $state,
                $player,
                KnowledgeDiscipline::from($discipline),
                $count,
            );
            $gainedPower += $knowledgeAdvance->gainedPower;
            $victoryPoints += $knowledgeAdvance->victoryPoints;
        }

        $player->victoryPoints += $victoryPoints;
        $state->pendingInteraction = null;
        $nextActiveUserId = $player->userId;
        $continueBuildingHexId = $interaction->context['continueBuildingAfterPowerHexId'] ?? null;

        if (is_string($continueBuildingHexId)) {
            $nextActiveUserId = $this->createTownChoiceAfterBuilding->execute(
                $state,
                $player,
                $continueBuildingHexId,
                powerOffersResolved: true,
            );
        }

        return new ChooseFelineTownBonusResultData(
            $nextActiveUserId,
            $victoryPoints,
            $gainedPower,
            is_string($continueBuildingHexId) ? $continueBuildingHexId : null,
        );
    }

    /** @param array<string, int> $counts */
    private function hasOnlyDisciplines(array $counts): bool
    {
        $disciplineIds = array_column(KnowledgeDiscipline::cases(), 'value');

        return collect($counts)->keys()->every(
            static fn (string $discipline): bool => in_array($discipline, $disciplineIds, true),
        ) && collect($counts)->every(
            static fn (int $count): bool => $count >= 0,
        );
    }
}
