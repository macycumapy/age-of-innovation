<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\PlayerAbilities\Services;

use App\Domain\GameEngine\Board\Actions\FindEligibleBridgePairsAction;
use App\Domain\GameEngine\Board\Services\BridgeSupply;
use App\Domain\GameEngine\Enums\GameActionOptionType;
use App\Domain\GameEngine\PlayerAbilities\Data\PlayerSpecialActionOptionData;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Research\Enums\Competency;
use App\Domain\GameEngine\Research\Enums\KnowledgeDiscipline;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;

final class PlayerSpecialActionOptionFinder
{
    public function __construct(
        private BridgeSupply $bridgeSupply,
        private FindEligibleBridgePairsAction $findEligibleBridgePairs,
    ) {
    }

    /** @return list<PlayerSpecialActionOptionData> */
    public function execute(GameStateData $state, GamePlayerStateData $player): array
    {
        if (! $state->round->phase->isActionPhase()
            || $state->pendingInteraction !== null
            || $state->round->hasTakenMainAction) {
            return [];
        }

        return [
            ...$this->factionOptions($state, $player),
            ...$this->competencyOptions($player),
            ...$this->roundBonusOptions($state, $player),
        ];
    }

    /** @return list<PlayerSpecialActionOptionData> */
    private function factionOptions(GameStateData $state, GamePlayerStateData $player): array
    {
        $actionId = $player->faction->specialActionId();
        if (! $player->faction->hasSpecialAction()
            || ($player->faction === Faction::Moles && (
                $player->resources->tools < 1
                || $this->bridgeSupply->remaining($state, $player) === 0
                || $this->findEligibleBridgePairs->execute($state, $player->playerId, true) === []
            ))
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
    private function roundBonusOptions(GameStateData $state, GamePlayerStateData $player): array
    {
        if (! $player->roundBonus->hasAvailableSpecialAction()
            || in_array($player->roundBonus->value, $player->usedSpecialActionIds, true)
            || ($player->roundBonus === RoundBonus::Bridge && (
                $this->bridgeSupply->remaining($state, $player) === 0
                || $this->findEligibleBridgePairs->execute($state, $player->playerId) === []
            ))) {
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
