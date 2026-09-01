<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\RoundBonusOfferData;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\RoundBonus;
use App\Models\GamePlayer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

final class ApplyPassAction
{
    public function __construct(
        private ApplyPassBonusesAction $applyPassBonuses,
        private ResolveScienceBonusPhaseAction $resolveScienceBonusPhase,
    ) {
    }

    /**
     * @param Collection<int, GamePlayer> $players
     * @return array{nextActiveUserId: int|null, phase: GamePhase, bonusCoins: int, victoryPoints: int, scoringSources: list<array{source: string, id: string, points: int}>, passOrder: int, nextRoundStarted: bool, incomeReceipts: list<array{player_id: int, tools: int, coins: int, scholars: int, power: int, books: int, knowledge_steps: int}>}
     */
    public function execute(
        GameStateData $state,
        GamePlayerStateData $player,
        RoundBonus $roundBonus,
        Collection $players,
    ): array {
        if (in_array($player->playerId, $state->passedPlayerIds, true)) {
            throw ValidationException::withMessages(['round_bonus' => 'Игрок уже спасовал в этом раунде.']);
        }

        $offerIndex = collect($state->setupPool?->availableRoundBonuses ?? [])
            ->search(static fn (RoundBonusOfferData $offer): bool => $offer->roundBonus === $roundBonus);

        if (! is_int($offerIndex) || $state->setupPool === null) {
            throw ValidationException::withMessages(['round_bonus' => 'Выбранный бонус раунда недоступен.']);
        }

        if ($state->passedPlayerIds === []) {
            $state->round->passOrder = [];
        }

        $oldRoundBonus = $player->roundBonus;
        $offer = $state->setupPool->availableRoundBonuses[$offerIndex];
        $bonuses = $this->applyPassBonuses->execute($state, $player);
        array_splice($state->setupPool->availableRoundBonuses, $offerIndex, 1);
        $state->setupPool->availableRoundBonuses[] = new RoundBonusOfferData($oldRoundBonus, 0);
        $player->roundBonus = $offer->roundBonus;
        $player->resources->coins += $offer->coins;
        $state->passedPlayerIds[] = $player->playerId;
        $state->round->passOrder[] = $player->playerId;
        $passOrder = count($state->passedPlayerIds);
        $state->turnStartSnapshot = null;
        $state->round->turnStartVersion = null;
        $state->round->hasTakenMainAction = false;
        $state->round->isCurrentTurnIrrevocable = false;

        if (count($state->passedPlayerIds) < count($state->turnOrder)) {
            $nextPlayer = $this->nextUnpassedPlayer($state, $players, $player->playerId);

            return [
                'nextActiveUserId' => $nextPlayer->user_id,
                'phase' => GamePhase::Actions,
                'bonusCoins' => $offer->coins,
                'victoryPoints' => $bonuses['victoryPoints'],
                'scoringSources' => $bonuses['sources'],
                'passOrder' => $passOrder,
                'nextRoundStarted' => false,
                'incomeReceipts' => [],
            ];
        }

        $state->turnOrder = $state->passedPlayerIds;
        $state->passedPlayerIds = [];

        $state->round->phase = GamePhase::ScienceBonus;
        $state->round->scienceBonusTurnIndex = 0;
        [$nextPlayer, $phase, $incomeReceipts] = $this->resolveScienceBonusPhase->execute($state, $players);

        return [
            'nextActiveUserId' => $nextPlayer?->user_id,
            'phase' => $phase,
            'bonusCoins' => $offer->coins,
            'victoryPoints' => $bonuses['victoryPoints'],
            'scoringSources' => $bonuses['sources'],
            'passOrder' => $passOrder,
            'nextRoundStarted' => $phase !== GamePhase::ScienceBonus,
            'incomeReceipts' => $incomeReceipts,
        ];
    }

    /** @param Collection<int, GamePlayer> $players */
    private function nextUnpassedPlayer(GameStateData $state, Collection $players, int $currentPlayerId): GamePlayer
    {
        $currentIndex = array_search($currentPlayerId, $state->turnOrder, true);

        foreach (range(1, count($state->turnOrder)) as $offset) {
            $candidateId = $state->turnOrder[((int) $currentIndex + $offset) % count($state->turnOrder)];

            if (! in_array($candidateId, $state->passedPlayerIds, true)) {
                $candidate = $players->firstWhere('id', $candidateId);

                if ($candidate instanceof GamePlayer) {
                    return $candidate;
                }
            }
        }

        throw ValidationException::withMessages(['game' => 'Не удалось определить следующего игрока.']);
    }
}
