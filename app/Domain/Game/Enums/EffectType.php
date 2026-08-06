<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

enum EffectType: string
{
    /** Одноразовый эффект, применяемый сразу после получения компонента. */
    case Immediate = 'immediate';
    /** Эффект, начисляемый в фазе дохода. */
    case Income = 'income';
    /** Эффект, применяемый при пасе. */
    case Pass = 'pass';
    /** Личное действие, обычно доступное один раз за раунд. */
    case SpecialAction = 'special_action';
    /** Постоянное изменение правил для владельца. */
    case Permanent = 'permanent';
    /** Начисление очков за события текущего раунда. */
    case RoundScoring = 'round_scoring';
    /** Бонус за уровни дисциплины в конце раунда. */
    case KnowledgeBonus = 'knowledge_bonus';
}
