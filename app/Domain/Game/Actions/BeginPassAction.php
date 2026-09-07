<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Data\RoundBonusOfferData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Enums\RoundBonus;
use App\Models\GamePlayer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

final class BeginPassAction
{
    public function __construct(
        private ApplyPassBonusesAction $applyPassBonuses,
        private AdvanceKnowledgeAction $advanceKnowledge,
        private CompletePassTurnAction $completePassTurn,
    ) {
    }

    /**
     * @param Collection<int, GamePlayer> $players
     * @param list<KnowledgeDiscipline> $knowledgeDisciplines
     * @return array<string, mixed>
     */
    public function execute(
        GameStateData $state,
        GamePlayerStateData $player,
        Collection $players,
        array $knowledgeDisciplines,
    ): array {
        if (in_array($player->playerId, $state->passedPlayerIds, true)) {
            throw ValidationException::withMessages(['game' => 'Игрок уже спасовал в этом раунде.']);
        }

        $this->applySchoolKnowledgeSteps($state, $player, $knowledgeDisciplines);
        $bonuses = $this->applyPassBonuses->execute($state, $player);
        $isFinalRound = $state->round->number >= 6;
        $conversion = $isFinalRound ? $this->convertFinalResources($player) : null;

        if (($conversion['victoryPoints'] ?? 0) > 0) {
            $bonuses['victoryPoints'] += $conversion['victoryPoints'];
            $bonuses['sources'][] = ['source' => 'end_game_resources', 'id' => 'coins', 'points' => $conversion['victoryPoints']];
        }

        if ($state->passedPlayerIds === []) {
            $state->round->passOrder = [];
        }

        $state->passedPlayerIds[] = $player->playerId;
        $state->round->passOrder[] = $player->playerId;
        $state->turnStartSnapshot = null;
        $state->round->turnStartVersion = null;
        $state->round->hasTakenMainAction = false;
        $state->round->isCurrentTurnIrrevocable = true;

        $completion = null;

        if ($isFinalRound) {
            if ($state->setupPool !== null) {
                $state->setupPool->availableRoundBonuses[] = new RoundBonusOfferData($player->roundBonus, 0);
            }
            $completion = $this->completePassTurn->execute($state, $player->playerId, $players);
        } else {
            $state->pendingInteraction = new PendingInteractionData(
                PendingInteractionType::ChooseRoundBonus,
                $player->playerId,
                array_map(
                    static fn (RoundBonusOfferData $offer): string => $offer->roundBonus->value,
                    $state->setupPool?->availableRoundBonuses ?? [],
                ),
            );
        }

        return [
            'victoryPoints' => $bonuses['victoryPoints'],
            'scoringSources' => $bonuses['sources'],
            'passOrder' => count($state->round->passOrder),
            'finalResourceConversion' => $conversion,
            'completion' => $completion,
        ];
    }

    /** @param list<KnowledgeDiscipline> $disciplines */
    private function applySchoolKnowledgeSteps(GameStateData $state, GamePlayerStateData $player, array $disciplines): void
    {
        $schoolCount = $player->roundBonus === RoundBonus::PassSchool ? count(array_filter(
            $state->board->hexes,
            static fn (BoardHexStateData $hex): bool => $hex->building?->ownerPlayerId === $player->playerId
                && ! $hex->building->isNeutral
                && $hex->building->type === BuildingType::School,
        )) : 0;

        if (count($disciplines) !== $schoolCount) {
            throw ValidationException::withMessages(['knowledge_counts' => 'Распределите все шаги знаний за школы.']);
        }

        foreach ($disciplines as $discipline) {
            $this->advanceKnowledge->execute($state, $player, $discipline, 1);
        }
    }

    /** @return array<string, int>|null */
    private function convertFinalResources(GamePlayerStateData $player): ?array
    {
        $resources = $player->resources;
        $moved = intdiv($resources->power->bowlTwo, 2);
        $books = $resources->books;
        $converted = $resources->tools + $resources->scholars + $books->banking + $books->law
            + $books->engineering + $books->medicine + $books->unassigned + $resources->power->bowlThree + $moved;
        $total = $resources->coins + $converted;

        if ($total < 5) {
            return null;
        }

        $spent = $moved * 2;
        $resources->power->bowlTwo -= $spent;
        $resources->tools = 0;
        $resources->scholars = 0;
        $books->banking = $books->law = $books->engineering = $books->medicine = $books->unassigned = 0;
        $resources->power->bowlOne += $resources->power->bowlThree + $moved;
        $resources->power->bowlThree = 0;
        $victoryPoints = intdiv($total, 5);
        $resources->coins = $total % 5;
        $player->victoryPoints += $victoryPoints;

        return ['bowlTwoSpent' => $spent, 'movedToBowlThree' => $moved, 'convertedToCoins' => $converted, 'totalCoins' => $total, 'victoryPoints' => $victoryPoints, 'remainingCoins' => $resources->coins];
    }
}
