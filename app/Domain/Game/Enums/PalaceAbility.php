<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

enum PalaceAbility: string
{
    /** Доход 5 силы; особое действие даёт 2 инструмента. */
    case Palace01 = 'palace_01';
    /** Особое преобразование и строительство с 2 бесплатными лопатами. */
    case Palace02 = 'palace_02';
    /** Доход 2 силы; заменить школу гильдией и получить 3 ПО и инструмент. */
    case Palace03 = 'palace_03';
    /** Доход 2 силы; бесплатно улучшить мастерскую до гильдии. */
    case Palace04 = 'palace_04';
    /** Доход 4 силы; при строительстве бесплатно получить компетенцию. */
    case Palace05 = 'palace_05';
    /** Доход 2 силы и книга; особое действие даёт 2 шага знания. */
    case Palace06 = 'palace_06';
    /** Доход 4 силы; при пасе получить по 3 ПО за школу. */
    case Palace07 = 'palace_07';
    /** Доход 2 силы, 2 монеты и инструмент; городу достаточно силы 6. */
    case Palace08 = 'palace_08';
    /** Доход учёный; полёт на расстояние до 3 клеток за учёного приносит 5 ПО. */
    case Palace09 = 'palace_09';
    /** Доход 6 монет; при строительстве получить 12 силы и 2 книги. */
    case Palace10 = 'palace_10';
    /** Доход инструмент; при строительстве бесплатно получить жетон города. */
    case Palace11 = 'palace_11';
    /** Доход 8 силы; получать 2 ПО за построенную мастерскую. */
    case Palace12 = 'palace_12';
    /** Особое действие даёт 3 монеты и книгу; гильдия приносит 3 ПО. */
    case Palace13 = 'palace_13';
    /** Доход 6 силы; до 2 шагов судоходства и города через одно речное поле. */
    case Palace14 = 'palace_14';
    /** Доход 6 силы; при строительстве получить 2 лопаты, 2 моста и 2 книги. */
    case Palace15 = 'palace_15';
    /** Доход 2 силы и книга; бесплатно поставить гильдию на родной местности. */
    case Palace16 = 'palace_16';
    /** Доход 2 силы; при строительстве получить 10 ПО. */
    case Palace17 = 'palace_17';

    public function description(): string
    {
        return match ($this) {
            self::Palace01 => 'Доход: 5 силы. Особое действие даёт 2 инструмента.',
            self::Palace02 => 'Особое действие: преобразование и строительство с 2 бесплатными лопатами.',
            self::Palace03 => 'Доход: 2 силы. Заменить школу гильдией и получить 3 ПО и инструмент.',
            self::Palace04 => 'Доход: 2 силы. Бесплатно улучшить мастерскую до гильдии.',
            self::Palace05 => 'Доход: 4 силы. При строительстве бесплатно получить компетенцию.',
            self::Palace06 => 'Доход: 2 силы и книга. Особое действие даёт 2 шага знания.',
            self::Palace07 => 'Доход: 4 силы. При пасе получить по 3 ПО за каждую школу.',
            self::Palace08 => 'Доход: 2 силы, 2 монеты и инструмент. Для города достаточно силы 6.',
            self::Palace09 => 'Доход: учёный. Полёт на расстояние до 3 клеток за учёного приносит 5 ПО.',
            self::Palace10 => 'Доход: 6 монет. При строительстве получить 12 силы и 2 книги.',
            self::Palace11 => 'Доход: инструмент. При строительстве бесплатно получить жетон города.',
            self::Palace12 => 'Доход: 8 силы. Получать 2 ПО за построенную мастерскую.',
            self::Palace13 => 'Особое действие даёт 3 монеты и книгу. Гильдия приносит 3 ПО.',
            self::Palace14 => 'Доход: 6 силы. До 2 шагов судоходства и города через одно речное поле.',
            self::Palace15 => 'Доход: 6 силы. При строительстве получить 2 лопаты, 2 моста и 2 книги.',
            self::Palace16 => 'Доход: 2 силы и книга. Бесплатно поставить рынок на родной местности.',
            self::Palace17 => 'Доход: 2 силы. При строительстве получить 10 ПО.',
        };
    }

    public function buildingVictoryPoints(BuildingType $buildingType): int
    {
        return match ($this) {
            self::Palace12 => $buildingType === BuildingType::Workshop ? 2 : 0,
            self::Palace13 => $buildingType === BuildingType::Guild ? 3 : 0,
            self::Palace17 => $buildingType === BuildingType::Palace ? 10 : 0,
            default => 0,
        };
    }

    public function hasSpecialAction(): bool
    {
        return in_array($this, [
            self::Palace01, self::Palace02, self::Palace03,
            self::Palace04, self::Palace06, self::Palace13,
        ], true);
    }

    public function specialActionId(): string
    {
        return "palace:{$this->value}";
    }

    public function passVictoryPoints(int $schoolCount): int
    {
        return $this === self::Palace07 ? $schoolCount * 3 : 0;
    }
}
