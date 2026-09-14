<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\KnowledgeStateData;
use App\Domain\Game\Data\NeutralKnowledgeStateData;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Enums\MapVariant;
use App\Domain\Game\Enums\PlayerColor;
use App\Domain\Game\Enums\RoundScoringTile;

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
