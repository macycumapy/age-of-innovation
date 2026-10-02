<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Research\Actions;

use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Research\Data\SendScholarOptionData;
use App\Domain\GameEngine\Research\Data\SendScholarResultData;
use App\Domain\GameEngine\Research\Enums\Competency;
use App\Domain\GameEngine\Research\Services\SendScholarOptionFinder;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use Illuminate\Validation\ValidationException;

final class ApplySendScholarAction
{
    public function __construct(
        private SendScholarOptionFinder $optionFinder,
        private AssignScholarSlotsAction $assignScholarSlots,
        private AdvanceKnowledgeAction $advanceKnowledge,
    ) {
    }

    public function execute(
        GameStateData $state,
        GamePlayerStateData $player,
        SendScholarOptionData $option,
    ): SendScholarResultData {
        $matchingOption = collect($this->optionFinder->execute($state, $player))->first(
            static fn (SendScholarOptionData $candidate): bool => $candidate->discipline === $option->discipline
                && $candidate->place === $option->place,
        );

        if (! $matchingOption instanceof SendScholarOptionData) {
            throw ValidationException::withMessages(['scholar' => 'Сейчас нельзя отправить учёного в эту дисциплину.']);
        }

        $slotIndex = $matchingOption->place
            ? $this->assignScholarSlots->nextAvailable($state, $matchingOption->discipline)
            : null;

        if ($matchingOption->place && $slotIndex === null) {
            throw ValidationException::withMessages(['scholar' => 'Сейчас нельзя отправить учёного в эту дисциплину.']);
        }

        $player->resources->scholars--;

        if ($matchingOption->place) {
            $player->scholarPoolSize--;
            $player->scholarDisciplineIds[] = $matchingOption->discipline->value;
            $player->scholarSlotIndexes[] = $slotIndex;
        }

        $knowledgeAdvance = $this->advanceKnowledge->execute(
            $state,
            $player,
            $matchingOption->discipline,
            $matchingOption->steps,
        );
        $actionVictoryPoints = ($player->roundBonus === RoundBonus::SendScholar ? 2 : 0)
            + (in_array(Competency::Competency09->value, $player->competencyIds, true) ? 2 : 0);
        $victoryPoints = $actionVictoryPoints + $knowledgeAdvance->victoryPoints;
        $player->victoryPoints += $victoryPoints;
        $state->round->hasTakenMainAction = true;

        return new SendScholarResultData(
            $slotIndex,
            $knowledgeAdvance->advancedSteps,
            $victoryPoints,
            $knowledgeAdvance->gainedPower,
        );
    }
}
