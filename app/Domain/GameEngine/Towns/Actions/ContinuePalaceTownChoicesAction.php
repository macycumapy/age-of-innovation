<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Towns\Actions;

use App\Domain\GameEngine\Interactions\Actions\AdvancePendingInteractionQueueAction;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;

final class ContinuePalaceTownChoicesAction
{
    public function __construct(private AdvancePendingInteractionQueueAction $advancePendingInteractionQueue)
    {
    }

    public function execute(GameStateData $state, GamePlayerStateData $player): void
    {
        if ($state->pendingInteraction !== null
            || $state->pendingInteractionQueue === []
            || ! $state->round->phase->isActionPhase()) {
            return;
        }

        $this->advancePendingInteractionQueue->execute($state, $player, '', powerOffersResolved: true);
    }
}
