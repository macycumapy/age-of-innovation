<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Economy\Services;

use App\Domain\GameEngine\Research\Enums\KnowledgeDiscipline;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use Illuminate\Validation\ValidationException;

final class BookDistributionValidator
{
    /** @param array<string, int> $bookCounts */
    public function validate(GamePlayerStateData $player, array $bookCounts, int $expectedCount): void
    {
        if (! $this->hasOnlyDisciplines($bookCounts)
            || array_sum($bookCounts) !== $expectedCount
            || $player->resources->books->unassigned < $expectedCount) {
            throw ValidationException::withMessages(['book_counts' => 'Нельзя распределить эти книги.']);
        }
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
