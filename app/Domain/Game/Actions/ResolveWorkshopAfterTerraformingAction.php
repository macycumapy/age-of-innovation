<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\BuildingStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ResolveWorkshopAfterTerraformingAction
{
    public function __construct(
        private AppendGameHistoryAction $appendGameHistory,
        private CreateBuildingFollowUpInteractionAction $createBuildingFollowUpInteraction,
        private CreatePowerOffersAfterBuildingAction $createPowerOffersAfterBuilding,
        private ApplyBuildingBonusesAction $applyBuildingBonuses,
    ) {
    }

    public function execute(Game $game, User $user, bool $build, ?string $hexId): Game
    {
        return DB::transaction(function () use ($game, $user, $build, $hexId): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $interaction = $state->pendingInteraction;
            $player = $lockedGame->players()->whereBelongsTo($user)->first();

            if ($lockedGame->phase !== GamePhase::Actions
                || $lockedGame->active_player_id !== $user->id
                || ! $player instanceof GamePlayer
                || $interaction?->type !== PendingInteractionType::BuildWorkshopAfterTerraforming
                || $interaction->playerId !== $player->id
                || ($build && (! is_string($hexId) || ! in_array($hexId, $interaction->optionIds, true)))) {
                throw ValidationException::withMessages(['game' => 'Сейчас нельзя подтвердить строительство дома.']);
            }

            $playerState = collect($state->players)->firstWhere('playerId', $player->id);

            if (! $playerState instanceof GamePlayerStateData) {
                throw ValidationException::withMessages(['game' => 'Не найдено состояние игрока.']);
            }

            $bonuses = ['victoryPoints' => 0, 'coins' => 0, 'sources' => []];
            $felineBonusPending = ($interaction->context['felineBonusPending'] ?? false) === true;
            $toolCost = max(0, (int) ($interaction->context['toolCost'] ?? 1));
            $coinCost = max(0, (int) ($interaction->context['coinCost'] ?? 2));

            if ($build) {
                $hex = collect($state->board->hexes)->firstWhere('id', $hexId);
                $workshopsOnMap = count(array_filter(
                    $state->board->hexes,
                    static fn (BoardHexStateData $boardHex): bool => $boardHex->building?->ownerPlayerId === $player->id
                        && $boardHex->building->type === BuildingType::Workshop
                        && ! $boardHex->building->isNeutral,
                ));

                if (! $hex instanceof BoardHexStateData
                    || $hex->building !== null
                    || $hex->terrain !== $playerState->homeland
                    || $playerState->resources->tools < $toolCost
                    || $playerState->resources->coins < $coinCost
                    || $workshopsOnMap >= BuildingType::Workshop->supplyLimit()) {
                    throw ValidationException::withMessages(['game' => 'Дом нельзя построить на выбранной клетке.']);
                }

                $playerState->resources->tools -= $toolCost;
                $playerState->resources->coins -= $coinCost;
                $hex->building = new BuildingStateData(BuildingType::Workshop, $player->id);
                $bonuses = $this->applyBuildingBonuses->execute(
                    $state,
                    $playerState,
                    $hex,
                    BuildingType::Workshop,
                );
            }

            $stateVersionBefore = $lockedGame->version;
            $state->pendingInteraction = null;
            $state->round->hasTakenMainAction = true;
            if ($felineBonusPending) {
                $nextActiveUserId = $build
                    ? $this->createPowerOffersAfterBuilding->execute($state, $player->id, (string) $hexId)
                    : null;

                if ($nextActiveUserId !== null
                    && $state->pendingInteraction?->type === PendingInteractionType::PowerOffer) {
                    $state->pendingInteraction->context['felineBonusPending'] = true;
                } else {
                    $playerState->resources->books->unassigned++;
                    $state->pendingInteraction = new PendingInteractionData(
                        PendingInteractionType::ChooseFelineTownBonus,
                        $player->id,
                        [],
                        [
                            'bookCount' => 1,
                            'knowledgeStepCount' => 3,
                            ...($build ? ['continueBuildingAfterPowerHexId' => (string) $hexId] : []),
                        ],
                    );
                    $nextActiveUserId = $player->user_id;
                }
            } else {
                $nextActiveUserId = $build
                    ? $this->createBuildingFollowUpInteraction->execute(
                        $state,
                        $playerState,
                        (string) $hexId,
                        BuildingType::Workshop,
                    )
                    : null;
            }
            $lockedGame->update([
                'active_player_id' => $nextActiveUserId ?? $player->user_id,
                'state' => $state,
                'version' => $lockedGame->version + 1,
            ]);
            $this->appendGameHistory->execute(
                $lockedGame,
                $user,
                GameActionType::TerraformAndBuild,
                [
                    'built' => $build,
                    'hex_id' => $build ? $hexId : null,
                    'victory_points' => $bonuses['victoryPoints'],
                    'bonus_coins' => $bonuses['coins'],
                    'scoring_sources' => $bonuses['sources'],
                    'feline_bonus_pending' => $felineBonusPending,
                    'tool_cost' => $toolCost,
                    'coin_cost' => $coinCost,
                ],
                [[
                    'type' => $build ? 'workshop_built_after_terraforming' : 'workshop_declined_after_terraforming',
                    'player_id' => $player->id,
                    'hex_id' => $build ? $hexId : null,
                ]],
                $stateVersionBefore,
                $lockedGame->version,
            );

            return $lockedGame->refresh();
        });
    }
}
