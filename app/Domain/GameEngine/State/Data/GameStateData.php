<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\State\Data;

use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\BoardStateData;
use App\Domain\GameEngine\Interactions\Data\PendingInteractionData;
use App\Domain\GameEngine\Research\Data\NeutralKnowledgeStateData;
use App\Domain\GameEngine\Setup\Data\GameSetupPoolData;
use App\Domain\GameEngine\Setup\Data\PlayerPlanningSelectionData;
use App\Domain\GameEngine\Turns\Data\RoundStateData;
use Spatie\LaravelData\Data;
use SplObjectStorage;
use UnitEnum;

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
 * @property list<string> $availableCompetencyIds Доступные плашки компетенций, по одному элементу на экземпляр.
 * @property list<string> $roundBonusIds Бонусы раунда, участвующие в партии.
 * @property GameSetupPoolData|null $setupPool Пул компонентов, сформированный при старте партии.
 * @property list<PlayerPlanningSelectionData> $planningSelections Выбранные игроками стартовые комплекты.
 * @property PendingInteractionData|null $pendingInteraction Незавершённое решение игрока, блокирующее продолжение партии.
 * @property list<PendingInteractionData> $pendingInteractionQueue Ожидающие выполнения шаги составного действия.
 * @property NeutralKnowledgeStateData|null $neutralKnowledge Состояние неигровой фракции на шкалах знаний для партии вдвоём.
 * @property int $startingBuildingTurnIndex Индекс текущего хода стартового выставления.
 * @property string|null $pendingStartingBuildingHexId Гекс дома, который ещё можно отменить.
 * @property array<string, mixed>|null $turnStartSnapshot Состояние игры до первого действия текущего хода.
 * @property array<string, mixed>|null $townChoiceCheckpoint Состояние игры перед последним выбором жетона города.
 */
class GameStateData extends Data
{
    private int $lastDeepCopyNanoseconds = 0;

    /**
     * @param list<int> $turnOrder
     * @param list<int> $passedPlayerIds
     * @param list<GamePlayerStateData> $players
     * @param list<string> $availableTownTileIds
     * @param list<string> $availablePalaceIds
     * @param list<string> $availableInventionIds
     * @param list<string> $availableCompetencyIds
     * @param list<string> $roundBonusIds
     * @param list<PlayerPlanningSelectionData> $planningSelections
     * @param list<PendingInteractionData> $pendingInteractionQueue
     * @param array<string, mixed>|null $turnStartSnapshot
     * @param array<string, mixed>|null $townChoiceCheckpoint
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
        public array $planningSelections = [],
        public ?PendingInteractionData $pendingInteraction = null,
        public array $pendingInteractionQueue = [],
        public ?NeutralKnowledgeStateData $neutralKnowledge = null,
        public int $startingBuildingTurnIndex = 0,
        public ?string $pendingStartingBuildingHexId = null,
        public ?array $turnStartSnapshot = null,
        public ?array $townChoiceCheckpoint = null,
    ) {
    }

    public function deepCopy(): self
    {
        $startedAt = hrtime(true);
        $copy = clone $this;
        $copies = new SplObjectStorage();
        $copies[$this] = $copy;

        foreach (get_object_vars($this) as $property => $value) {
            /** Serialized snapshots contain only arrays and scalar values, isolated by PHP copy-on-write. */
            if ($property === 'turnStartSnapshot' || $property === 'townChoiceCheckpoint') {
                continue;
            }
            $copy->{$property} = self::copyValue($value, $copies);
        }
        $copy->lastDeepCopyNanoseconds = hrtime(true) - $startedAt;

        return $copy;
    }

    public function lastDeepCopyNanoseconds(): int
    {
        return $this->lastDeepCopyNanoseconds;
    }

    /** @param SplObjectStorage<object, object> $copies */
    private static function copyValue(mixed $value, SplObjectStorage $copies): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::copyValue($item, $copies);
            }

            return $value;
        }

        if (! is_object($value) || $value instanceof UnitEnum) {
            return $value;
        }

        if (isset($copies[$value])) {
            return $copies[$value];
        }

        if (! $value instanceof Data) {
            $copy = unserialize(serialize($value), ['allowed_classes' => true]);
            $copies[$value] = $copy;

            return $copy;
        }

        $copy = clone $value;
        $copies[$value] = $copy;
        foreach (get_object_vars($value) as $property => $item) {
            /** Lists of scalar map IDs remain isolated through PHP copy-on-write. */
            if (($value instanceof BoardHexStateData && in_array($property, ['adjacentHexIds', 'riverConnectedHexIds'], true))
                || ($value instanceof BoardStateData && in_array($property, ['riverBankHexIds', 'edgeHexIds'], true))) {
                continue;
            }
            $copy->{$property} = self::copyValue($item, $copies);
        }

        return $copy;
    }
}
