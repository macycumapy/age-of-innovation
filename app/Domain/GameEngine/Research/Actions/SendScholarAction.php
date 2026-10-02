<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Research\Actions;

use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\Enums\GameEventType;
use App\Domain\GameEngine\History\Actions\AppendGameHistoryAction;
use App\Domain\GameEngine\Research\Data\SendScholarOptionData;
use App\Domain\GameEngine\Research\Enums\KnowledgeDiscipline;
use App\Domain\GameEngine\Research\Services\SendScholarOptionFinder;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SendScholarAction
{
    public function __construct(
        private SendScholarOptionFinder $optionFinder,
        private ApplySendScholarAction $applySendScholar,
        private AppendGameHistoryAction $appendGameHistory,
    ) {
    }

    public function execute(Game $game, GamePlayer $player, KnowledgeDiscipline $discipline, bool $place): Game
    {
        return DB::transaction(function () use ($game, $player, $discipline, $place): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $playerState = collect($state->players)->firstWhere('playerId', $player->id);
            if (! $lockedGame->phase->isActionPhase()
                || $player->game_id !== $lockedGame->id
                || ! $lockedGame->isActivePlayer($player)
                || ! $playerState instanceof GamePlayerStateData) {
                throw ValidationException::withMessages(['scholar' => 'Сейчас нельзя отправить учёного в эту дисциплину.']);
            }

            $option = collect($this->optionFinder->execute($state, $playerState))->first(
                static fn (SendScholarOptionData $candidate): bool => $candidate->discipline === $discipline
                    && $candidate->place === $place,
            );

            if (! $option instanceof SendScholarOptionData) {
                throw ValidationException::withMessages(['scholar' => 'Сейчас нельзя отправить учёного в эту дисциплину.']);
            }

            $stateVersionBefore = $lockedGame->version;

            if ($state->turnStartSnapshot === null) {
                $state->turnStartSnapshot = $state->toArray();
                $state->round->turnStartVersion = $stateVersionBefore;
            }

            $result = $this->applySendScholar->execute($state, $playerState, $option);
            $lockedGame->update([
                'state' => $state,
                'version' => $lockedGame->version + 1,
            ]);
            $this->appendGameHistory->execute(
                $lockedGame,
                $player,
                GameActionType::SendScholar,
                [
                    'discipline' => $discipline->value,
                    'placed' => $place,
                    'slot_index' => $result->slotIndex,
                    'steps' => $result->advancedSteps,
                    'victory_points' => $result->victoryPoints,
                    'gained_power' => $result->gainedPower,
                ],
                [[
                    'type' => GameEventType::ScholarSent->value,
                    'player_id' => $player->id,
                    'discipline' => $discipline->value,
                    'placed' => $place,
                    'steps' => $result->advancedSteps,
                ]],
                $stateVersionBefore,
                $lockedGame->version,
            );

            return $lockedGame->refresh();
        });
    }
}
