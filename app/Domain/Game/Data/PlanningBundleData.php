<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\RoundBonus;
use App\Domain\Game\Enums\TerrainType;
use Spatie\LaravelData\Data;

/**
 * @property TerrainType $homeland Родная местность и соответствующий планшет планирования.
 * @property Faction $faction Сообщество, случайно связанное с планшетом.
 * @property RoundBonus $roundBonus Стартовый бонус раунда в комплекте.
 */
final class PlanningBundleData extends Data
{
    public function __construct(
        public TerrainType $homeland,
        public Faction $faction,
        public RoundBonus $roundBonus,
    ) {
    }
}
