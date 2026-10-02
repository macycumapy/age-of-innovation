<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\PlayerAbilities\Actions;

use App\Domain\GameEngine\Economy\Actions\GainPowerAction;
use App\Domain\GameEngine\Research\Enums\Competency;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
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
