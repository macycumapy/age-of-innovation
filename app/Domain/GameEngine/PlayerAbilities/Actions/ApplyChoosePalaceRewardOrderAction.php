<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\PlayerAbilities\Actions;

use App\Domain\GameEngine\Interactions\Actions\AdvancePendingInteractionQueueAction;
use App\Domain\GameEngine\Interactions\Data\PendingInteractionData;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use Illuminate\Validation\ValidationException;

final class ApplyChoosePalaceRewardOrderAction
{
    public function __construct(private AdvancePendingInteractionQueueAction $advancePendingInteractionQueue)
    {
    }
    public function execute(GameStateData $state, GamePlayerStateData $player, string $firstReward): int
    {
        $interaction = $state->pendingInteraction;
        if ($interaction?->type !== PendingInteractionType::ChoosePalaceRewardOrder
            || $interaction->playerId !== $player->playerId
            || ! in_array($firstReward, $interaction->optionIds, true)) {
            throw ValidationException::withMessages(['first_reward' => 'Сейчас нельзя выбрать это действие дворца.']);
        }
        if ($firstReward === 'bridges') {
            $spades = array_values(array_filter($state->pendingInteractionQueue, static fn (PendingInteractionData $step): bool => $step->type === PendingInteractionType::SpendSpades));
            $bridges = array_values(array_filter($state->pendingInteractionQueue, static fn (PendingInteractionData $step): bool => $step->type === PendingInteractionType::PlaceBridge));
            $state->pendingInteractionQueue = [...$bridges, ...$spades];
        }
        return $this->advancePendingInteractionQueue->execute(
            $state,
            $player,
            (string) ($interaction->context['builtHexId'] ?? ''),
            ($interaction->context['powerOffersResolved'] ?? false) === true,
        );
    }
}
