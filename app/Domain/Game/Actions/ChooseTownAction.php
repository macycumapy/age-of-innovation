<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Enums\RoundScoringGoal;
use App\Domain\Game\Enums\RoundScoringTile;
use App\Domain\Game\Enums\TownTile;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ChooseTownAction
{
    public function __construct(
        private AppendGameHistoryAction $appendGameHistory,
        private AdvanceKnowledgeAction $advanceKnowledge,
        private GainPowerAction $gainPower,
        private FindEligibleTerraformHexesAction $findEligibleTerraformHexes,
    ) {
    }

    public function execute(Game $game, User $user, TownTile $townTile): Game
    {
        return DB::transaction(function () use ($game, $user, $townTile): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $interaction = $state->pendingInteraction;
            $player = $lockedGame->players()->whereKey($interaction?->playerId)->whereBelongsTo($user)->first();

            if ($lockedGame->phase !== GamePhase::Actions
                || $lockedGame->active_player_id !== $user->id
                || $interaction?->type !== PendingInteractionType::ChooseTown
                || ! $player instanceof GamePlayer
                || ! in_array($townTile->value, $interaction->optionIds, true)) {
                throw ValidationException::withMessages(['town_tile' => 'Этот жетон города недоступен.']);
            }

            $playerState = collect($state->players)->firstWhere('playerId', $player->id);
            $townHexIds = $interaction->context['townHexIds'] ?? [];
            $builtHexId = (string) ($interaction->context['builtHexId'] ?? '');
            $markerHexId = (string) ($interaction->context['markerHexId'] ?? $builtHexId);
            $isFreePalaceTownTile = ($interaction->context['freePalaceTownTile'] ?? false) === true;
            $queuedBuiltHexIds = is_array($interaction->context['queuedBuiltHexIds'] ?? null)
                ? $interaction->context['queuedBuiltHexIds']
                : [];

            if (! $playerState instanceof GamePlayerStateData
                || ! is_array($townHexIds)
                || ($townHexIds === [] && ! $isFreePalaceTownTile)) {
                throw ValidationException::withMessages(['town_tile' => 'Не найдены клетки основанного города.']);
            }

            $stateVersionBefore = $lockedGame->version;

            if ($state->turnStartSnapshot === null) {
                $state->turnStartSnapshot = $state->toArray();
                $state->round->turnStartVersion = $stateVersionBefore;
            }

            $townChoiceCheckpoint = $state->toArray();
            $townId = $isFreePalaceTownTile ? null : (string) Str::uuid();

            foreach ($state->board->hexes as $hex) {
                if (in_array($hex->id, $townHexIds, true)) {
                    $hex->townId = $townId;
                    $hex->townTileId = $hex->id === $markerHexId ? $townTile->value : null;
                }
            }

            $playerState->townTileIds[] = $townTile->value;
            $townTileIndex = array_search($townTile->value, $state->availableTownTileIds, true);

            if ($townTileIndex !== false) {
                array_splice($state->availableTownTileIds, $townTileIndex, 1);
            }

            $victoryPoints = $this->applyReward($state, $playerState, $townTile);
            $playerState->victoryPoints += $victoryPoints;
            $state->pendingInteraction = null;

            $isFelineTown = $playerState->faction === Faction::Felines;

            if ($townTile === TownTile::Books) {
                $state->pendingInteraction = new PendingInteractionData(
                    PendingInteractionType::ChooseTownBooks,
                    $player->id,
                    [],
                    [
                        'bookCount' => 2,
                        'builtHexId' => $builtHexId,
                        'queuedBuiltHexIds' => $queuedBuiltHexIds,
                        ...($isFelineTown ? ['felineBonusPending' => true] : []),
                    ],
                );
            } elseif ($townTile === TownTile::Terraform) {
                $playerState->unassignedSpades += 2;
                $eligibleHexIds = $this->findEligibleTerraformHexes->execute($state, $playerState, $playerState->homeland);
                $state->pendingInteraction = new PendingInteractionData(
                    PendingInteractionType::SpendSpades,
                    $player->id,
                    $eligibleHexIds,
                    [
                        'phase' => GamePhase::Actions->value,
                        'spadeCount' => 2,
                        'remainingSpades' => 2,
                        'targetTerrain' => $playerState->homeland->value,
                        ...($isFelineTown ? ['felineBonusPending' => true] : []),
                    ],
                );
            } elseif ($isFelineTown) {
                $playerState->resources->books->unassigned++;
                $state->pendingInteraction = new PendingInteractionData(
                    PendingInteractionType::ChooseFelineTownBonus,
                    $player->id,
                    [],
                    [
                        'bookCount' => 1,
                        'knowledgeStepCount' => 3,
                        'builtHexId' => $builtHexId,
                        'queuedBuiltHexIds' => $queuedBuiltHexIds,
                    ],
                );
            }
            $nextActiveUserId = $player->user_id;

            $state->townChoiceCheckpoint = $townChoiceCheckpoint;

            $lockedGame->update([
                'active_player_id' => $nextActiveUserId,
                'state' => $state,
                'version' => $lockedGame->version + 1,
            ]);
            $this->appendGameHistory->execute(
                $lockedGame,
                $user,
                GameActionType::ChooseTown,
                [
                    'town_tile' => $townTile->value,
                    'town_id' => $townId,
                    'town_hex_ids' => $townHexIds,
                    'marker_hex_id' => $markerHexId,
                    'queued_built_hex_ids' => $queuedBuiltHexIds,
                    'victory_points' => $victoryPoints,
                ],
                [['type' => 'town_founded', 'player_id' => $player->id, 'town_tile' => $townTile->value]],
                $stateVersionBefore,
                $lockedGame->version,
            );

            return $lockedGame->refresh();
        });
    }

    private function applyReward(
        \App\Domain\Game\Data\GameStateData $state,
        GamePlayerStateData $player,
        TownTile $townTile,
    ): int {
        $knowledgeLevelBefore = array_sum(array_map(
            static fn (KnowledgeDiscipline $discipline): int => $player->knowledge->{$discipline->value},
            KnowledgeDiscipline::cases(),
        ));

        match ($townTile) {
            TownTile::Tools => $player->resources->tools += 3,
            TownTile::Books => $player->resources->books->unassigned += 2,
            TownTile::Coins => $player->resources->coins += 6,
            TownTile::Knowledge => array_map(
                fn (KnowledgeDiscipline $discipline) => $this->advanceKnowledge->execute($state, $player, $discipline, 1),
                KnowledgeDiscipline::cases(),
            ),
            TownTile::Power => $this->gainPower->execute($player, 8),
            TownTile::Scholar => $player->resources->scholars++,
            TownTile::Terraform => null,
        };

        $roundTile = RoundScoringTile::tryFrom((string) $state->round->scoringTileId);
        $advancedKnowledgeSteps = array_sum(array_map(
            static fn (KnowledgeDiscipline $discipline): int => $player->knowledge->{$discipline->value},
            KnowledgeDiscipline::cases(),
        )) - $knowledgeLevelBefore;

        return match ($townTile) {
            TownTile::Tools => 4,
            TownTile::Terraform, TownTile::Books => 5,
            TownTile::Coins => 6,
            TownTile::Knowledge => 7,
            TownTile::Power, TownTile::Scholar => 8,
        } + ($roundTile?->goal() === RoundScoringGoal::Town ? 5 : 0)
            + ($roundTile?->goal() === RoundScoringGoal::Knowledge ? $advancedKnowledgeSteps : 0);
    }
}
