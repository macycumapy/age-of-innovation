<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\PlayerAbilities\Services;

use App\Domain\GameEngine\Board\Actions\FindEligibleTerraformHexesAction;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\PlayerAbilities\Data\PalaceActionOptionData;
use App\Domain\GameEngine\PlayerAbilities\Enums\PalaceAbility;
use App\Domain\GameEngine\Research\Enums\KnowledgeDiscipline;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;

final class PalaceActionOptionFinder
{
    public function __construct(private FindEligibleTerraformHexesAction $findEligibleTerraformHexes)
    {
    }

    /** @return list<PalaceActionOptionData> */
    public function execute(GameStateData $state, GamePlayerStateData $player): array
    {
        $palace = PalaceAbility::tryFrom((string) $player->palaceId);
        if (! $state->round->phase->isActionPhase()
            || $state->pendingInteraction !== null
            || $state->round->hasTakenMainAction
            || ! $palace?->hasSpecialAction()
            || in_array($palace->specialActionId(), $player->usedSpecialActionIds, true)) {
            return [];
        }

        return match ($palace) {
            PalaceAbility::Palace01 => [new PalaceActionOptionData($palace)],
            PalaceAbility::Palace02 => $this->findEligibleTerraformHexes->execute($state, $player, $player->homeland) === []
                ? [] : [new PalaceActionOptionData($palace)],
            PalaceAbility::Palace03 => $this->buildingOptions($state, $player, $palace, BuildingType::School),
            PalaceAbility::Palace04 => $this->buildingOptions($state, $player, $palace, BuildingType::Workshop),
            PalaceAbility::Palace06 => $this->knowledgeOptions($palace),
            PalaceAbility::Palace13 => array_map(
                static fn (KnowledgeDiscipline $discipline): PalaceActionOptionData => new PalaceActionOptionData($palace, $discipline),
                KnowledgeDiscipline::cases(),
            ),
            default => [],
        };
    }

    /** @return list<PalaceActionOptionData> */
    private function buildingOptions(
        GameStateData $state,
        GamePlayerStateData $player,
        PalaceAbility $palace,
        BuildingType $source,
    ): array {
        $guildCount = count(array_filter(
            $state->board->hexes,
            static fn (BoardHexStateData $hex): bool => $hex->building?->ownerPlayerId === $player->playerId
                && $hex->building->type === BuildingType::Guild
                && ! $hex->building->isNeutral,
        ));
        if ($guildCount >= BuildingType::Guild->supplyLimit()) {
            return [];
        }

        return array_values(array_map(
            static fn (BoardHexStateData $hex): PalaceActionOptionData => new PalaceActionOptionData($palace, hexId: $hex->id),
            array_filter(
                $state->board->hexes,
                static fn (BoardHexStateData $hex): bool => $hex->building?->ownerPlayerId === $player->playerId
                    && $hex->building->type === $source
                    && ! $hex->building->isNeutral,
            ),
        ));
    }

    /** @return list<PalaceActionOptionData> */
    private function knowledgeOptions(PalaceAbility $palace): array
    {
        $options = [];
        $disciplines = KnowledgeDiscipline::cases();
        foreach ($disciplines as $firstIndex => $first) {
            foreach (array_slice($disciplines, $firstIndex) as $second) {
                $options[] = new PalaceActionOptionData($palace, knowledgeDisciplines: [$first, $second]);
            }
        }

        return $options;
    }
}
