<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Actions\ApplyCompetencyAction;
use App\Domain\Game\Actions\ApplyFactionAction;
use App\Domain\Game\Actions\ApplyRoundBonusAction;
use App\Domain\Game\Data\GameActionSimulationData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PlayerSpecialActionOptionData;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\GameActionOptionType;
use App\Domain\Game\Enums\RoundBonus;
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
        $simulatedState = GameStateData::from($state->toArray());
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

        return new GameActionSimulationData($simulatedState, $player->userId);
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
