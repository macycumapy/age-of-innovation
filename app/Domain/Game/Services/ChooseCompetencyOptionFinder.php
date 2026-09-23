<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Data\ChooseCompetencyOptionData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Enums\Competency;
use App\Domain\Game\Enums\PendingInteractionType;

final class ChooseCompetencyOptionFinder
{
    /** @return list<ChooseCompetencyOptionData> */
    public function execute(GameStateData $state, GamePlayerStateData $player): array
    {
        $interaction = $state->pendingInteraction;
        $reason = $interaction?->context['reason'] ?? null;
        if ($interaction?->type !== PendingInteractionType::ChooseCompetency
            || $interaction->playerId !== $player->playerId
            || ! in_array($reason, ['building', 'innovation'], true)) {
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
