<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use App\Domain\Game\Enums\GamePhase;
use Spatie\LaravelData\Data;

/**
 * @property int $number Номер текущего раунда от 1 до 6.
 * @property GamePhase $phase Текущая фаза раунда.
 * @property string|null $scoringTileId Жетон подсчёта текущего раунда.
 * @property string|null $additionalScoringTileId Дополнительный жетон подсчёта шестого раунда.
 * @property list<string> $usedSharedActionIds Общие действия, уже использованные в текущем раунде.
 * @property list<string> $usedBookActionIds Книжные действия, использованные в текущем раунде.
 * @property int $incomeTurnIndex Индекс следующего игрока в порядке начисления дохода.
 * @property int|null $turnStartVersion Версия состояния в начале текущего хода.
 * @property bool $hasTakenMainAction Выполнил ли активный игрок основное действие текущего хода.
 * @property bool $isCurrentTurnIrrevocable Нельзя ли перезапустить текущий ход из-за принятой другим игроком Силы.
 * @property list<int> $passOrder Порядок, в котором игроки пасовали.
 */
class RoundStateData extends Data
{
    /**
     * @param list<string> $usedSharedActionIds
     * @param list<string> $usedBookActionIds
     * @param list<int> $passOrder
     */
    public function __construct(
        public int $number = 1,
        public GamePhase $phase = GamePhase::Setup,
        public ?string $scoringTileId = null,
        public ?string $additionalScoringTileId = null,
        public array $usedSharedActionIds = [],
        public array $usedBookActionIds = [],
        public int $incomeTurnIndex = 0,
        public ?int $turnStartVersion = null,
        public bool $hasTakenMainAction = false,
        public bool $isCurrentTurnIrrevocable = false,
        public array $passOrder = [],
    ) {
    }
}
