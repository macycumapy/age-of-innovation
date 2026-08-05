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
 */
class RoundStateData extends Data
{
    /** @param list<string> $usedSharedActionIds */
    public function __construct(
        public int $number = 1,
        public GamePhase $phase = GamePhase::Setup,
        public ?string $scoringTileId = null,
        public ?string $additionalScoringTileId = null,
        public array $usedSharedActionIds = [],
    ) {
    }
}
