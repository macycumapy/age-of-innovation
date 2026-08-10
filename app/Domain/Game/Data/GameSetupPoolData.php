<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use App\Domain\Game\Enums\BookAction;
use App\Domain\Game\Enums\Competency;
use App\Domain\Game\Enums\FinalRoundScoringTile;
use App\Domain\Game\Enums\Innovation;
use App\Domain\Game\Enums\MapVariant;
use App\Domain\Game\Enums\PalaceAbility;
use App\Domain\Game\Enums\RoundScoringTile;
use App\Domain\Game\Enums\TownTile;
use Spatie\LaravelData\Data;

/**
 * @property int $playerCount Число участников партии.
 * @property MapVariant $mapVariant Сторона карты для партии.
 * @property int $firstPlayerIndex Индекс случайно выбранного первого игрока.
 * @property list<RoundScoringTile> $roundScoringTiles Жетоны раундов 1–6 по порядку.
 * @property FinalRoundScoringTile $additionalFinalRoundGoal Дополнительный жетон подсчёта шестого раунда.
 * @property list<BookAction> $bookActions Три общих книжных действия партии.
 * @property list<Competency> $competencies Случайный порядок двенадцати стопок компетенций.
 * @property list<Innovation> $innovations Открытые изобретения на планшете инноваций.
 * @property list<PalaceAbility> $palaces Доступные свойства дворцов, включая дворец №17.
 * @property list<PlanningBundleData> $planningBundles Семь комплектов местности, сообщества и бонуса.
 * @property list<RoundBonusOfferData> $availableRoundBonuses Три оставшихся бонуса с монетами.
 * @property list<TownTile> $townTiles Типы доступных жетонов города.
 * @property int|null $twoPlayerAreaTile Номер нейтрального жетона области для партии вдвоём.
 */
final class GameSetupPoolData extends Data
{
    /**
     * @param list<RoundScoringTile> $roundScoringTiles
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
    ) {
    }
}
