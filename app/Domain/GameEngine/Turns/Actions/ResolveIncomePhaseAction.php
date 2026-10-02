<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Turns\Actions;

use App\Domain\GameEngine\Economy\Actions\ApplyIncomeAction;
use App\Domain\GameEngine\Economy\Data\IncomeReceiptData;
use App\Domain\GameEngine\Economy\Services\PlayerIncomeCalculator;
use App\Domain\GameEngine\Interactions\Data\PendingInteractionData;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\Research\Enums\KnowledgeDiscipline;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use Illuminate\Validation\ValidationException;

final class ResolveIncomePhaseAction
{
    public function __construct(private ApplyIncomeAction $applyIncome)
    {
    }

    /**
     * @return array{GamePlayerStateData, GamePhase, list<IncomeReceiptData>}
     */
    public function execute(GameStateData $state): array
    {
        if ($state->round->incomeOrder === []) {
            $state->round->incomeOrder = $this->prepareManualResources($state);
        }

        while ($state->round->incomeTurnIndex < count($state->round->incomeOrder)) {
            $playerId = $state->round->incomeOrder[$state->round->incomeTurnIndex];
            $playerState = collect($state->players)->firstWhere('playerId', $playerId);

            if (! $playerState instanceof GamePlayerStateData) {
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

                return [$playerState, GamePhase::Income, []];
            }
        }

        foreach ($state->turnOrder as $playerId) {
            $playerState = collect($state->players)->firstWhere('playerId', $playerId);

            if (! $playerState instanceof GamePlayerStateData) {
                throw ValidationException::withMessages(['game' => 'Нарушен порядок получения дохода.']);
            }

            $state->round->incomeReceipts[] = $this->applyIncome->execute($state, $playerState, false);
        }

        $firstPlayer = collect($state->players)->firstWhere('playerId', $state->turnOrder[0] ?? null);

        if (! $firstPlayer instanceof GamePlayerStateData) {
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
        return array_values(collect($state->turnOrder)
            ->filter(function (int $playerId) use ($playerStates, $state): bool {
                $playerState = $playerStates->get($playerId);

                if (! $playerState instanceof GamePlayerStateData) {
                    return false;
                }

                $income = PlayerIncomeCalculator::calculate($playerState, $state->board);
                $playerState->resources->books->unassigned += $income->books;
                $playerState->knowledge->unassignedSteps += $income->knowledgeSteps;

                return $playerState->resources->books->unassigned > 0
                    || $playerState->knowledge->unassignedSteps > 0;
            })
            ->values()
            ->all());
    }
}
