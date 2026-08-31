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
}
