<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

enum KnowledgeDiscipline: string
{
    /** Банковское дело, жёлтая дисциплина знаний. */
    case Banking = 'banking';
    /** Право, синяя дисциплина знаний. */
    case Law = 'law';
    /** Инженерное дело, коричневая дисциплина знаний. */
    case Engineering = 'engineering';
    /** Медицина, белая дисциплина знаний. */
    case Medicine = 'medicine';

    public function displayName(): string
    {
        return match ($this) {
            self::Banking => 'Банковское дело',
            self::Law => 'Право',
            self::Engineering => 'Инженерное дело',
            self::Medicine => 'Медицина',
        };
    }

    /** @return array{coins: int, power: int, tools: int, victoryPoints: int} */
    public function highLevelIncome(): array
    {
        $income = ['coins' => 0, 'power' => 0, 'tools' => 0, 'victoryPoints' => 0];

        match ($this) {
            self::Banking => $income['coins'] = 3,
            self::Law => $income['power'] = 6,
            self::Engineering => $income['tools'] = 1,
            self::Medicine => $income['victoryPoints'] = 3,
        };

        return $income;
    }
}
