<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Enums\RoundScoringTile;
use App\Models\GamePlayer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

final class ResolveScienceBonusPhaseAction
{
    public function __construct(
        private FindEligibleTerraformHexesAction $findEligibleTerraformHexes,
        private GainPowerAction $gainPower,
        private StartNextRoundAction $startNextRound,
    ) {
    }

    /**
     * @param Collection<int, GamePlayer> $players
     * @return array{GamePlayer|null, GamePhase, list<array{player_id: int, tools: int, coins: int, scholars: int, power: int, books: int, knowledge_steps: int}>}
     */
    public function execute(GameStateData $state, Collection $players): array
    {
        $scoringTile = RoundScoringTile::tryFrom((string) $state->round->scoringTileId);

        if ($scoringTile === null) {
            throw ValidationException::withMessages(['game' => 'Не найдена научная цель текущего раунда.']);
        }

        while ($state->round->scienceBonusTurnIndex < count($state->turnOrder)) {
            $playerId = $state->turnOrder[$state->round->scienceBonusTurnIndex];
            $player = $players->firstWhere('id', $playerId);
            $playerState = collect($state->players)->firstWhere('playerId', $playerId);

            if (! $player instanceof GamePlayer || ! $playerState instanceof GamePlayerStateData) {
                throw ValidationException::withMessages(['game' => 'Нарушен порядок научных бонусов.']);
            }

            $knowledgeLevel = $playerState->knowledge->{$scoringTile->knowledgeDiscipline()->value};
            $reward = $scoringTile->scienceBonus($knowledgeLevel);
            $playerState->resources->coins += $reward['coins'];
            $playerState->resources->tools += $reward['tools'];
            $playerState->resources->scholars = min(
                $playerState->scholarPoolSize,
                $playerState->resources->scholars + $reward['scholars'],
            );
            $this->gainPower->execute($playerState, $reward['power']);
            $state->round->scienceBonusTurnIndex++;

            if ($reward['books'] > 0) {
                $state->pendingInteraction = new PendingInteractionData(
                    PendingInteractionType::ChooseScienceBonusBooks,
                    $playerId,
                    [],
                    ['bookCount' => $reward['books']],
                );

                return [$player, GamePhase::ScienceBonus, []];
            }

            if ($reward['spades'] > 0) {
                $options = $this->findEligibleTerraformHexes->execute($state, $playerState, $playerState->homeland);

                if ($options !== []) {
                    $playerState->unassignedSpades += $reward['spades'];
                    $state->pendingInteraction = new PendingInteractionData(
                        PendingInteractionType::SpendSpades,
                        $playerId,
                        $options,
                        [
                            'phase' => GamePhase::ScienceBonus->value,
                            'remainingSpades' => $reward['spades'],
                            'targetTerrain' => $playerState->homeland->value,
                        ],
                    );

                    return [$player, GamePhase::ScienceBonus, []];
                }
            }
        }

        $state->pendingInteraction = null;

        if ($state->round->number >= 6) {
            $state->round->phase = GamePhase::Finished;

            return [null, GamePhase::Finished, []];
        }

        return $this->startNextRound->execute($state, $players);
    }
}
