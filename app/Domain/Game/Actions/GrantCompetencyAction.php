<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Enums\Competency;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Services\CompetencySupply;
use Illuminate\Validation\ValidationException;

final class GrantCompetencyAction
{
    public function __construct(private AdvanceKnowledgeAction $advanceKnowledge)
    {
    }

    /** @param list<Competency|string> $availableCompetencies */
    public function execute(
        GameStateData $state,
        GamePlayerStateData $playerState,
        Competency $competency,
        array $availableCompetencies,
    ): void {
        if (in_array($competency->value, $playerState->competencyIds, true)) {
            throw ValidationException::withMessages([
                'competency_id' => 'У игрока уже есть эта компетенция.',
            ]);
        }

        if ($state->schemaVersion < CompetencySupply::CURRENT_SCHEMA_VERSION) {
            $state->availableCompetencyIds = CompetencySupply::availableIds($state);
            $state->schemaVersion = CompetencySupply::CURRENT_SCHEMA_VERSION;
        }

        $availableCompetencyIndex = array_search($competency->value, $state->availableCompetencyIds, true);

        if (! is_int($availableCompetencyIndex)) {
            throw ValidationException::withMessages([
                'competency_id' => 'Все плашки этой компетенции уже разобраны.',
            ]);
        }

        $competencyIndex = $this->competencyIndex($competency, $availableCompetencies);
        $disciplines = KnowledgeDiscipline::cases();
        $discipline = $disciplines[$competencyIndex % count($disciplines)];
        $competencyRow = intdiv($competencyIndex, count($disciplines));

        $playerState->competencyIds[] = $competency->value;
        unset($state->availableCompetencyIds[$availableCompetencyIndex]);
        $state->availableCompetencyIds = array_values($state->availableCompetencyIds);
        $this->advanceKnowledge->execute($state, $playerState, $discipline, 3 - $competencyRow);
        $philosopherBonusBooks = $playerState->faction === Faction::Philosophers ? 1 : 0;
        $playerState->resources->books->{$discipline->value} += $competencyRow + $philosopherBonusBooks;
        $this->applyImmediateEffect($playerState, $competency);
    }

    /** @param list<Competency|string> $availableCompetencies */
    private function competencyIndex(Competency $competency, array $availableCompetencies): int
    {
        foreach ($availableCompetencies as $index => $availableCompetency) {
            $availableCompetencyValue = $availableCompetency instanceof Competency
                ? $availableCompetency->value
                : $availableCompetency;

            if ($availableCompetencyValue === $competency->value) {
                return $index;
            }
        }

        throw ValidationException::withMessages([
            'competency_id' => 'Выбранная компетенция недоступна.',
        ]);
    }

    private function applyImmediateEffect(GamePlayerStateData $playerState, Competency $competency): void
    {
        match ($competency) {
            Competency::Competency04 => $this->grantCompetency04Resources($playerState),
            Competency::Competency05 => $playerState->unassignedSpades += 2,
            Competency::Competency06 => $playerState->availableAnnexes += 2,
            default => null,
        };
    }

    private function grantCompetency04Resources(GamePlayerStateData $playerState): void
    {
        $playerState->resources->tools++;
        $playerState->resources->coins += 2;
        $playerState->victoryPoints += 5;
    }

}
