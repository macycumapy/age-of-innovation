<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Board\Actions;

use App\Domain\GameEngine\Interactions\Actions\AdvancePendingInteractionQueueAction;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use Illuminate\Validation\ValidationException;

final class ApplySkipBridgeAction
{
    public function __construct(private AdvancePendingInteractionQueueAction $advancePendingInteractionQueue)
    {
    }

    public function execute(GameStateData $state, GamePlayerStateData $player): int
    {
        $interaction = $state->pendingInteraction;

        if ($interaction?->type !== PendingInteractionType::PlaceBridge
            || $interaction->playerId !== $player->playerId
            || ($interaction->context['source'] ?? null) !== 'palace_15'
            || isset($interaction->context['selectedFromHexId'])) {
            throw ValidationException::withMessages(['bridge' => 'От этого моста нельзя отказаться.']);
        }

        return $this->advancePendingInteractionQueue->execute(
            $state,
            $player,
            (string) ($interaction->context['builtHexId'] ?? ''),
            ($interaction->context['powerOffersResolved'] ?? false) === true,
        );
    }
}
