<?php

declare(strict_types=1);

namespace App\Domain\Automation\Evaluation\Services;

use App\Domain\GameEngine\Board\Actions\FindEligibleTerraformHexesAction;
use App\Domain\GameEngine\Scoring\Enums\RoundScoringTile;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;

final class ScienceBonusEvaluator
{
    public function __construct(private FindEligibleTerraformHexesAction $findEligibleTerraformHexes)
    {
    }

    public function execute(GameStateData $state, GamePlayerStateData $player): int
    {
        if ($state->setupPool === null || $state->round->phase === GamePhase::Finished
            || $state->round->number >= 6) {
            return 0;
        }

        $currentBonusApplied = $state->round->phase === GamePhase::ScienceBonus
            && collect($state->round->scienceBonusReceipts)->contains(
                static fn (array $receipt): bool => ($receipt['round'] ?? null) === $state->round->number
                    && ($receipt['player_id'] ?? null) === $player->playerId,
            );
        $hasTerraformingTarget = null;
        $score = 0;

        for ($roundNumber = max(1, $state->round->number); $roundNumber < 6; $roundNumber++) {
            if ($roundNumber === $state->round->number && $currentBonusApplied) {
                continue;
            }

            $tile = $roundNumber === $state->round->number && $state->round->scoringTileId !== null
                ? RoundScoringTile::tryFrom($state->round->scoringTileId)
                : $state->setupPool->roundScoringTiles[$roundNumber - 1] ?? null;
            if (is_string($tile)) {
                $tile = RoundScoringTile::tryFrom($tile);
            }
            if (! $tile instanceof RoundScoringTile) {
                continue;
            }

            $knowledgeLevel = $player->faction->scienceBonusKnowledgeLevel(
                $player->knowledge->{$tile->knowledgeDiscipline()->value},
            );
            $reward = $tile->scienceBonus($knowledgeLevel);
            $scholarCount = min($reward['scholars'], $player->scholarPoolSize);
            if ($roundNumber === $state->round->number) {
                $scholarCount = min($scholarCount, max(0, $player->scholarPoolSize - $player->resources->scholars));
            }
            $score += $reward['coins'] * $this->resourceWeight(20, 2, $roundNumber + 1)
                + $reward['tools'] * $this->resourceWeight(20, 2, $roundNumber + 1)
                + $scholarCount * $this->resourceWeight(40, 2, $roundNumber + 1)
                + $reward['books'] * $this->resourceWeight(25, 2, $roundNumber + 1)
                + $reward['power'] * $this->resourceWeight(7, 1, $roundNumber + 1);

            if ($reward['spades'] > 0) {
                $hasTerraformingTarget ??= $this->findEligibleTerraformHexes->execute($state, $player, $player->homeland) !== [];
                $score += $hasTerraformingTarget ? $reward['spades'] * 30 : 0;
            }
        }

        return $score;
    }

    private function resourceWeight(int $earlyWeight, int $finalWeight, int $roundNumber): int
    {
        $remainingRounds = max(0, min(2, 6 - $roundNumber));

        return intdiv($earlyWeight * $remainingRounds + $finalWeight * (2 - $remainingRounds), 2);
    }
}
