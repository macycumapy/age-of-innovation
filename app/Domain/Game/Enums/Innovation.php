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
    /** Добавить 2 новые Силы в чашу III, поставить нейтральный дворец без свойства; доход — 4 силы. */
    case Palace = 'palace';
    /** Поставить нейтральный монумент и получить 7 ПО. */
    case Monument = 'monument';

    public function description(): string
    {
        return match ($this) {
            self::DeusExMachina => 'Книга, шаг во всех дисциплинах и особое преобразование с 1 лопатой.',
            self::TradeRoutes => 'При пасе получить по 2 ПО за каждую свою гильдию.',
            self::Professor => 'Особое действие: получить учёного и 3 ПО.',
            self::SewageSystem => 'Немедленно получить по 2 ПО за каждую свою мастерскую.',
            self::Architecture => 'Получить шаг за каждую построенную форму здания и 10 ПО.',
            self::Library => 'Получить ПО по сумме уровней двух лучших дисциплин.',
            self::SteamEngine => 'Получить учёного и бесплатные шаги судоходства и преобразования.',
            self::LeagueOfCities => 'Немедленно получить по 5 ПО за каждый жетон города.',
            self::Telecommunication => 'Получить 8/12/18 ПО за 4/5/6 и более областей поселений.',
            self::Steel => 'Получить 8/12/18 ПО за 1/2/3 подходящих моста.',
            self::Census => 'Получить 8/12/18 ПО за 7–8/9–10/11 и более зданий.',
            self::Science => 'Немедленно получить по 5 ПО за каждую свою школу.',
            self::Workshop => 'Поставить нейтральную мастерскую; доход — 3 инструмента.',
            self::Guild => 'Поставить нейтральную гильдию; доход — 5 монет.',
            self::School => 'Поставить нейтральную школу и получить любую компетенцию.',
            self::University => 'Поставить нейтральный университет без компетенции; доход — 2 ПО.',
            self::Palace => 'Добавить 2 новые Силы в чашу III, поставить нейтральный дворец без свойства; доход — 4 силы.',
            self::Monument => 'Поставить нейтральный монумент и получить 7 ПО.',
        };
    }

    public function passVictoryPoints(int $guildCount): int
    {
        return $this === self::TradeRoutes ? $guildCount * 2 : 0;
    }

    public function hasSpecialAction(): bool
    {
        return in_array($this, [self::DeusExMachina, self::Professor], true);
    }

    public function specialActionId(): string
    {
        return 'innovation:'.$this->value;
    }

    public function neutralBuildingType(): ?BuildingType
    {
        return match ($this) {
            self::Workshop => BuildingType::Workshop,
            self::Guild => BuildingType::Guild,
            self::School => BuildingType::School,
            self::University => BuildingType::University,
            self::Palace => BuildingType::Palace,
            self::Monument => BuildingType::Monument,
            default => null,
        };
    }
}
