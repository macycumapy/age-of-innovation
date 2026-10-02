<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Research\Actions;

use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\Interactions\Data\PendingInteractionData;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\Research\Data\DevelopmentAdvancementOptionData;
use App\Domain\GameEngine\Research\Data\DevelopmentAdvancementResultData;
use App\Domain\GameEngine\Research\Services\DevelopmentAdvancementOptionFinder;
use App\Domain\GameEngine\Scoring\Actions\ApplyDevelopmentTrackRoundScoringAction;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use Illuminate\Validation\ValidationException;

final class ApplyDevelopmentAdvancementAction
{
    public function __construct(
        private DevelopmentAdvancementOptionFinder $optionFinder,
        private AdvanceDevelopmentTrackAction $advanceDevelopmentTrack,
        private ApplyDevelopmentTrackRoundScoringAction $applyDevelopmentTrackRoundScoring,
    ) {
    }

    public function execute(
        GameStateData $state,
        GamePlayerStateData $player,
        DevelopmentAdvancementOptionData $option,
    ): DevelopmentAdvancementResultData {
        $matchingOption = collect($this->optionFinder->execute($state, $player))->first(
            static fn (DevelopmentAdvancementOptionData $candidate): bool => $candidate->action === $option->action,
        );

        if (! $matchingOption instanceof DevelopmentAdvancementOptionData) {
            throw ValidationException::withMessages([
                $option->action === GameActionType::AdvanceShipping ? 'shipping' : 'terraforming'
                    => 'Сейчас нельзя повысить выбранный уровень развития.',
            ]);
        }

        $player->resources->tools -= $matchingOption->tools;
        $player->resources->coins -= $matchingOption->coins;
        $player->resources->scholars -= $matchingOption->scholars;
        $reward = match ($matchingOption->action) {
            GameActionType::AdvanceShipping => $this->advanceDevelopmentTrack->advanceShipping($player),
            GameActionType::AdvanceTerraforming => $this->advanceDevelopmentTrack->advanceTerraforming($player),
            default => throw ValidationException::withMessages(['development' => 'Неизвестный трек развития.']),
        };
        $reward['victoryPoints'] += $this->applyDevelopmentTrackRoundScoring->execute(
            $state,
            $player,
            $reward['steps'],
        );
        $state->round->hasTakenMainAction = true;

        if ($reward['books'] > 0) {
            $state->pendingInteraction = new PendingInteractionData(
                $matchingOption->action === GameActionType::AdvanceShipping
                    ? PendingInteractionType::ChooseShippingBooks
                    : PendingInteractionType::ChooseTerraformingBooks,
                $player->playerId,
                context: ['bookCount' => $reward['books']],
            );
        }

        return new DevelopmentAdvancementResultData(...$reward);
    }
}
