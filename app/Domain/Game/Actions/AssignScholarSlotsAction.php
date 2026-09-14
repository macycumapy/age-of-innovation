<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Enums\KnowledgeDiscipline;

final class AssignScholarSlotsAction
{
    private const int SLOT_COUNT = 4;

    public function normalize(GameStateData $state): void
    {
        $occupiedSlotIndexes = [];

        foreach (KnowledgeDiscipline::cases() as $discipline) {
            $occupiedSlotIndexes[$discipline->value] = $state->neutralKnowledge?->scholarDisciplineIds !== null
                && in_array($discipline->value, $state->neutralKnowledge->scholarDisciplineIds, true)
                ? [$state->neutralKnowledge->scholarSlotIndex]
                : [];
        }

        foreach ($state->players as $player) {
            $normalizedSlotIndexes = [];

            foreach ($player->scholarDisciplineIds as $placementIndex => $disciplineId) {
                $discipline = KnowledgeDiscipline::tryFrom($disciplineId);
                $preferredSlotIndex = $player->scholarSlotIndexes[$placementIndex] ?? null;

                if ($discipline === null) {
                    $normalizedSlotIndexes[] = is_int($preferredSlotIndex) ? $preferredSlotIndex : 0;

                    continue;
                }

                $usedSlotIndexes = $occupiedSlotIndexes[$discipline->value];
                $slotIndex = is_int($preferredSlotIndex)
                    && $preferredSlotIndex >= 0
                    && $preferredSlotIndex < self::SLOT_COUNT
                    && ! in_array($preferredSlotIndex, $usedSlotIndexes, true)
                        ? $preferredSlotIndex
                        : $this->firstAvailableSlotIndex($usedSlotIndexes);

                if ($slotIndex === null) {
                    $slotIndex = self::SLOT_COUNT + count($usedSlotIndexes);
                }

                $normalizedSlotIndexes[] = $slotIndex;
                $occupiedSlotIndexes[$discipline->value][] = $slotIndex;
            }

            $player->scholarSlotIndexes = $normalizedSlotIndexes;
        }
    }

    public function nextAvailable(GameStateData $state, KnowledgeDiscipline $discipline): ?int
    {
        $this->normalize($state);
        $usedSlotIndexes = [];

        if ($state->neutralKnowledge !== null
            && in_array($discipline->value, $state->neutralKnowledge->scholarDisciplineIds, true)) {
            $usedSlotIndexes[] = $state->neutralKnowledge->scholarSlotIndex;
        }

        foreach ($state->players as $player) {
            foreach ($player->scholarDisciplineIds as $placementIndex => $disciplineId) {
                if ($disciplineId === $discipline->value) {
                    $usedSlotIndexes[] = $player->scholarSlotIndexes[$placementIndex];
                }
            }
        }

        return $this->firstAvailableSlotIndex($usedSlotIndexes);
    }

    /** @param list<int> $usedSlotIndexes */
    private function firstAvailableSlotIndex(array $usedSlotIndexes): ?int
    {
        foreach (range(0, self::SLOT_COUNT - 1) as $slotIndex) {
            if (! in_array($slotIndex, $usedSlotIndexes, true)) {
                return $slotIndex;
            }
        }

        return null;
    }
}
