<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Research\Services;

use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\Research\Data\ChooseCompetencyOptionData;
use App\Domain\GameEngine\Research\Enums\Competency;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;

final class ChooseCompetencyOptionFinder
{
    /** @return list<ChooseCompetencyOptionData> */
    public function execute(GameStateData $state, GamePlayerStateData $player): array
    {
        $interaction = $state->pendingInteraction;
        $reason = $interaction?->context['reason'] ?? null;
        $isStartingCompetency = $state->round->phase === GamePhase::Setup
            && $interaction?->playerId === $player->playerId
            && in_array($player->faction, [Faction::Monks, Faction::Inventors], true)
            && $reason === null;

        if ($interaction?->type !== PendingInteractionType::ChooseCompetency
            || $interaction->playerId !== $player->playerId
            || (! $isStartingCompetency && ! in_array($reason, ['building', 'innovation'], true))) {
            return [];
        }

        $options = [];
        foreach ($interaction->optionIds as $optionId) {
            $competency = is_string($optionId) ? Competency::tryFrom($optionId) : null;
            if ($competency !== null && ! in_array($competency->value, $player->competencyIds, true)) {
                $options[] = new ChooseCompetencyOptionData($competency);
            }
        }

        return $options;
    }
}
