<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Enums\PendingInteractionType;
use Illuminate\Validation\ValidationException;

final class ApplyRewardBookDistributionAction
{
    public function __construct(private CreateTownChoiceAfterBuildingAction $createTownChoiceAfterBuilding)
    {
    }

    /** @param array<string, int> $bookCounts */
    public function execute(
        GameStateData $state,
        GamePlayerStateData $player,
        array $bookCounts,
        PendingInteractionType $expectedInteractionType,
    ): int {
        $interaction = $state->pendingInteraction;
        $bookCount = (int) ($interaction?->context['bookCount'] ?? 0);

        if (! in_array($expectedInteractionType, $this->supportedInteractionTypes(), true)
            || $interaction?->type !== $expectedInteractionType
            || $interaction->playerId !== $player->playerId
            || ! $this->hasOnlyDisciplines($bookCounts)
            || array_sum($bookCounts) !== $bookCount
            || $player->resources->books->unassigned < $bookCount) {
            throw ValidationException::withMessages(['book_counts' => 'Сейчас нельзя распределить эти книги.']);
        }

        foreach ($bookCounts as $discipline => $count) {
            $player->resources->books->{$discipline} += $count;
        }
        $player->resources->books->unassigned -= $bookCount;
        $state->pendingInteraction = null;

        if ($expectedInteractionType === PendingInteractionType::ChoosePalaceBooks) {
            return $this->createTownChoiceAfterBuilding->execute(
                $state,
                $player,
                (string) ($interaction->context['builtHexId'] ?? ''),
            );
        }

        return $player->userId;
    }

    /** @return list<PendingInteractionType> */
    private function supportedInteractionTypes(): array
    {
        return [
            PendingInteractionType::ChooseShippingBooks,
            PendingInteractionType::ChooseTerraformingBooks,
            PendingInteractionType::ChoosePalaceBooks,
        ];
    }

    /** @param array<string, int> $bookCounts */
    private function hasOnlyDisciplines(array $bookCounts): bool
    {
        $disciplineIds = array_column(KnowledgeDiscipline::cases(), 'value');

        return collect($bookCounts)->keys()->every(
            static fn (string $discipline): bool => in_array($discipline, $disciplineIds, true),
        ) && collect($bookCounts)->every(
            static fn (int $count): bool => $count >= 0,
        );
    }
}
