<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Setup\Actions;

use App\Domain\GameEngine\Board\Enums\MapVariant;
use App\Domain\GameEngine\Research\Data\KnowledgeStateData;
use App\Domain\GameEngine\Research\Data\NeutralKnowledgeStateData;
use App\Domain\GameEngine\Research\Enums\KnowledgeDiscipline;
use App\Domain\GameEngine\Scoring\Enums\RoundScoringTile;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;

final class InitializeNeutralKnowledgeFactionAction
{
    public function execute(GameStateData $state): void
    {
        if ($state->neutralKnowledge !== null
            || $state->board->variant !== MapVariant::OneToThreePlayers
            || count($state->players) !== 2) {
            return;
        }

        $usedColors = array_map(
            static fn (GamePlayerStateData $player): PlayerColor => $player->color,
            $state->players,
        );
        $neutralColor = collect(PlayerColor::cases())->first(
            static fn (PlayerColor $color): bool => ! in_array($color, $usedColors, true),
        );

        if (! $neutralColor instanceof PlayerColor) {
            return;
        }

        $knowledge = new KnowledgeStateData(
            banking: 2,
            law: 2,
            engineering: 2,
            medicine: 2,
        );

        $roundScoringTiles = $state->setupPool === null
            ? []
            : $state->setupPool->roundScoringTiles;

        foreach (array_slice($roundScoringTiles, 0, 5) as $roundScoringTile) {
            $roundScoringTile = $roundScoringTile instanceof RoundScoringTile
                ? $roundScoringTile
                : RoundScoringTile::from($roundScoringTile);
            $discipline = $roundScoringTile->knowledgeDiscipline();
            $knowledge->{$discipline->value} = min(
                12,
                $knowledge->{$discipline->value} + $roundScoringTile->scienceBonusLevelInterval(),
            );
        }

        $state->neutralKnowledge = new NeutralKnowledgeStateData(
            color: $neutralColor,
            knowledge: $knowledge,
            scholarDisciplineIds: array_column(KnowledgeDiscipline::cases(), 'value'),
            scholarSlotIndex: 1,
        );
    }
}
