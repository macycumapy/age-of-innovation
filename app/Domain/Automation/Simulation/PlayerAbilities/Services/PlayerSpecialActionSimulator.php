<?php

declare(strict_types=1);

namespace App\Domain\Automation\Simulation\PlayerAbilities\Services;

use App\Domain\Automation\Data\GameActionSimulationData;
use App\Domain\GameEngine\Enums\GameActionOptionType;
use App\Domain\GameEngine\PlayerAbilities\Actions\ApplyCompetencyAction;
use App\Domain\GameEngine\PlayerAbilities\Actions\ApplyFactionAction;
use App\Domain\GameEngine\PlayerAbilities\Actions\ApplyRoundBonusAction;
use App\Domain\GameEngine\PlayerAbilities\Data\PlayerSpecialActionOptionData;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\PlayerAbilities\Services\PlayerSpecialActionOptionFinder;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use InvalidArgumentException;

final class PlayerSpecialActionSimulator
{
    public function __construct(
        private PlayerSpecialActionOptionFinder $optionFinder,
        private ApplyFactionAction $applyFactionAction,
        private ApplyCompetencyAction $applyCompetencyAction,
        private ApplyRoundBonusAction $applyRoundBonusAction,
    ) {
    }

    public function execute(GameStateData $state, int $playerId, PlayerSpecialActionOptionData $option): GameActionSimulationData
    {
        $simulatedState = $state->deepCopy();
        $player = collect($simulatedState->players)->firstWhere('playerId', $playerId);
        if (! $player instanceof GamePlayerStateData) {
            throw new InvalidArgumentException('Не найдено состояние игрока для симуляции.');
        }

        $isAvailable = collect($this->optionFinder->execute($simulatedState, $player))->contains(
            static fn (PlayerSpecialActionOptionData $candidate): bool => $candidate->toArray() === $option->toArray(),
        );
        if (! $isAvailable) {
            throw new InvalidArgumentException('Недопустимое особое действие игрока.');
        }

        match ($option->actionType) {
            GameActionOptionType::UseFactionAction => $this->applyFaction($simulatedState, $player, $option),
            GameActionOptionType::UseCompetencyAction => $this->applyCompetency($simulatedState, $player),
            GameActionOptionType::UseRoundBonusAction => $this->applyRoundBonus($simulatedState, $player, $option),
            default => throw new InvalidArgumentException('Неизвестное особое действие игрока.'),
        };

        return new GameActionSimulationData($simulatedState, $player->playerId);
    }

    private function applyFaction(GameStateData $state, GamePlayerStateData $player, PlayerSpecialActionOptionData $option): void
    {
        $this->applyFactionAction->execute($state, $player, $option->discipline);
        if ($player->faction === Faction::Moles && $state->pendingInteraction !== null) {
            $state->pendingInteraction->context['source'] = 'faction';
        }
    }

    private function applyCompetency(GameStateData $state, GamePlayerStateData $player): void
    {
        $this->applyCompetencyAction->execute($player);
        $state->round->hasTakenMainAction = true;
    }

    private function applyRoundBonus(GameStateData $state, GamePlayerStateData $player, PlayerSpecialActionOptionData $option): void
    {
        $this->applyRoundBonusAction->execute($state, $player, $option->discipline);
        if ($player->roundBonus === RoundBonus::Bridge && $state->pendingInteraction !== null) {
            $state->pendingInteraction->context['source'] = 'round_bonus';
        }
    }
}
