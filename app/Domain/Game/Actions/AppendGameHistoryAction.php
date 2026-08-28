<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Enums\GameActionType;
use App\Models\Game;
use App\Models\GameAction;
use App\Models\User;

final class AppendGameHistoryAction
{
    /**
     * @param array<string, mixed> $payload
     * @param list<array<string, mixed>> $events
     */
    public function execute(
        Game $lockedGame,
        User $user,
        GameActionType $type,
        array $payload,
        array $events,
        int $stateVersionBefore,
        int $stateVersionAfter,
    ): GameAction {
        $nextSequence = ((int) $lockedGame->actions()->max('sequence')) + 1;

        return $lockedGame->actions()->create([
            'sequence' => $nextSequence,
            'player_id' => $user->id,
            'type' => $type,
            'payload' => $payload,
            'events' => $events,
            'state_version_before' => $stateVersionBefore,
            'state_version_after' => $stateVersionAfter,
        ]);
    }
}
