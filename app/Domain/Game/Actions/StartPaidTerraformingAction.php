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
    public function __construct(
        private FindEligibleTerraformHexesAction $findEligibleTerraformHexes,
        private FindEligibleMoleTunnelHexesAction $findEligibleMoleTunnelHexes,
    ) {
    }

    public function execute(Game $game, User $user, string $hexId, bool $useAvailable, bool $useTunnel = false): Game
    {
        return DB::transaction(function () use ($game, $user, $hexId, $useAvailable, $useTunnel): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $player = $lockedGame->players()->whereBelongsTo($user)->first();
            $interaction = $state->pendingInteraction;
            $isExistingSpadeInteraction = $interaction?->type === PendingInteractionType::SpendSpades
                && $interaction->playerId === $player?->id
                && ! isset($interaction->context['selectedHexId']);

            $isAllowedPhase = $lockedGame->phase === GamePhase::Actions
                || ($isExistingSpadeInteraction && in_array(
                    $lockedGame->phase,
                    [GamePhase::Setup, GamePhase::ScienceBonus],
                    true,
                ));

            if (! $isAllowedPhase
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
            $regularEligibleHexIds = $isExistingSpadeInteraction
                ? $interaction->optionIds
                : $this->findEligibleTerraformHexes->execute($state, $playerState, $playerState->homeland);
            $tunnelEligibleHexIds = $isExistingSpadeInteraction && ($interaction->context['tunnelUsed'] ?? false)
                ? []
                : $this->findEligibleMoleTunnelHexes->execute($state, $playerState);
            $eligibleHexIds = array_values(array_unique([...$regularEligibleHexIds, ...$tunnelEligibleHexIds]));
            $targetHex = collect($state->board->hexes)->firstWhere('id', $hexId);
            $requiredSpadeCount = $targetHex?->terrain->spadesTo($playerState->homeland) ?? 0;
            $purchasedSpadeCount = max(0, $requiredSpadeCount - $playerState->unassignedSpades);
            $totalToolCost = $purchasedSpadeCount * $toolCostPerSpade;
            $isTunnelEligible = in_array($hexId, $tunnelEligibleHexIds, true);

            if ($useTunnel && ! $isTunnelEligible) {
                throw ValidationException::withMessages(['hex_id' => 'Для этой клетки нельзя использовать Туннель.']);
            }

            if (! $useTunnel && ! in_array($hexId, $regularEligibleHexIds, true)) {
                throw ValidationException::withMessages(['hex_id' => 'До этой клетки можно добраться только через Туннель.']);
            }

            $tunnelToolCost = $useTunnel ? 1 : 0;
            $tunnelVictoryPoints = $useTunnel ? 2 + count($state->players) : 0;
            $totalToolCost += $tunnelToolCost;

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
                $totalToolCost = $tunnelToolCost;
            }

            $spadesToSpend = $useAvailable
                ? min($requiredSpadeCount, $playerState->unassignedSpades)
                : $requiredSpadeCount;

            if ($totalToolCost > $playerState->resources->tools) {
                throw ValidationException::withMessages(['hex_id' => 'Недостаточно инструментов для преобразования этой клетки.']);
            }

            $stateVersionBefore = $lockedGame->version;

            if ($lockedGame->phase === GamePhase::Actions && $state->turnStartSnapshot === null) {
                $state->turnStartSnapshot = $state->toArray();
                $state->round->turnStartVersion = $stateVersionBefore;
            }

            $playerState->resources->tools -= $totalToolCost;
            $playerState->unassignedSpades += $purchasedSpadeCount;
            if ($lockedGame->phase === GamePhase::Actions) {
                $state->round->hasTakenMainAction = true;
            }

            if ($isExistingSpadeInteraction) {
                $interaction->context['optionIdsBeforeSelection'] = $interaction->optionIds;
                $interaction->optionIds = [$hexId];
                $interaction->context['remainingSpades'] = (int) ($interaction->context['remainingSpades'] ?? 0)
                    + $purchasedSpadeCount;
                $interaction->context['paidTools'] = (int) ($interaction->context['paidTools'] ?? 0)
                    + $totalToolCost;
                $interaction->context['paidSpadeCount'] = (int) ($interaction->context['paidSpadeCount'] ?? 0)
                    + $purchasedSpadeCount;
                $interaction->context['spadesToSpend'] = $spadesToSpend;
                $interaction->context['tunnelTools'] = $tunnelToolCost;
                $interaction->context['tunnelVictoryPoints'] = $tunnelVictoryPoints;
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
                        'tunnelTools' => $tunnelToolCost,
                        'tunnelVictoryPoints' => $tunnelVictoryPoints,
                    ],
                );
            }

            $playerState->victoryPoints += $tunnelVictoryPoints;

            $lockedGame->update([
                'state' => $state,
                'version' => $lockedGame->version + 1,
            ]);

            return $lockedGame->refresh();
        });
    }
}
