<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use Illuminate\Validation\ValidationException;

final class KnowledgeDistributionValidator
{
    /** @param array<string, int> $knowledgeCounts */
    public function validate(GamePlayerStateData $player, array $knowledgeCounts, int $expectedCount): void
    {
        if (! $this->hasOnlyDisciplines($knowledgeCounts)
            || array_sum($knowledgeCounts) !== $expectedCount
            || $player->knowledge->unassignedSteps < $expectedCount) {
            throw ValidationException::withMessages([
                'knowledge_counts' => 'Нельзя распределить эти шаги знаний.',
            ]);
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
