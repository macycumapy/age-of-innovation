<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Enums\PalaceAbility;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Enums\RoundScoringGoal;
use App\Domain\Game\Enums\RoundScoringTile;
use Illuminate\Validation\ValidationException;

final class ApplyPalaceAction
{
    public function __construct(
        private AdvanceKnowledgeAction $advanceKnowledge,
        private ApplyBuildingBonusesAction $applyBuildingBonuses,
        private CreateBuildingFollowUpInteractionAction $createBuildingFollowUpInteraction,
        private FindEligibleTerraformHexesAction $findEligibleTerraformHexes,
    ) {
    }

    /**
     * @param list<KnowledgeDiscipline> $knowledgeDisciplines
     * @return array{nextActiveUserId: int, victoryPoints: int, bonusCoins: int, gainedPower: int}
     */
    public function execute(
        GameStateData $state,
        GamePlayerStateData $player,
        ?KnowledgeDiscipline $discipline,
        array $knowledgeDisciplines,
        ?string $hexId,
    ): array {
        $palace = PalaceAbility::tryFrom((string) $player->palaceId);

        if (! $palace?->hasSpecialAction() || in_array($palace->specialActionId(), $player->usedSpecialActionIds, true)) {
            throw ValidationException::withMessages(['palace' => 'Действие этого жетона Дворца недоступно.']);
        }

        $result = ['nextActiveUserId' => $player->userId, 'victoryPoints' => 0, 'bonusCoins' => 0, 'gainedPower' => 0];

        match ($palace) {
            PalaceAbility::Palace01 => $player->resources->tools += 2,
            PalaceAbility::Palace02 => $this->grantSpades($state, $player),
            PalaceAbility::Palace03, PalaceAbility::Palace04 => $result = $this->upgradeToGuild($state, $player, $palace, $hexId),
            PalaceAbility::Palace06 => $result = [...$result, ...$this->gainKnowledge($state, $player, $knowledgeDisciplines)],
            PalaceAbility::Palace13 => $this->gainCoinsAndBook($player, $discipline),
            default => null,
        };

        $player->usedSpecialActionIds[] = $palace->specialActionId();
        $state->round->hasTakenMainAction = true;

        return $result;
    }

    private function grantSpades(GameStateData $state, GamePlayerStateData $player): void
    {
        $options = $this->findEligibleTerraformHexes->execute($state, $player, $player->homeland);

        if ($options === []) {
            throw ValidationException::withMessages(['palace' => 'Нет доступной местности для преобразования.']);
        }

        $player->unassignedSpades += 2;
        $state->pendingInteraction = new PendingInteractionData(PendingInteractionType::SpendSpades, $player->playerId, $options, [
            'phase' => GamePhase::Actions->value,
            'spadeCount' => 2,
            'remainingSpades' => 2,
            'targetTerrain' => $player->homeland->value,
        ]);
    }

    /**
     * @param list<KnowledgeDiscipline> $disciplines
     * @return array{victoryPoints: int, gainedPower: int}
     */
    private function gainKnowledge(
        GameStateData $state,
        GamePlayerStateData $player,
        array $disciplines,
    ): array {
        if (count($disciplines) !== 2) {
            throw ValidationException::withMessages(['knowledge_steps' => 'Распределите ровно 2 шага знаний.']);
        }

        $advancedSteps = 0;
        $gainedPower = 0;

        foreach ($disciplines as $discipline) {
            $levelBefore = $player->knowledge->{$discipline->value};
            $gainedPower += $this->advanceKnowledge->execute($state, $player, $discipline, 1);
            $advancedSteps += $player->knowledge->{$discipline->value} - $levelBefore;
        }

        $roundScoringTile = RoundScoringTile::tryFrom((string) $state->round->scoringTileId);
        $victoryPoints = $roundScoringTile?->goal() === RoundScoringGoal::Knowledge ? $advancedSteps : 0;
        $player->victoryPoints += $victoryPoints;

        return ['victoryPoints' => $victoryPoints, 'gainedPower' => $gainedPower];
    }

    private function gainCoinsAndBook(GamePlayerStateData $player, ?KnowledgeDiscipline $discipline): void
    {
        if ($discipline === null) {
            throw ValidationException::withMessages(['discipline' => 'Выберите книгу.']);
        }

        $player->resources->coins += 3;
        $player->resources->books->{$discipline->value}++;
    }

    /** @return array{nextActiveUserId: int, victoryPoints: int, bonusCoins: int, gainedPower: int} */
    private function upgradeToGuild(GameStateData $state, GamePlayerStateData $player, PalaceAbility $palace, ?string $hexId): array
    {
        $hex = collect($state->board->hexes)->firstWhere('id', $hexId);
        $source = $palace === PalaceAbility::Palace03 ? BuildingType::School : BuildingType::Workshop;
        $guildCount = collect($state->board->hexes)->filter(
            static fn (BoardHexStateData $candidate): bool => $candidate->building?->ownerPlayerId === $player->playerId
                && $candidate->building->type === BuildingType::Guild
                && ! $candidate->building->isNeutral,
        )->count();

        if (! $hex instanceof BoardHexStateData || $hex->building?->ownerPlayerId !== $player->playerId
            || $hex->building->isNeutral || $hex->building->type !== $source
            || $guildCount >= BuildingType::Guild->supplyLimit()) {
            throw ValidationException::withMessages(['hex_id' => 'Выберите подходящее своё здание.']);
        }

        $hex->building->type = BuildingType::Guild;
        $bonuses = $this->applyBuildingBonuses->execute($state, $player, $hex, BuildingType::Guild);

        if ($palace === PalaceAbility::Palace03) {
            $player->victoryPoints += 3;
            $player->resources->tools++;
        }

        return [
            'nextActiveUserId' => $this->createBuildingFollowUpInteraction->execute($state, $player, $hex->id, BuildingType::Guild),
            'victoryPoints' => $bonuses['victoryPoints'] + ($palace === PalaceAbility::Palace03 ? 3 : 0),
            'bonusCoins' => $bonuses['coins'],
            'gainedPower' => 0,
        ];
    }
}
