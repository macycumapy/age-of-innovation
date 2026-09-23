<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Actions\AssignScholarSlotsAction;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\SendScholarOptionData;
use App\Domain\Game\Enums\KnowledgeDiscipline;

final class SendScholarOptionFinder
{
    public function __construct(private AssignScholarSlotsAction $assignScholarSlots)
    {
    }

    /** @return list<SendScholarOptionData> */
    public function execute(GameStateData $state, GamePlayerStateData $player): array
    {
        if (! $state->round->phase->isActionPhase()
            || $state->pendingInteraction !== null
            || $state->round->hasTakenMainAction
            || $player->resources->scholars < 1) {
            return [];
        }

        $normalizedState = GameStateData::from($state->toArray());
        $options = [];

        foreach (KnowledgeDiscipline::cases() as $discipline) {
            $options[] = new SendScholarOptionData($discipline, false, 1, null);

            if ($player->scholarPoolSize < 1) {
                continue;
            }

            $slotIndex = $this->assignScholarSlots->nextAvailable($normalizedState, $discipline);
            if ($slotIndex !== null) {
                $placedScholarCount = collect($normalizedState->players)->sum(
                    static fn (GamePlayerStateData $candidate): int => count(array_filter(
                        $candidate->scholarDisciplineIds,
                        static fn (string $disciplineId): bool => $disciplineId === $discipline->value,
                    )),
                );
                $options[] = new SendScholarOptionData(
                    $discipline,
                    true,
                    $placedScholarCount === 0 ? 3 : 2,
                    $slotIndex,
                );
            }
        }

        return $options;
    }
}
