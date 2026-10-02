<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Research\Services;

use App\Domain\GameEngine\Research\Actions\AssignScholarSlotsAction;
use App\Domain\GameEngine\Research\Data\SendScholarOptionData;
use App\Domain\GameEngine\Research\Enums\KnowledgeDiscipline;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;

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

        $normalizedState = $state->deepCopy();
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
