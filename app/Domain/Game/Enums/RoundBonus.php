<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

enum RoundBonus: string
{
    /** Судоходство +1 и 2 ПО за мастерскую рядом с рекой. */
    case RiverWorkshop = 'river_workshop';
    /** Доход — учёный; по 2 ПО за отправленного учёного. */
    case SendScholar = 'send_scholar';
    /** Доход — 3 силы; по 3 ПО за построенную гильдию. */
    case BuildGuild = 'build_guild';
    /** Доход — инструмент; при пасе по 4 ПО за дворец и университет. */
    case PassPalaceUniversity = 'pass_palace_university';
    /** Доход — выбранная книга; особое преобразование с 1 лопатой. */
    case Spade = 'spade';
    /** Доход — выбранная книга; особое действие строительства моста. */
    case Bridge = 'bridge';
    /** Доход — 2 инструмента; особое действие шага знания. */
    case Knowledge = 'knowledge';
    /** Доход — 4 монеты; при пасе по шагу знания за школу. */
    case PassSchool = 'pass_school';
    /** Доход: 4 силы и 2 монеты. */
    case PowerCoins = 'power_coins';
    /** Доход: 6 монет. */
    case Coins = 'coins';
}
