<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Setup\Data;

use App\Domain\GameEngine\Board\Enums\MapVariant;
use App\Domain\GameEngine\Economy\Enums\BookAction;
use App\Domain\GameEngine\PlayerAbilities\Data\RoundBonusOfferData;
use App\Domain\GameEngine\PlayerAbilities\Enums\PalaceAbility;
use App\Domain\GameEngine\Research\Enums\Competency;
use App\Domain\GameEngine\Research\Enums\Innovation;
use App\Domain\GameEngine\Scoring\Enums\FinalRoundScoringTile;
use App\Domain\GameEngine\Scoring\Enums\RoundScoringTile;
use App\Domain\GameEngine\Scoring\Enums\TwoPlayerTerritoryScore;
use App\Domain\GameEngine\Towns\Enums\TownTile;
use Spatie\LaravelData\Data;

/**
 * @property int $playerCount Число участников партии.
 * @property MapVariant $mapVariant Сторона карты для партии.
 * @property int $firstPlayerIndex Индекс случайно выбранного первого игрока.
 * @property list<RoundScoringTile|string> $roundScoringTiles Жетоны раундов 1–6 по порядку.
 * @property FinalRoundScoringTile $additionalFinalRoundGoal Дополнительный жетон подсчёта шестого раунда.
 * @property list<BookAction> $bookActions Три общих книжных действия партии.
 * @property list<Competency> $competencies Случайный порядок двенадцати стопок компетенций.
 * @property list<Innovation> $innovations Открытые изобретения на планшете инноваций.
 * @property list<PalaceAbility> $palaces Доступные свойства дворцов, включая дворец №17.
 * @property list<PlanningBundleData> $planningBundles Семь комплектов местности, сообщества и бонуса.
 * @property list<RoundBonusOfferData> $availableRoundBonuses Три оставшихся бонуса с монетами.
 * @property list<TownTile> $townTiles Типы доступных жетонов города.
 * @property int|null $twoPlayerAreaTile Номер нейтрального жетона области для партии вдвоём.
 * @property TwoPlayerTerritoryScore|null $twoPlayerTerritoryScore Размер крупнейшей группы неигровой фракции.
 */
final class GameSetupPoolData extends Data
{
    /**
     * @param list<RoundScoringTile|string> $roundScoringTiles
     * @param list<BookAction> $bookActions
     * @param list<Competency> $competencies
     * @param list<Innovation> $innovations
     * @param list<PalaceAbility> $palaces
     * @param list<PlanningBundleData> $planningBundles
     * @param list<RoundBonusOfferData> $availableRoundBonuses
     * @param list<TownTile> $townTiles
     */
    public function __construct(
        public int $playerCount,
        public MapVariant $mapVariant,
        public int $firstPlayerIndex,
        public array $roundScoringTiles,
        public FinalRoundScoringTile $additionalFinalRoundGoal,
        public array $bookActions,
        public array $competencies,
        public array $innovations,
        public array $palaces,
        public array $planningBundles,
        public array $availableRoundBonuses,
        public array $townTiles,
        public ?int $twoPlayerAreaTile = null,
        public ?TwoPlayerTerritoryScore $twoPlayerTerritoryScore = null,
    ) {
    }
}
