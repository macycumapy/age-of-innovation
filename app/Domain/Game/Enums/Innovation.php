<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

enum Innovation: string
{
    /** Книга, шаг во всех дисциплинах и особое преобразование с 1 лопатой. */
    case DeusExMachina = 'deus_ex_machina';
    /** При пасе получить по 2 ПО за каждую свою гильдию. */
    case TradeRoutes = 'trade_routes';
    /** Особое действие: получить учёного и 3 ПО. */
    case Professor = 'professor';
    /** Немедленно получить по 2 ПО за каждую свою мастерскую. */
    case SewageSystem = 'sewage_system';
    /** Получить шаг за каждую построенную форму здания и 10 ПО. */
    case Architecture = 'architecture';
    /** Получить ПО по сумме уровней двух лучших дисциплин. */
    case Library = 'library';
    /** Получить учёного и бесплатные шаги судоходства и преобразования. */
    case SteamEngine = 'steam_engine';
    /** Немедленно получить по 5 ПО за каждый жетон города. */
    case LeagueOfCities = 'league_of_cities';
    /** Получить 8/12/18 ПО за 4/5/6 и более областей поселений. */
    case Telecommunication = 'telecommunication';
    /** Получить 8/12/18 ПО за 1/2/3 подходящих моста. */
    case Steel = 'steel';
    /** Получить 8/12/18 ПО за 7–8/9–10/11 и более зданий. */
    case Census = 'census';
    /** Немедленно получить по 5 ПО за каждую свою школу. */
    case Science = 'science';
    /** Поставить нейтральную мастерскую; доход — 3 инструмента. */
    case Workshop = 'workshop';
    /** Поставить нейтральную гильдию; доход — 5 монет. */
    case Guild = 'guild';
    /** Поставить нейтральную школу и получить любую компетенцию. */
    case School = 'school';
    /** Поставить нейтральный университет без компетенции; доход — 2 ПО. */
    case University = 'university';
    /** Поставить нейтральный дворец без свойства; доход — 4 силы. */
    case Palace = 'palace';
    /** Поставить монумент силы 4 и получить 7 ПО. */
    case Monument = 'monument';
}
