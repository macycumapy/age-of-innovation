<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\Competency;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use Illuminate\Validation\ValidationException;

final class GrantCompetencyAction
{
    /** @param list<Competency|string> $availableCompetencies */
    public function execute(
        GamePlayerStateData $playerState,
        Competency $competency,
        array $availableCompetencies,
    ): void {
        if (in_array($competency->value, $playerState->competencyIds, true)) {
            throw ValidationException::withMessages([
                'competency_id' => 'У игрока уже есть эта компетенция.',
            ]);
        }

        $competencyIndex = $this->competencyIndex($competency, $availableCompetencies);
        $disciplines = KnowledgeDiscipline::cases();
        $discipline = $disciplines[$competencyIndex % count($disciplines)];
        $competencyRow = intdiv($competencyIndex, count($disciplines));

        $playerState->competencyIds[] = $competency->value;
        $this->advanceKnowledge($playerState, $discipline, 3 - $competencyRow);
        $playerState->resources->books->{$discipline->value} += $competencyRow;
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
            default => null,
        };
    }

    private function grantCompetency04Resources(GamePlayerStateData $playerState): void
    {
        $playerState->resources->tools++;
        $playerState->resources->coins += 2;
        $playerState->victoryPoints += 5;
    }

    private function advanceKnowledge(
        GamePlayerStateData $playerState,
        KnowledgeDiscipline $discipline,
        int $steps,
    ): void {
        $currentLevel = $playerState->knowledge->{$discipline->value};
        $newLevel = $currentLevel + $steps;
        $powerRewards = [3 => 1, 5 => 2, 7 => 2, 12 => 3];

        foreach ($powerRewards as $level => $power) {
            if ($currentLevel < $level && $newLevel >= $level) {
                $this->gainPower($playerState, $power);
            }
        }

        $playerState->knowledge->{$discipline->value} = $newLevel;
    }

    private function gainPower(GamePlayerStateData $playerState, int $power): void
    {
        for ($step = 0; $step < $power; $step++) {
            if ($playerState->resources->power->bowlOne > 0) {
                $playerState->resources->power->bowlOne--;
                $playerState->resources->power->bowlTwo++;

                continue;
            }

            if ($playerState->resources->power->bowlTwo > 0) {
                $playerState->resources->power->bowlTwo--;
                $playerState->resources->power->bowlThree++;
            }
        }
    }
}
