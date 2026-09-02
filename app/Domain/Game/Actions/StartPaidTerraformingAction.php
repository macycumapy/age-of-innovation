<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StartPaidTerraformingAction
{
    public function __construct(private FindEligibleTerraformHexesAction $findEligibleTerraformHexes)
    {
    }

    public function execute(Game $game, User $user, string $hexId, bool $useAvailable): Game
    {
        return DB::transaction(function () use ($game, $user, $hexId, $useAvailable): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $player = $lockedGame->players()->whereBelongsTo($user)->first();
            $interaction = $state->pendingInteraction;
            $isExistingSpadeInteraction = $interaction?->type === PendingInteractionType::SpendSpades
                && $interaction->playerId === $player?->id
                && ! isset($interaction->context['selectedHexId']);

            if ($lockedGame->phase !== GamePhase::Actions
                || $lockedGame->active_player_id !== $user->id
                || ! $player instanceof GamePlayer
                || ($interaction !== null && ! $isExistingSpadeInteraction)
                || ($interaction === null && $state->round->hasTakenMainAction)) {
                throw ValidationException::withMessages(['game' => 'Сейчас нельзя начать преобразование.']);
            }

            $playerState = collect($state->players)->firstWhere('playerId', $player->id);

            if (! $playerState instanceof GamePlayerStateData) {
                throw ValidationException::withMessages(['game' => 'Не найдено состояние игрока.']);
            }

            $toolCostPerSpade = max(1, 3 - $playerState->terraformingLevel);
            $eligibleHexIds = $isExistingSpadeInteraction
                ? $interaction->optionIds
                : $this->findEligibleTerraformHexes->execute($state, $playerState, $playerState->homeland);
            $targetHex = collect($state->board->hexes)->firstWhere('id', $hexId);
            $requiredSpadeCount = $targetHex?->terrain->spadesTo($playerState->homeland) ?? 0;
            $purchasedSpadeCount = max(0, $requiredSpadeCount - $playerState->unassignedSpades);
            $totalToolCost = $purchasedSpadeCount * $toolCostPerSpade;

            if (! in_array($hexId, $eligibleHexIds, true) || $requiredSpadeCount < 1) {
                throw ValidationException::withMessages(['hex_id' => 'Эта клетка недоступна для преобразования.']);
            }

            if ($useAvailable) {
                if (! $isExistingSpadeInteraction
                    || $playerState->unassignedSpades < 1
                    || $playerState->unassignedSpades >= $requiredSpadeCount) {
                    throw ValidationException::withMessages([
                        'hex_id' => 'Нельзя преобразовать эту клетку только доступными лопатами.',
                    ]);
                }

                $purchasedSpadeCount = 0;
                $totalToolCost = 0;
            }

            $spadesToSpend = $useAvailable
                ? min($requiredSpadeCount, $playerState->unassignedSpades)
                : $requiredSpadeCount;

            if ($totalToolCost > $playerState->resources->tools) {
                throw ValidationException::withMessages(['hex_id' => 'Недостаточно инструментов для преобразования этой клетки.']);
            }

            $stateVersionBefore = $lockedGame->version;

            if ($state->turnStartSnapshot === null) {
                $state->turnStartSnapshot = $state->toArray();
                $state->round->turnStartVersion = $stateVersionBefore;
            }

            $playerState->resources->tools -= $totalToolCost;
            $playerState->unassignedSpades += $purchasedSpadeCount;
            $state->round->hasTakenMainAction = true;

            if ($isExistingSpadeInteraction) {
                $interaction->optionIds = [$hexId];
                $interaction->context['remainingSpades'] = (int) ($interaction->context['remainingSpades'] ?? 0)
                    + $purchasedSpadeCount;
                $interaction->context['paidTools'] = (int) ($interaction->context['paidTools'] ?? 0)
                    + $totalToolCost;
                $interaction->context['paidSpadeCount'] = (int) ($interaction->context['paidSpadeCount'] ?? 0)
                    + $purchasedSpadeCount;
                $interaction->context['spadesToSpend'] = $spadesToSpend;
                $state->pendingInteraction = $interaction;
            } else {
                $state->pendingInteraction = new PendingInteractionData(
                    PendingInteractionType::SpendSpades,
                    $player->id,
                    [$hexId],
                    [
                        'phase' => GamePhase::Actions->value,
                        'spadeCount' => $requiredSpadeCount,
                        'remainingSpades' => $requiredSpadeCount,
                        'targetTerrain' => $playerState->homeland->value,
                        'paidTools' => $totalToolCost,
                        'paidSpadeCount' => $purchasedSpadeCount,
                        'spadesToSpend' => $spadesToSpend,
                    ],
                );
            }

            $lockedGame->update([
                'state' => $state,
                'version' => $lockedGame->version + 1,
            ]);

            return $lockedGame->refresh();
        });
    }
}
