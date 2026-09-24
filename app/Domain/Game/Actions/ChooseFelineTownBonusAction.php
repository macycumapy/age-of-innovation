<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GameEventType;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ChooseFelineTownBonusAction
{
    public function __construct(
        private AppendGameHistoryAction $appendGameHistory,
        private ApplyChooseFelineTownBonusAction $applyChooseFelineTownBonus,
    ) {
    }

    /**
     * @param array<string, int> $bookCounts
     * @param array<string, int> $knowledgeCounts
     */
    public function execute(Game $game, User $user, array $bookCounts, array $knowledgeCounts): Game
    {
        return DB::transaction(function () use ($game, $user, $bookCounts, $knowledgeCounts): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $interaction = $state->pendingInteraction;
            $player = $lockedGame->players()->whereKey($interaction?->playerId)->whereBelongsTo($user)->first();
            $playerState = collect($state->players)->firstWhere('playerId', $player?->id);

            if ($interaction?->type !== PendingInteractionType::ChooseFelineTownBonus
                || ! $player instanceof GamePlayer
                || ! $playerState instanceof GamePlayerStateData) {
                throw ValidationException::withMessages(['book_counts' => 'Нельзя распределить бонус Кошачьих.']);
            }

            $stateVersionBefore = $lockedGame->version;
            $result = $this->applyChooseFelineTownBonus->execute(
                $state,
                $playerState,
                $bookCounts,
                $knowledgeCounts,
                $stateVersionBefore,
            );

            $lockedGame->update([
                'active_game_player_id' => $result->nextActivePlayerId,
                'state' => $state,
                'version' => $lockedGame->version + 1,
            ]);
            $this->appendGameHistory->execute(
                $lockedGame,
                $player,
                GameActionType::ChooseFelineTownBonus,
                [
                    'book_counts' => $bookCounts,
                    'knowledge_counts' => $knowledgeCounts,
                    'victory_points' => $result->victoryPoints,
                    'continue_building_after_power_hex_id' => $result->continueBuildingHexId,
                    'gained_power' => $result->gainedPower,
                ],
                [[
                    'type' => GameEventType::FelineTownBonusChosen->value,
                    'player_id' => $player->id,
                    'book_counts' => $bookCounts,
                    'knowledge_counts' => $knowledgeCounts,
                ]],
                $stateVersionBefore,
                $lockedGame->version,
            );

            return $lockedGame->refresh();
        });
    }
}
