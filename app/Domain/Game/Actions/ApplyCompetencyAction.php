<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\Competency;
use Illuminate\Validation\ValidationException;

final class ApplyCompetencyAction
{
    public function __construct(private GainPowerAction $gainPower)
    {
    }

    public function execute(GamePlayerStateData $playerState): void
    {
        $actionId = Competency::Competency07->value;

        if (! in_array($actionId, $playerState->competencyIds, true)
            || in_array($actionId, $playerState->usedSpecialActionIds, true)) {
            throw ValidationException::withMessages(['competency' => 'Действие этой компетенции недоступно.']);
        }

        $this->gainPower->execute($playerState, 4);
        $playerState->usedSpecialActionIds[] = $actionId;
    }
}
