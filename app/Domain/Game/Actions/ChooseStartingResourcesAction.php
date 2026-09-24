<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\Competency;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GameEventType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\GameStatus;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ChooseStartingResourcesAction
{
    public function __construct(
        private DetermineNextPlanningPlayerAction $determineNextPlanningPlayer,
        private AppendGameHistoryAction $appendGameHistory,
        private ApplyStartingResourcesAction $applyStartingResources,
    ) {
    }

    /**
     * @param array<string, int> $bookCounts
     * @param array<string, int> $knowledgeCounts
     */
    public function execute(
        Game $game,
        User $user,
        array $bookCounts,
        array $knowledgeCounts,
        ?Competency $competency,
    ): Game {
        return DB::transaction(function () use ($game, $user, $bookCounts, $knowledgeCounts, $competency): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $stateVersionBefore = $lockedGame->version;
            $interaction = $lockedGame->state->pendingInteraction;
            $interactionPhase = $lockedGame->phase;

            if ($lockedGame->status !== GameStatus::Active
                || ! in_array($interactionPhase, [GamePhase::Setup, GamePhase::Income], true)
                || $interaction?->type !== PendingInteractionType::ChooseStartingResources) {
                throw ValidationException::withMessages([
                    'game' => 'Выбор стартовых ресурсов сейчас недоступен.',
                ]);
            }

            $player = $lockedGame->players()
                ->whereKey($interaction->playerId)
                ->whereBelongsTo($user)
                ->first();

            if (! $player instanceof GamePlayer || $lockedGame->active_player_id !== $user->id) {
                throw ValidationException::withMessages([
                    'game' => 'Стартовые ресурсы должен выбрать текущий игрок.',
                ]);
            }

            $state = $lockedGame->state;
            $playerState = collect($state->players)->firstWhere('playerId', $player->id);

            if (! $playerState instanceof GamePlayerStateData) {
                throw ValidationException::withMessages([
                    'game' => 'Не найдено игровое состояние участника.',
                ]);
            }

            if ($interactionPhase === GamePhase::Setup && $competency instanceof Competency) {
                throw ValidationException::withMessages([
                    'competency_id' => 'Стартовая компетенция выбирается после расстановки зданий.',
                ]);
            }

            $result = $this->applyStartingResources->execute(
                $state,
                $playerState,
                $bookCounts,
                $knowledgeCounts,
            );

            if ($interactionPhase === GamePhase::Income) {
                $nextActivePlayerId = $result->nextActivePlayerId;
            } else {
                $nextActivePlayerId = $this->determineNextPlanningPlayer->execute($lockedGame, $player)->id;
            }

            $lockedGame->update([
                'active_game_player_id' => $nextActivePlayerId,
                'phase' => $result->nextPhase,
                'version' => $lockedGame->version + 1,
                'state' => $state,
            ]);
            $this->appendGameHistory->execute(
                $lockedGame,
                $player,
                $interactionPhase === GamePhase::Income
                    ? GameActionType::ChooseIncomeResources
                    : GameActionType::ChooseStartingResources,
                [
                    'book_disciplines' => $this->disciplines($bookCounts),
                    'knowledge_disciplines' => $this->disciplines($knowledgeCounts),
                    'competency' => $competency?->value,
                    'phase' => $interactionPhase->value,
                    'income_receipts' => $result->incomeReceipts,
                    'gained_power' => $result->gainedPower,
                ],
                [[
                    'type' => $interactionPhase === GamePhase::Income
                        ? GameEventType::IncomeResourcesChosen->value
                        : GameEventType::StartingResourcesChosen->value,
                    'player_id' => $player->id,
                ]],
                $stateVersionBefore,
                $lockedGame->version,
                $result->nextPhase !== $interactionPhase,
            );

            return $lockedGame->refresh();
        });
    }

    /**
     * @param array<string, int> $counts
     * @return list<string>
     */
    private function disciplines(array $counts): array
    {
        $disciplines = [];

        foreach (KnowledgeDiscipline::cases() as $discipline) {
            for ($count = $counts[$discipline->value] ?? 0; $count > 0; $count--) {
                $disciplines[] = $discipline->value;
            }
        }

        return $disciplines;
    }
}
