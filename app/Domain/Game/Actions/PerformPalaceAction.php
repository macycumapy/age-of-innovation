<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PerformPalaceAction
{
    public function __construct(private ApplyPalaceAction $applyPalaceAction, private AppendGameHistoryAction $appendGameHistory)
    {
    }

    /** @param list<KnowledgeDiscipline> $knowledgeDisciplines */
    public function execute(
        Game $game,
        User $user,
        ?KnowledgeDiscipline $discipline,
        array $knowledgeDisciplines,
        ?string $hexId,
    ): Game {
        return DB::transaction(function () use ($game, $user, $discipline, $knowledgeDisciplines, $hexId): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $player = $lockedGame->players()->whereBelongsTo($user)->first();

            if ($lockedGame->phase !== GamePhase::Actions || $lockedGame->active_player_id !== $user->id
                || $state->pendingInteraction !== null || ! $player instanceof GamePlayer) {
                throw ValidationException::withMessages(['game' => 'Сейчас нельзя использовать действие Дворца.']);
            }

            $playerState = collect($state->players)->firstWhere('playerId', $player->id);

            if (! $playerState instanceof GamePlayerStateData) {
                throw ValidationException::withMessages(['game' => 'Не найдено состояние игрока.']);
            }

            $before = $lockedGame->version;

            if ($state->turnStartSnapshot === null) {
                $state->turnStartSnapshot = $state->toArray();
                $state->round->turnStartVersion = $before;
            }

            $palaceId = $playerState->palaceId;
            $result = $this->applyPalaceAction->execute(
                $state,
                $playerState,
                $discipline,
                $knowledgeDisciplines,
                $hexId,
            );
            $lockedGame->update(['active_player_id' => $result['nextActiveUserId'], 'state' => $state, 'version' => $before + 1]);
            $this->appendGameHistory->execute($lockedGame, $user, GameActionType::SpecialAction, [
                'palace' => $palaceId,
                'discipline' => $discipline?->value,
                'knowledge_disciplines' => array_map(
                    static fn (KnowledgeDiscipline $knowledgeDiscipline): string => $knowledgeDiscipline->value,
                    $knowledgeDisciplines,
                ),
                'hex_id' => $hexId,
                'victory_points' => $result['victoryPoints'],
                'bonus_coins' => $result['bonusCoins'],
            ], [[
                'type' => 'palace_action_used',
                'player_id' => $player->id,
                'palace' => $palaceId,
            ]], $before, $lockedGame->version);

            return $lockedGame->refresh();
        });
    }
}
