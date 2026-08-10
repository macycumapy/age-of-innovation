<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

enum RoundScoringGoal: string
{
    /** Построить мастерскую. */
    case Workshop = 'workshop';
    /** Построить гильдию. */
    case Guild = 'guild';
    /** Построить школу. */
    case School = 'school';
    /** Построить дворец или университет. */
    case PalaceOrUniversity = 'palace_or_university';
    /** Использовать лопату. */
    case Spade = 'spade';
    /** Продвинуться в любой дисциплине знаний. */
    case Knowledge = 'knowledge';
    /** Получить жетон города. */
    case Town = 'town';
    /** Продвинуть судоходство или преобразование. */
    case ShippingOrTerraforming = 'shipping_or_terraforming';
    /** Создать изобретение. */
    case Innovation = 'innovation';
}
