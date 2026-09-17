<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\RoundBonusOfferData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Enums\RoundBonus;
use App\Models\GamePlayer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

final class ApplyPassAction
{
    public function __construct(
        private ApplyPassBonusesAction $applyPassBonuses,
        private ResolveScienceBonusPhaseAction $resolveScienceBonusPhase,
        private AdvanceKnowledgeAction $advanceKnowledge,
    ) {
    }

    /**
     * @param Collection<int, GamePlayer> $players
     * @param list<KnowledgeDiscipline>|null $knowledgeDisciplines Null only replays legacy history without this effect.
     * @return array{nextActiveUserId: int|null, phase: GamePhase, bonusCoins: int, victoryPoints: int, scoringSources: list<array{source: string, id: string, points: int}>, passOrder: int, nextRoundStarted: bool, incomeReceipts: list<array{player_id: int, tools: int, coins: int, scholars: int, power: int, books: int, knowledge_steps: int}>, finalScoring: list<array{playerId: int, victoryPoints: int, sources: list<array{source: string, id: string, value: int, rank: int, points: int}>}>, finalResourceConversion: array{bowlTwoSpent: int, movedToBowlThree: int, convertedToCoins: int, totalCoins: int, victoryPoints: int, remainingCoins: int}|null}
     */
    public function execute(
        GameStateData $state,
        GamePlayerStateData $player,
        ?RoundBonus $roundBonus,
        Collection $players,
        ?array $knowledgeDisciplines = [],
    ): array {
        if (in_array($player->playerId, $state->passedPlayerIds, true)) {
            throw ValidationException::withMessages(['round_bonus' => 'Игрок уже спасовал в этом раунде.']);
        }

        $isFinalRound = $state->round->number >= 6;
        $offerIndex = $isFinalRound ? null : collect($state->setupPool?->availableRoundBonuses ?? [])
            ->search(static fn (RoundBonusOfferData $offer): bool => $offer->roundBonus === $roundBonus);

        if ($state->setupPool === null || (! $isFinalRound && ! is_int($offerIndex))) {
            throw ValidationException::withMessages(['round_bonus' => 'Выбранный бонус раунда недоступен.']);
        }

        if ($state->passedPlayerIds === []) {
            $state->round->passOrder = [];
        }

        $oldRoundBonus = $player->roundBonus;
        $knowledgeVictoryPoints = $this->applySchoolKnowledgeSteps($state, $player, $knowledgeDisciplines);
        $bonuses = $this->applyPassBonuses->execute($state, $player);

        if ($knowledgeVictoryPoints > 0) {
            $bonuses['victoryPoints'] += $knowledgeVictoryPoints;
            $bonuses['sources'][] = [
                'source' => 'round_scoring',
                'id' => (string) $state->round->scoringTileId,
                'points' => $knowledgeVictoryPoints,
            ];
        }
        $finalResourceConversion = $isFinalRound ? $this->convertFinalResources($player) : null;

        if (($finalResourceConversion['victoryPoints'] ?? 0) > 0) {
            $bonuses['victoryPoints'] += $finalResourceConversion['victoryPoints'];
            $bonuses['sources'][] = [
                'source' => 'end_game_resources',
                'id' => 'coins',
                'points' => $finalResourceConversion['victoryPoints'],
            ];
        }

        $offer = is_int($offerIndex) ? $state->setupPool->availableRoundBonuses[$offerIndex] : null;

        if (is_int($offerIndex)) {
            array_splice($state->setupPool->availableRoundBonuses, $offerIndex, 1);
        }

        $state->setupPool->availableRoundBonuses[] = new RoundBonusOfferData($oldRoundBonus, 0);

        if ($offer instanceof RoundBonusOfferData) {
            $player->roundBonus = $offer->roundBonus;
            $player->resources->coins += $offer->coins;
        }

        $bonusCoins = $offer?->coins ?? 0;
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
                'bonusCoins' => $bonusCoins,
                'victoryPoints' => $bonuses['victoryPoints'],
                'scoringSources' => $bonuses['sources'],
                'passOrder' => $passOrder,
                'nextRoundStarted' => false,
                'incomeReceipts' => [],
                'finalScoring' => [],
                'finalResourceConversion' => $finalResourceConversion,
            ];
        }

        $state->turnOrder = $state->passedPlayerIds;
        $state->passedPlayerIds = [];

        $state->round->phase = GamePhase::ScienceBonus;
        $state->round->scienceBonusTurnIndex = 0;
        [$nextPlayer, $phase, $incomeReceipts, $finalScoring] = $this->resolveScienceBonusPhase->execute($state, $players);

        return [
            'nextActiveUserId' => $nextPlayer?->user_id,
            'phase' => $phase,
            'bonusCoins' => $bonusCoins,
            'victoryPoints' => $bonuses['victoryPoints'],
            'scoringSources' => $bonuses['sources'],
            'passOrder' => $passOrder,
            'nextRoundStarted' => $phase !== GamePhase::ScienceBonus,
            'incomeReceipts' => $incomeReceipts,
            'finalScoring' => $finalScoring,
            'finalResourceConversion' => $finalResourceConversion,
        ];
    }

    /** @param list<KnowledgeDiscipline>|null $knowledgeDisciplines */
    private function applySchoolKnowledgeSteps(
        GameStateData $state,
        GamePlayerStateData $player,
        ?array $knowledgeDisciplines,
    ): int {
        if ($knowledgeDisciplines === null) {
            return 0;
        }

        $schoolCount = $player->roundBonus === RoundBonus::PassSchool
            ? count(array_filter(
                $state->board->hexes,
                static fn (BoardHexStateData $hex): bool => $hex->building?->ownerPlayerId === $player->playerId
                    && ! $hex->building->isNeutral
                    && $hex->building->type === BuildingType::School,
            ))
            : 0;

        if (count($knowledgeDisciplines) !== $schoolCount) {
            throw ValidationException::withMessages([
                'knowledge_counts' => 'Распределите все шаги знаний за школы.',
            ]);
        }

        $victoryPoints = 0;

        foreach ($knowledgeDisciplines as $discipline) {
            $knowledgeAdvance = $this->advanceKnowledge->execute($state, $player, $discipline, 1);
            $victoryPoints += $knowledgeAdvance->victoryPoints;
        }

        $player->victoryPoints += $victoryPoints;

        return $victoryPoints;
    }

    /**
     * @return array{bowlTwoSpent: int, movedToBowlThree: int, convertedToCoins: int, totalCoins: int, victoryPoints: int, remainingCoins: int}|null
     */
    private function convertFinalResources(GamePlayerStateData $player): ?array
    {
        $resources = $player->resources;
        $movedToBowlThree = intdiv($resources->power->bowlTwo, 2);
        $books = $resources->books;
        $convertedToCoins = $resources->tools
            + $resources->scholars
            + $books->banking
            + $books->law
            + $books->engineering
            + $books->medicine
            + $books->unassigned
            + $resources->power->bowlThree
            + $movedToBowlThree;
        $totalCoins = $resources->coins + $convertedToCoins;

        if ($totalCoins < 5) {
            return null;
        }

        $bowlTwoSpent = $movedToBowlThree * 2;
        $resources->power->bowlTwo -= $bowlTwoSpent;
        $resources->power->bowlThree += $movedToBowlThree;

        $resources->tools = 0;
        $resources->scholars = 0;
        $books->banking = 0;
        $books->law = 0;
        $books->engineering = 0;
        $books->medicine = 0;
        $books->unassigned = 0;
        $resources->power->bowlOne += $resources->power->bowlThree;
        $resources->power->bowlThree = 0;
        $resources->coins += $convertedToCoins;

        $victoryPoints = intdiv($resources->coins, 5);
        $resources->coins %= 5;
        $player->victoryPoints += $victoryPoints;

        return [
            'bowlTwoSpent' => $bowlTwoSpent,
            'movedToBowlThree' => $movedToBowlThree,
            'convertedToCoins' => $convertedToCoins,
            'totalCoins' => $totalCoins,
            'victoryPoints' => $victoryPoints,
            'remainingCoins' => $resources->coins,
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
