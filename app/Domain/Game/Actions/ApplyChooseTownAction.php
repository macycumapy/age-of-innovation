<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\ChooseTownResultData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Data\TownRewardResultData;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Enums\RoundScoringGoal;
use App\Domain\Game\Enums\RoundScoringTile;
use App\Domain\Game\Enums\TownTile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ApplyChooseTownAction
{
    public function __construct(
        private AdvanceKnowledgeAction $advanceKnowledge,
        private GainPowerAction $gainPower,
        private FindEligibleTerraformHexesAction $findEligibleTerraformHexes,
        private StartFelineTownBonusAction $startFelineTownBonus,
        private StartLizardTownBonusAction $startLizardTownBonus,
    ) {
    }

    public function execute(
        GameStateData $state,
        GamePlayerStateData $player,
        TownTile $townTile,
    ): ChooseTownResultData {
        $interaction = $state->pendingInteraction;

        if ($interaction?->type !== PendingInteractionType::ChooseTown
            || $interaction->playerId !== $player->playerId
            || ! in_array($townTile->value, $interaction->optionIds, true)) {
            throw ValidationException::withMessages(['town_tile' => 'Этот жетон города недоступен.']);
        }

        $townHexIds = $this->stringList($interaction->context['townHexIds'] ?? []);
        $builtHexId = (string) ($interaction->context['builtHexId'] ?? '');
        $markerHexId = (string) ($interaction->context['markerHexId'] ?? $builtHexId);
        $isFreePalaceTownTile = ($interaction->context['freePalaceTownTile'] ?? false) === true;
        $queuedBuiltHexIds = $this->stringList($interaction->context['queuedBuiltHexIds'] ?? []);

        if ($townHexIds === [] && ! $isFreePalaceTownTile) {
            throw ValidationException::withMessages(['town_tile' => 'Не найдены клетки основанного города.']);
        }

        $state->townChoiceCheckpoint = $state->toArray();
        $townId = $isFreePalaceTownTile ? null : (string) Str::uuid();

        foreach ($state->board->hexes as $hex) {
            if (in_array($hex->id, $townHexIds, true)) {
                $hex->townId = $townId;
                $hex->townTileId = $hex->id === $markerHexId ? $townTile->value : null;
            }
        }

        $player->townTileIds[] = $townTile->value;
        $townTileIndex = array_search($townTile->value, $state->availableTownTileIds, true);

        if ($townTileIndex !== false) {
            array_splice($state->availableTownTileIds, $townTileIndex, 1);
        }

        $reward = $this->applyReward($state, $player, $townTile);
        $player->victoryPoints += $reward->victoryPoints;
        $state->pendingInteraction = null;
        $isFelineTown = $player->faction === Faction::Felines;
        $isLizardTown = $player->faction === Faction::Lizards;

        if ($townTile === TownTile::Books) {
            $state->pendingInteraction = new PendingInteractionData(
                PendingInteractionType::ChooseTownBooks,
                $player->playerId,
                context: [
                    'bookCount' => 2,
                    'builtHexId' => $builtHexId,
                    'queuedBuiltHexIds' => $queuedBuiltHexIds,
                    ...($isFelineTown ? ['felineBonusPending' => true] : []),
                    ...($isLizardTown ? ['lizardBonusPending' => true] : []),
                ],
            );
        } elseif ($townTile === TownTile::Terraform) {
            $player->unassignedSpades += 2;
            $eligibleHexIds = $this->findEligibleTerraformHexes->execute($state, $player, $player->homeland);
            $state->pendingInteraction = new PendingInteractionData(
                PendingInteractionType::SpendSpades,
                $player->playerId,
                $eligibleHexIds,
                [
                    'phase' => GamePhase::Actions->value,
                    'spadeCount' => 2,
                    'remainingSpades' => 2,
                    'targetTerrain' => $player->homeland->value,
                    ...($isFelineTown ? ['felineBonusPending' => true] : []),
                    ...($isLizardTown ? ['lizardBonusPending' => true] : []),
                ],
            );
        } elseif ($isFelineTown) {
            $this->startFelineTownBonus->execute($state, $player, [
                'builtHexId' => $builtHexId,
                'queuedBuiltHexIds' => $queuedBuiltHexIds,
            ]);
        } elseif ($isLizardTown) {
            $this->startLizardTownBonus->execute($state, $player);
        }

        return new ChooseTownResultData(
            $player->userId,
            $townId,
            $townHexIds,
            $markerHexId,
            $reward->victoryPoints,
            $reward->gainedPower,
        );
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }

    private function applyReward(
        GameStateData $state,
        GamePlayerStateData $player,
        TownTile $townTile,
    ): TownRewardResultData {
        $knowledgeAdvances = [];
        $gainedPower = match ($townTile) {
            TownTile::Tools => $player->resources->tools += 3,
            TownTile::Books => $player->resources->books->unassigned += 2,
            TownTile::Coins => $player->resources->coins += 6,
            TownTile::Knowledge => array_sum(array_map(function (KnowledgeDiscipline $discipline) use ($state, $player, &$knowledgeAdvances): int {
                $knowledgeAdvance = $this->advanceKnowledge->execute($state, $player, $discipline, 1);
                $knowledgeAdvances[] = $knowledgeAdvance;

                return $knowledgeAdvance->gainedPower;
            }, KnowledgeDiscipline::cases())),
            TownTile::Power => $this->gainPower->execute($player, 8),
            TownTile::Scholar => $player->resources->scholars = min(
                $player->scholarPoolSize,
                $player->resources->scholars + 1,
            ),
            TownTile::Terraform => null,
        };

        $roundTile = RoundScoringTile::tryFrom((string) $state->round->scoringTileId);
        $knowledgeVictoryPoints = array_sum(array_column($knowledgeAdvances, 'victoryPoints'));
        $victoryPoints = match ($townTile) {
            TownTile::Tools => 4,
            TownTile::Terraform, TownTile::Books => 5,
            TownTile::Coins => 6,
            TownTile::Knowledge => 7,
            TownTile::Power, TownTile::Scholar => 8,
        } + ($roundTile?->goal() === RoundScoringGoal::Town ? 5 : 0)
            + $knowledgeVictoryPoints;

        return new TownRewardResultData(
            victoryPoints: $victoryPoints,
            gainedPower: $townTile === TownTile::Knowledge ? $gainedPower : 0,
        );
    }
}
