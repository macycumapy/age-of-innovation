<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
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

final class ChooseScienceBonusBooksAction
{
    public function __construct(
        private AppendGameHistoryAction $appendGameHistory,
        private ApplyScienceBonusBookDistributionAction $applyScienceBonusBookDistribution,
    ) {
    }

    /** @param array<string, int> $bookCounts */
    public function execute(Game $game, User $user, array $bookCounts): Game
    {
        return DB::transaction(function () use ($game, $user, $bookCounts): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $interaction = $state->pendingInteraction;
            $player = $lockedGame->players()->whereKey($interaction?->playerId)->whereBelongsTo($user)->first();

            if ($lockedGame->phase !== GamePhase::ScienceBonus
                || $lockedGame->active_player_id !== $user->id
                || $interaction?->type !== PendingInteractionType::ChooseScienceBonusBooks
                || ! $player instanceof GamePlayer) {
                throw ValidationException::withMessages(['book_counts' => 'Сейчас нельзя выбрать эти книги.']);
            }

            $playerState = collect($state->players)->firstWhere('playerId', $player->id);

            if (! $playerState instanceof GamePlayerStateData) {
                throw ValidationException::withMessages(['game' => 'Не найдено состояние игрока.']);
            }

            $stateVersionBefore = $lockedGame->version;
            $result = $this->applyScienceBonusBookDistribution->execute(
                $state,
                $playerState,
                $bookCounts,
            );
            $lockedGame->update([
                'status' => $result->nextPhase === GamePhase::Finished ? GameStatus::Finished : GameStatus::Active,
                'phase' => $result->nextPhase,
                'active_player_id' => $result->nextActiveUserId,
                'state' => $state,
                'version' => $lockedGame->version + 1,
            ]);
            $this->appendGameHistory->execute($lockedGame, $user, GameActionType::ChooseScienceBonusBooks, [
                'disciplines' => $this->disciplines($bookCounts),
                'next_phase' => $result->nextPhase->value,
                'income_receipts' => $result->incomeReceipts,
                'final_scoring' => $result->finalScoring,
                'science_bonus_receipts' => $result->scienceBonusReceipts,
            ], [[
                'type' => GameEventType::ScienceBonusBooksChosen->value,
                'player_id' => $player->id,
            ]], $stateVersionBefore, $lockedGame->version, $result->nextPhase !== GamePhase::ScienceBonus);

            return $lockedGame->refresh();
        });
    }

    /**
     * @param array<string, int> $bookCounts
     * @return list<string>
     */
    private function disciplines(array $bookCounts): array
    {
        $disciplines = [];

        foreach (KnowledgeDiscipline::cases() as $discipline) {
            for ($count = $bookCounts[$discipline->value] ?? 0; $count > 0; $count--) {
                $disciplines[] = $discipline->value;
            }
        }

        return $disciplines;
    }
}
