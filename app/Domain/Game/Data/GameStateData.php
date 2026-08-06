<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use Spatie\LaravelData\Data;

/**
 * @property int $schemaVersion Версия структуры снимка для миграции старых сохранений.
 * @property list<int> $turnOrder Идентификаторы участников в текущем порядке хода.
 * @property list<int> $passedPlayerIds Идентификаторы участников, спасовавших в текущем раунде.
 * @property BoardStateData $board Состояние игровой карты.
 * @property list<GamePlayerStateData> $players Полное игровое состояние участников.
 * @property RoundStateData $round Состояние текущего раунда.
 * @property list<string> $availableTownTileIds Доступные жетоны городов.
 * @property list<string> $availablePalaceIds Доступные дворцы.
 * @property list<string> $availableInventionIds Доступные изобретения.
 * @property list<string> $availableCompetencyIds Доступные компетенции.
 * @property list<string> $roundBonusIds Бонусы раунда, участвующие в партии.
 * @property GameSetupPoolData|null $setupPool Пул компонентов, сформированный при старте партии.
 * @property PendingInteractionData|null $pendingInteraction Незавершённое решение игрока, блокирующее продолжение партии.
 */
class GameStateData extends Data
{
    /**
     * @param list<int> $turnOrder
     * @param list<int> $passedPlayerIds
     * @param list<GamePlayerStateData> $players
     * @param list<string> $availableTownTileIds
     * @param list<string> $availablePalaceIds
     * @param list<string> $availableInventionIds
     * @param list<string> $availableCompetencyIds
     * @param list<string> $roundBonusIds
     */
    public function __construct(
        public int $schemaVersion = 1,
        public array $turnOrder = [],
        public array $passedPlayerIds = [],
        public BoardStateData $board = new BoardStateData(),
        public array $players = [],
        public RoundStateData $round = new RoundStateData(),
        public array $availableTownTileIds = [],
        public array $availablePalaceIds = [],
        public array $availableInventionIds = [],
        public array $availableCompetencyIds = [],
        public array $roundBonusIds = [],
        public ?GameSetupPoolData $setupPool = null,
        public ?PendingInteractionData $pendingInteraction = null,
    ) {
    }
}
