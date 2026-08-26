<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\PlayerColor;
use App\Domain\Game\Enums\RoundBonus;
use App\Domain\Game\Enums\TerrainType;
use Spatie\LaravelData\Data;

/**
 * @property int $playerId Идентификатор записи участника партии.
 * @property int $userId Идентификатор пользователя, управляющего участником.
 * @property PlayerColor $color Цвет компонентов игрока.
 * @property Faction $faction Сообщество игрока.
 * @property TerrainType $homeland Родная местность игрока.
 * @property RoundBonus $roundBonus Выбранный стартовый бонус раунда.
 * @property int $victoryPoints Текущее количество победных очков.
 * @property PlayerResourcesData $resources Текущие ресурсы игрока.
 * @property KnowledgeStateData $knowledge Положение игрока на шкалах знаний.
 * @property int $shippingLevel Текущий уровень судоходства.
 * @property int $terraformingLevel Текущий уровень эффективности преобразования.
 * @property int $unassignedSpades Количество полученных лопат, которые ещё нужно потратить.
 * @property list<string> $townTileIds Полученные жетоны городов.
 * @property string|null $palaceId Выбранный дворец или null, если дворец ещё не построен.
 * @property list<string> $competencyIds Полученные компетенции.
 * @property list<string> $inventionIds Созданные изобретения.
 * @property list<string> $usedSpecialActionIds Особые действия, использованные в текущем раунде.
 */
class GamePlayerStateData extends Data
{
    /**
     * @param list<string> $townTileIds
     * @param list<string> $competencyIds
     * @param list<string> $inventionIds
     * @param list<string> $usedSpecialActionIds
     */
    public function __construct(
        public int $playerId,
        public int $userId,
        public PlayerColor $color,
        public Faction $faction,
        public TerrainType $homeland,
        public RoundBonus $roundBonus,
        public int $victoryPoints = 20,
        public PlayerResourcesData $resources = new PlayerResourcesData(),
        public KnowledgeStateData $knowledge = new KnowledgeStateData(),
        public int $shippingLevel = 0,
        public int $terraformingLevel = 0,
        public int $unassignedSpades = 0,
        public array $townTileIds = [],
        public ?string $palaceId = null,
        public array $competencyIds = [],
        public array $inventionIds = [],
        public array $usedSpecialActionIds = [],
    ) {
    }
}
