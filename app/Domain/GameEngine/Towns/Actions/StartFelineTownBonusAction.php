<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Towns\Actions;

use App\Domain\GameEngine\Interactions\Data\PendingInteractionData;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;

final class StartFelineTownBonusAction
{
    /** @param array<string, mixed> $context */
    public function execute(GameStateData $state, GamePlayerStateData $player, array $context = []): void
    {
        $player->resources->books->unassigned++;
        $player->knowledge->unassignedSteps += 3;
        $state->pendingInteraction = new PendingInteractionData(
            PendingInteractionType::ChooseFelineTownBonus,
            $player->playerId,
            [],
            [
                'bookCount' => 1,
                'knowledgeStepCount' => 3,
                ...$context,
            ],
        );
    }
}
