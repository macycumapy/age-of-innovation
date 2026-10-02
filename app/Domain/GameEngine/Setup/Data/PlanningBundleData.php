<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Setup\Data;

use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
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
