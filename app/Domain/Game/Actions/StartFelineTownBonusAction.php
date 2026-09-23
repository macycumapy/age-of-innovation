<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Enums\PendingInteractionType;

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
