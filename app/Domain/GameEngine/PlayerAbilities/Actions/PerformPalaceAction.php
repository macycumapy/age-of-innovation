<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\PlayerAbilities\Actions;

use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\Enums\GameEventType;
use App\Domain\GameEngine\History\Actions\AppendGameHistoryAction;
use App\Domain\GameEngine\Research\Enums\KnowledgeDiscipline;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Models\Game;
use App\Models\GamePlayer;
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
        GamePlayer $player,
        ?KnowledgeDiscipline $discipline,
        array $knowledgeDisciplines,
        ?string $hexId,
    ): Game {
        return DB::transaction(function () use ($game, $player, $discipline, $knowledgeDisciplines, $hexId): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;

            if (! $lockedGame->phase->isActionPhase() || ! $lockedGame->isActivePlayer($player)
                || $state->pendingInteraction !== null) {
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
            $lockedGame->update(['active_game_player_id' => $result['nextActivePlayerId'], 'state' => $state, 'version' => $before + 1]);
            $this->appendGameHistory->execute($lockedGame, $player, GameActionType::SpecialAction, [
                'palace' => $palaceId,
                'discipline' => $discipline?->value,
                'knowledge_disciplines' => array_map(
                    static fn (KnowledgeDiscipline $knowledgeDiscipline): string => $knowledgeDiscipline->value,
                    $knowledgeDisciplines,
                ),
                'hex_id' => $hexId,
                'victory_points' => $result['victoryPoints'],
                'bonus_coins' => $result['bonusCoins'],
                'gained_power' => $result['gainedPower'],
            ], [[
                'type' => GameEventType::PalaceActionUsed->value,
                'player_id' => $player->id,
                'palace' => $palaceId,
            ]], $before, $lockedGame->version);

            return $lockedGame->refresh();
        });
    }
}
