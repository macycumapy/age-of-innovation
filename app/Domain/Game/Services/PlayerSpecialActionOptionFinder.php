<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PlayerSpecialActionOptionData;
use App\Domain\Game\Enums\Competency;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\GameActionOptionType;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Enums\RoundBonus;

final class PlayerSpecialActionOptionFinder
{
    /** @return list<PlayerSpecialActionOptionData> */
    public function execute(GameStateData $state, GamePlayerStateData $player): array
    {
        if (! $state->round->phase->isActionPhase()
            || $state->pendingInteraction !== null
            || $state->round->hasTakenMainAction) {
            return [];
        }

        return [
            ...$this->factionOptions($player),
            ...$this->competencyOptions($player),
            ...$this->roundBonusOptions($player),
        ];
    }

    /** @return list<PlayerSpecialActionOptionData> */
    private function factionOptions(GamePlayerStateData $player): array
    {
        $actionId = $player->faction->specialActionId();
        if (! $player->faction->hasSpecialAction()
            || ($player->faction === Faction::Moles && $player->resources->tools < 1)
            || ($player->faction !== Faction::Moles && in_array($actionId, $player->usedSpecialActionIds, true))) {
            return [];
        }

        return $player->faction === Faction::Philosophers
            ? array_map(
                static fn (KnowledgeDiscipline $discipline): PlayerSpecialActionOptionData => new PlayerSpecialActionOptionData(
                    GameActionOptionType::UseFactionAction,
                    $discipline,
                ),
                KnowledgeDiscipline::cases(),
            )
            : [new PlayerSpecialActionOptionData(GameActionOptionType::UseFactionAction)];
    }

    /** @return list<PlayerSpecialActionOptionData> */
    private function competencyOptions(GamePlayerStateData $player): array
    {
        return in_array(Competency::Competency07->value, $player->competencyIds, true)
            && ! in_array(Competency::Competency07->value, $player->usedSpecialActionIds, true)
                ? [new PlayerSpecialActionOptionData(GameActionOptionType::UseCompetencyAction)]
                : [];
    }

    /** @return list<PlayerSpecialActionOptionData> */
    private function roundBonusOptions(GamePlayerStateData $player): array
    {
        if (! $player->roundBonus->hasAvailableSpecialAction()
            || in_array($player->roundBonus->value, $player->usedSpecialActionIds, true)) {
            return [];
        }

        return $player->roundBonus === RoundBonus::Knowledge
            ? array_map(
                static fn (KnowledgeDiscipline $discipline): PlayerSpecialActionOptionData => new PlayerSpecialActionOptionData(
                    GameActionOptionType::UseRoundBonusAction,
                    $discipline,
                ),
                KnowledgeDiscipline::cases(),
            )
            : [new PlayerSpecialActionOptionData(GameActionOptionType::UseRoundBonusAction)];
    }
}
