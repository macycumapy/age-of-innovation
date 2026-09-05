<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Services\PlayerIncomeCalculator;
use App\Models\GamePlayer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

final class ResolveIncomePhaseAction
{
    public function __construct(private ApplyIncomeAction $applyIncome)
    {
    }

    /**
     * @param Collection<int, GamePlayer> $players
     * @return array{GamePlayer, GamePhase, list<array{player_id: int, tools: int, coins: int, scholars: int, power: int, books: int, knowledge_steps: int}>}
     */
    public function execute(GameStateData $state, Collection $players): array
    {
        if ($state->round->incomeOrder === []) {
            $state->round->incomeOrder = $this->prepareManualResources($state);
        }

        while ($state->round->incomeTurnIndex < count($state->round->incomeOrder)) {
            $playerId = $state->round->incomeOrder[$state->round->incomeTurnIndex];
            $player = $players->firstWhere('id', $playerId);
            $playerState = collect($state->players)->firstWhere('playerId', $playerId);

            if (! $player instanceof GamePlayer || ! $playerState instanceof GamePlayerStateData) {
                throw ValidationException::withMessages(['game' => 'Нарушен порядок получения дохода.']);
            }

            $state->round->incomeTurnIndex++;

            if ($playerState->resources->books->unassigned > 0
                || $playerState->knowledge->unassignedSteps > 0) {
                $state->round->phase = GamePhase::Income;
                $state->pendingInteraction = new PendingInteractionData(
                    PendingInteractionType::ChooseStartingResources,
                    $playerId,
                    array_column(KnowledgeDiscipline::cases(), 'value'),
                    [
                        'bookCount' => $playerState->resources->books->unassigned,
                        'knowledgeStepCount' => $playerState->knowledge->unassignedSteps,
                        'competencyIds' => [],
                        'phase' => GamePhase::Income->value,
                    ],
                );

                return [$player, GamePhase::Income, []];
            }
        }

        foreach ($state->turnOrder as $playerId) {
            $playerState = collect($state->players)->firstWhere('playerId', $playerId);

            if (! $playerState instanceof GamePlayerStateData) {
                throw ValidationException::withMessages(['game' => 'Нарушен порядок получения дохода.']);
            }

            $income = $this->applyIncome->execute($state, $playerState, false);
            $state->round->incomeReceipts[] = [
                'player_id' => $playerId,
                'tools' => $income['tools'],
                'coins' => $income['coins'],
                'scholars' => $income['scholars'],
                'power' => $income['power'],
                'books' => $income['books'],
                'knowledge_steps' => $income['knowledgeSteps'],
                'victory_points' => $income['victoryPoints'],
            ];
        }

        $firstPlayer = $players->firstWhere('id', $state->turnOrder[0] ?? null);

        if (! $firstPlayer instanceof GamePlayer) {
            throw ValidationException::withMessages(['game' => 'Не найден первый игрок нового раунда.']);
        }

        $state->round->phase = GamePhase::Actions;
        $state->round->incomeOrder = [];
        $state->pendingInteraction = null;
        $state->turnStartSnapshot = null;
        $state->round->turnStartVersion = null;
        $state->round->hasTakenMainAction = false;
        $state->round->isCurrentTurnIrrevocable = false;
        $incomeReceipts = $state->round->incomeReceipts;
        $state->round->incomeReceipts = [];

        return [$firstPlayer, GamePhase::Actions, $incomeReceipts];
    }

    /** @return list<int> */
    private function prepareManualResources(GameStateData $state): array
    {
        $playerStates = collect($state->players)->keyBy('playerId');
        return collect($state->turnOrder)
            ->filter(function (int $playerId) use ($playerStates, $state): bool {
                $playerState = $playerStates->get($playerId);

                if (! $playerState instanceof GamePlayerStateData) {
                    return false;
                }

                $income = PlayerIncomeCalculator::calculate($playerState, $state->board);
                $playerState->resources->books->unassigned += $income['books'];
                $playerState->knowledge->unassignedSteps += $income['knowledgeSteps'];

                return $playerState->resources->books->unassigned > 0
                    || $playerState->knowledge->unassignedSteps > 0;
            })
            ->values()
            ->all();
    }
}
